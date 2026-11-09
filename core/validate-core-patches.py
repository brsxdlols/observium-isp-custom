#!/usr/bin/env python3
"""Offline, read-only validation of PRIVATE Observium ISP patch bundle.

Accepts ZIP produced by export of the review directory. Checks exact baseline
SHA-256 before attempting a dry-run patch in an isolated temporary directory.
It deliberately does NOT modify the Observium tree or publish source/patches.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

TARGETS = {
    'html/includes/navbar.inc.php',
    'html/ajax/widget.php',
    'html/js/dashboard.js',
}
PATCH_NAMES = {p: p.replace('/', '__') + '.patch' for p in TARGETS}
MAX_BYTES = 2_000_000

def digest(data):
    return hashlib.sha256(data).hexdigest()

def fail(msg):
    raise ValueError(msg)

def check_patch_text(text, rel):
    # Harden patch input: one unified diff for one explicitly permitted file.
    # Reject extra file headers or Git patch metadata, which might introduce
    # arbitrary other paths even when the first pair is correct.
    lines = text.splitlines()
    header_a, header_b = '--- a/' + rel, '+++ b/' + rel
    negatives = [line for line in lines if line.startswith('--- ')]
    positives = [line for line in lines if line.startswith('+++ ')]
    if negatives != [header_a] or positives != [header_b]:
        fail('cabeçalho de patch inesperado: ' + rel)
    if any(line.startswith(('diff --git ', 'Index: ', 'GIT binary patch', 'Binary files ', '*** ')) for line in lines):
        fail('metadados de patch inesperados: ' + rel)
    if not any(re.match(r'^@@ -\d+(?:,\d+)? \+\d+(?:,\d+)? @@', line) for line in lines):
        fail('patch sem blocos válidos: ' + rel)
    if '\x00' in text:
        fail('patch binário rejeitado: ' + rel)

def load_bundle(bundle):
    with zipfile.ZipFile(bundle) as z:
        items = z.infolist()
        names = [x.filename for x in items]
        if len(names) != len(set(names)):
            fail('nomes duplicados no ZIP')
        allowed = {'manifest.json'} | set(PATCH_NAMES.values())
        if set(names) != allowed:
            fail('ZIP não contém exatamente manifesto e 3 patches')
        for item in items:
            if item.is_dir() or item.file_size > MAX_BYTES or (item.external_attr >> 16) & 0o170000 == 0o120000:
                fail('entrada ZIP inválida: ' + item.filename)
        manifest = json.loads(z.read('manifest.json'))
        patch_data = {rel: z.read(name) for rel, name in PATCH_NAMES.items()}
    if manifest.get('format') != 'observium-isp-core-review-v1':
        fail('formato desconhecido de manifesto')
    listed = manifest.get('files')
    if not isinstance(listed, list) or len(listed) != 5:
        fail('manifesto incompleto')
    by_path = {x.get('path'): x for x in listed if isinstance(x, dict)}
    if len(by_path) != 5:
        fail('caminhos duplicados ou inválidos no manifesto')
    for rel in TARGETS:
        row = by_path.get(rel)
        if not row or row.get('changed') is not True:
            fail('arquivo alterado não declarado no manifesto: ' + rel)
        for field in ('baseline_sha256', 'customized_sha256'):
            if not isinstance(row.get(field), str) or not re.fullmatch('[0-9a-f]{64}', row[field]):
                fail('hash inválido para ' + rel)
        try:
            text = patch_data[rel].decode('utf-8')
        except UnicodeDecodeError:
            fail('patch não UTF-8: ' + rel)
        check_patch_text(text, rel)
    return by_path, patch_data

def validate(bundle, root):
    rows, patches = load_bundle(bundle)
    if not root.is_dir():
        fail('diretório Observium não encontrado')
    patch_exe = '/usr/bin/patch'
    if not Path(patch_exe).is_file():
        fail('GNU patch não encontrado em /usr/bin/patch')
    for rel in sorted(TARGETS):
        target = root / rel
        if not target.is_file() or target.is_symlink():
            fail('arquivo de destino não regular: ' + rel)
        original = target.read_bytes()
        before = digest(original)
        expected_before = rows[rel]['baseline_sha256']
        expected_after = rows[rel]['customized_sha256']
        if before == expected_after:
            print('JÁ PERSONALIZADO:', rel, '(SHA-256 confere)')
            continue
        if before != expected_before:
            fail('HASH DIVERGENTE, aplicação proibida: ' + rel + ' atual=' + before + ' esperado=' + expected_before)
        with tempfile.TemporaryDirectory(prefix='obs-isp-patch-check-') as d:
            t = Path(d)
            file = t / rel
            file.parent.mkdir(parents=True)
            file.write_bytes(original)
            patch_path = t / 'review.patch'
            patch_path.write_bytes(patches[rel])
            proc = subprocess.run([patch_exe, '--batch', '--forward', '--fuzz=0', '--no-backup-if-mismatch', '-p1', '-i', str(patch_path)], cwd=t, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
            if proc.returncode != 0:
                fail('patch rejeitado em cópia temporária: ' + rel + ' (' + proc.stdout[-350:] + ' ' + proc.stderr[-350:] + ')')
            if digest(file.read_bytes()) != expected_after:
                fail('SHA final não corresponde à versão homologada: ' + rel)
        print('PRONTO PARA REVISÃO:', rel, '(patch + SHA final conferidos em cópia temporária)')
    print('CHECK CONCLUÍDO: nenhum arquivo do Observium foi alterado.')
    print('ATENÇÃO: compatibilidade de hashes não substitui revisão do diff, licença e testes funcionais.')

def main():
    p = argparse.ArgumentParser()
    p.add_argument('--bundle', required=True, type=Path)
    p.add_argument('--root', default='/opt/observium', type=Path)
    args = p.parse_args()
    validate(args.bundle, args.root)

if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, zipfile.BadZipFile, subprocess.TimeoutExpired, json.JSONDecodeError) as e:
        print('ERRO:', e, file=sys.stderr)
        sys.exit(1)