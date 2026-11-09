#!/usr/bin/env python3
"""Rehearse and roll back PRIVATE Observium core patches in an isolated temporary tree.

Does not change the installed Observium files. Does not publish patch contents.
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

TARGETS = (
    'html/includes/navbar.inc.php',
    'html/ajax/widget.php',
    'html/js/dashboard.js',
)
MAX_FILE = 2_000_000
VERSION_REL = 'includes/definitions/version.inc.php'
VERSION_PATTERN = rb"define\s*\(\s*['\"]OBSERVIUM_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]"


def sha(blob):
    return hashlib.sha256(blob).hexdigest()


def regular(tar, name, limit):
    matches = [m for m in tar if m.name == name]
    if len(matches) != 1 or not matches[0].isfile() or matches[0].size > limit:
        raise ValueError('Entrada ausente, duplicada, link ou grande no pacote original: ' + name)
    f = tar.extractfile(matches[0])
    if f is None:
        raise ValueError('Impossivel ler ' + name)
    blob = f.read(limit + 1)
    if len(blob) > limit:
        raise ValueError('Arquivo excede limite: ' + name)
    return blob


def version(blob):
    m = re.search(VERSION_PATTERN, blob)
    if not m:
        raise ValueError('OBSERVIUM_VERSION ausente')
    return m.group(1).decode('ascii')


def get_validator(script):
    spec = importlib.util.spec_from_file_location('core_validator', script)
    if spec is None or spec.loader is None:
        raise ValueError('Validador indisponivel')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def rehearse(baseline, bundle, installed_version, validator_script):
    check = get_validator(validator_script)
    rows, diffs = check.load_bundle(bundle)
    if set(rows.keys()) != set(TARGETS) | {
        'html/includes/dashboard-generate.inc.php',
        'html/pages/dashboard.inc.php',
    }:
        raise ValueError('Manifesto inesperado')
    if not shutil.which('patch'):
        raise ValueError('GNU patch ausente')

    with tarfile.open(baseline, 'r:gz') as tar:
        pristine_version = version(regular(tar, 'observium/' + VERSION_REL, 65536))
        if pristine_version != installed_version:
            raise ValueError('Versao do pacote original divergente: ' + pristine_version +
                             ' vs ' + installed_version)
        originals = {}
        for rel in TARGETS:
            original = regular(tar, 'observium/' + rel, MAX_FILE)
            if sha(original) != rows[rel]['baseline_sha256']:
                raise ValueError('SHA original divergente: ' + rel)
            originals[rel] = original

    # All mutations occur in a temporary directory created outside /opt/observium.
    with tempfile.TemporaryDirectory(prefix='obs-isp-core-rehearsal-') as temp:
        root = Path(temp)
        backups = {}
        for rel, original in originals.items():
            target = root / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(original)
            backups[rel] = original

        print('ETAPA 1: arquivos originais em diretorio temporario; SHA validado')
        for rel in TARGETS:
            patchfile = root / (rel.replace('/', '__') + '.patch')
            patchfile.write_bytes(diffs[rel])
            proc = subprocess.run(
                ['patch', '--batch', '--forward', '--fuzz=0', '-p1', '-i', str(patchfile)],
                cwd=root, capture_output=True, text=True, timeout=30,
            )
            if proc.returncode:
                raise ValueError('Falha na simulacao do patch ' + rel +
                                 ': ' + (proc.stdout + proc.stderr)[-500:])
            target = root / rel
            if sha(target.read_bytes()) != rows[rel]['customized_sha256']:
                raise ValueError('SHA final inesperado: ' + rel)
            print('APLICACAO SIMULADA OK:', rel)

        print('ETAPA 2: restaurando backups no ambiente temporario')
        for rel, original in backups.items():
            target = root / rel
            target.write_bytes(original)
            if sha(target.read_bytes()) != rows[rel]['baseline_sha256']:
                raise ValueError('ROLLBACK SIMULADO FALHOU: ' + rel)
            print('ROLLBACK SIMULADO OK:', rel)

    print('RESULTADO: tres patches aplicados e revertidos em copias temporarias.')
    print('NENHUMA ALTERACAO no Observium instalado. Patches ainda nao homologados para producao.')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--baseline', type=Path, required=True)
    parser.add_argument('--bundle', type=Path, required=True)
    parser.add_argument('--installed-version', required=True)
    parser.add_argument('--validator', type=Path, required=True)
    args = parser.parse_args()
    for p in (args.baseline, args.bundle, args.validator):
        if not p.is_file():
            parser.error('Arquivo nao encontrado: ' + str(p))
    rehearse(args.baseline, args.bundle, args.installed_version, args.validator)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, tarfile.TarError, subprocess.TimeoutExpired) as exc:
        print('ERRO:', exc, file=sys.stderr)
        sys.exit(1)
