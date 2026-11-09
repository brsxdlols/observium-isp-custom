#!/usr/bin/env python3
"""Offline transactional PATCH rehearsal in a disposable sandbox; NEVER writes live Observium.

This is a rehearsal of atomic deployment and backup-based rollback mechanics.
The target MUST be created below a fresh TemporaryDirectory by this process.
"""
import argparse
import hashlib
import importlib.util
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile

TARGETS = ('html/includes/navbar.inc.php', 'html/ajax/widget.php', 'html/js/dashboard.js')
VERSION_REL = 'observium/includes/definitions/version.inc.php'
MAX_SIZE = 2_000_000
VERSION_RX = rb"define\s*\(\s*['\"]OBSERVIUM_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]"


def sha(data):
    return hashlib.sha256(data).hexdigest()


def version(data):
    m = re.search(VERSION_RX, data)
    if not m:
        raise ValueError('Nao foi possivel identificar a versao')
    return m.group(1).decode('ascii')


def from_archive(archive, names):
    values = {}
    with tarfile.open(archive, 'r:gz') as tar:
        required = {'observium/' + p for p in names}
        for member in tar:
            if member.name not in required:
                continue
            if member.name in values or not member.isfile() or member.size > MAX_SIZE:
                raise ValueError('Arquivo original inseguro/duplicado: ' + member.name)
            with tar.extractfile(member) as reader:
                values[member.name] = reader.read(MAX_SIZE + 1)
        if required != values.keys():
            raise ValueError('Pacote original nao possui arquivos necessarios: ' + str(required - values.keys()))
    return values


def load_validator(filename):
    spec = importlib.util.spec_from_file_location('obs_core_private_validator', filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def stage(baseline, bundle, installed_version, validator, inject_failure_at=0):
    """Run real write/revert operations only inside a process-owned temp directory."""
    if not shutil.which('patch'):
        raise ValueError('Comando patch ausente')
    v = load_validator(validator)
    rows, diffs = v.load_bundle(bundle)
    pristine = from_archive(baseline, (*TARGETS, VERSION_REL[len('observium/'):]))
    remote_version = version(pristine[VERSION_REL])
    if remote_version != installed_version:
        raise ValueError('Versao divergente: ' + remote_version + ' != ' + installed_version)
    for rel in TARGETS:
        before = sha(pristine['observium/' + rel])
        if before != rows[rel]['baseline_sha256']:
            raise ValueError('Pre-imagem divergente ' + rel)

    with tempfile.TemporaryDirectory(prefix='obs-isp-transaction-') as dirname:
        root = Path(dirname)
        live = root / 'disposable-tree'
        backup = root / 'private-backup'
        patched = root / 'preflight'
        for folder in (live, backup, patched):
            folder.mkdir(mode=0o700)
        for rel in TARGETS:
            data = pristine['observium/' + rel]
            for folder in (live, backup, patched):
                p = folder / rel
                p.parent.mkdir(parents=True, exist_ok=True)
                p.write_bytes(data)
            patchfile = root / (rel.replace('/', '_') + '.patch')
            patchfile.write_bytes(diffs[rel])
            completed = subprocess.run(['patch', '--batch', '--forward', '--fuzz=0', '--no-backup-if-mismatch', '-p1', '-i', str(patchfile)], cwd=patched, capture_output=True, text=True, timeout=30)
            if completed.returncode:
                raise ValueError('Preflight falhou ' + rel + ': ' + (completed.stdout + completed.stderr)[-400:])
            if sha((patched / rel).read_bytes()) != rows[rel]['customized_sha256']:
                raise ValueError('Hash final incorreto no preflight: ' + rel)
        print('PREFLIGHT OK: tres patches + hashes na copia isolada')

        installed = []
        try:
            for index, rel in enumerate(TARGETS, 1):
                dest = live / rel
                if sha(dest.read_bytes()) != rows[rel]['baseline_sha256']:
                    raise ValueError('Destino mudou durante transacao: ' + rel)
                # Atomically replace only the disposable-tree file; backup remains intact.
                temp_dest = dest.with_name(dest.name + '.staged')
                shutil.copyfile(patched / rel, temp_dest)
                os.replace(temp_dest, dest)
                installed.append(rel)
                if sha(dest.read_bytes()) != rows[rel]['customized_sha256']:
                    raise ValueError('Hash apos instalacao divergente: ' + rel)
                print('TRANSACAO SIMULADA OK:', rel)
                if index == inject_failure_at:
                    raise ValueError('FALHA INJETADA para testar rollback')
        except Exception:
            for rel in reversed(installed):
                dest = live / rel
                original = backup / rel
                tmp_restore = dest.with_name(dest.name + '.restoring')
                shutil.copyfile(original, tmp_restore)
                os.replace(tmp_restore, dest)
                if sha(dest.read_bytes()) != rows[rel]['baseline_sha256']:
                    raise RuntimeError('ROLLBACK FALHOU: ' + rel)
                print('ROLLBACK POR BACKUP OK:', rel)
            for rel in TARGETS:
                if sha((live / rel).read_bytes()) != rows[rel]['baseline_sha256']:
                    raise RuntimeError('Rollback incompleto: ' + rel)
            print('ROLLBACK AUTOMATICO TESTADO: copia restaurada integralmente')
            if not inject_failure_at:
                raise
            return 'rollback'
        for rel in TARGETS:
            if sha((live / rel).read_bytes()) != rows[rel]['customized_sha256']:
                raise RuntimeError('Transacao incompleta: ' + rel)
        print('COMMIT SIMULADO OK: tres arquivos atualizados no ambiente descartavel')
        return 'commit'


def main():
    p = argparse.ArgumentParser(description='Simular transacao e rollback em diretorio descartavel')
    p.add_argument('--baseline', type=Path, required=True)
    p.add_argument('--bundle', type=Path, required=True)
    p.add_argument('--validator', type=Path, required=True)
    p.add_argument('--installed-version', required=True)
    args = p.parse_args()
    for pth in (args.baseline, args.bundle, args.validator):
        if not pth.is_file():
            raise ValueError('Arquivo ausente: ' + str(pth))
    a = stage(args.baseline, args.bundle, args.installed_version, args.validator)
    b = stage(args.baseline, args.bundle, args.installed_version, args.validator, inject_failure_at=2)
    if (a, b) != ('commit', 'rollback'):
        raise RuntimeError('Ensaio transacional incompleto')
    print('SUCESSO: commit simulado e rollback apos falha injetada')
    print('NENHUM arquivo do Observium em producao foi modificado')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, tarfile.TarError, subprocess.TimeoutExpired, RuntimeError) as exc:
        print('ERRO:', exc, file=sys.stderr)
        sys.exit(1)