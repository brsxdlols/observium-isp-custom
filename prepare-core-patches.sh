#!/usr/bin/env bash
# OBS_ISP_CORE_PREPARE_V1: create local review-only patches from an official pristine CE tarball
set -Eeuo pipefail
umask 077
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'ERRO: execute como root'; exit 1; }
OBS=${OBSERVIUM_DIR:-/opt/observium}
RAW=${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}
[[ -r "$OBS/config.php" ]] || { echo 'ERRO: Observium atual nao localizado'; exit 1; }
for cmd in python3 curl tar sha256sum; do command -v "$cmd" >/dev/null || { echo "ERRO: falta $cmd"; exit 1; }; done
EVIDENCE=${ISP_CORE_EVIDENCE:-}
if [[ -z "$EVIDENCE" ]]; then
  shopt -s nullglob
  files=(/root/observium-isp-exports/observium-core-evidence-*.tar.gz)
  ((${#files[@]})) || { echo 'ERRO: execute --core-export antes, ou forneca ISP_CORE_EVIDENCE'; exit 2; }
  EVIDENCE=$(ls -t -- "${files[@]}" | head -1)
fi
[[ -f "$EVIDENCE" ]] || { echo "ERRO: evidencia ausente $EVIDENCE"; exit 2; }
BASELINE=${ISP_CORE_BASELINE:-/opt/observium-community-latest.tar.gz}
WORK=$(mktemp -d /root/observium-core-prepare.XXXXXX)
trap 'rm -rf -- "$WORK"' EXIT
if [[ ! -r "$BASELINE" ]]; then
  echo 'Arquivo original nao encontrado: baixando release Community oficial para area temporaria.'
  BASELINE="$WORK/observium-community-latest.tar.gz"
  curl --fail --location --retry 3 --connect-timeout 15 --max-time 600 \
    https://www.observium.org/observium-community-latest.tar.gz -o "$BASELINE"
fi
# Safe read-only checks; never extract the archive into /opt/observium.
tar -tzf "$BASELINE" >/dev/null
TMP_PY="$WORK/build-core-patches.py"
# Pinned core builder: the immutable commit URL avoids stale CDN responses
# from /main/ and prevents accidentally running a mismatched generator.
BUILDER_REV="23a744b7e95ef1e456f95967bfb5bab0e2ff4c65"
BUILDER_URL="https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/$BUILDER_REV/core/build-core-patches.py"
curl --fail --location --retry 2 --connect-timeout 15 --max-time 60 \
  "$BUILDER_URL" -o "$TMP_PY"
python3 - "$TMP_PY" <<'PY'
from pathlib import Path
import sys
source = Path(sys.argv[1]).read_text(encoding='utf-8')
if 'Required source is not a regular file' not in source or 'Archive contains unsafe path/link' in source:
    raise SystemExit('ERRO: gerador desatualizado ou incorreto; abortando antes da comparacao')
compile(source, sys.argv[1], 'exec')
print('OK: gerador de patches corrigido (symlink seguro; revisao fixada)')
PY
OUT="/root/observium-isp-exports/core-patch-review-$(date -u +%Y%m%dT%H%M%SZ)-$$"
python3 "$TMP_PY" --baseline "$BASELINE" --evidence "$EVIDENCE" --out "$OUT"
cat <<'NOTE'

IMPORTANTE: os patches gerados sao RASCUNHOS PRIVADOS, nao patches homologados.
O pacote 'latest' pode ser diferente da revisao que originou seu servidor.
Nao aplique nem publique os .patch sem conferir a revisao-base exata e remover
identificadores internos/credenciais porventura presentes nos diffs.
Nenhum arquivo de producao foi alterado.
NOTE