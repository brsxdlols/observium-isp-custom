#!/usr/bin/env bash
# OBS_ISP_CORE_TX_TEST_V1 - transaction simulation only; DOES NOT WRITE TO PRODUCTION
set -Eeuo pipefail
umask 077
[[ $(id -u) -eq 0 ]] || { echo 'ERRO: execute como root.' >&2; exit 1; }

OBS="${OBSERVIUM_DIR:-/opt/observium}"
ZIP="${ISP_CORE_PATCH_ZIP:-/root/observium-isp-patches-revisao.zip}"
BASELINE="${ISP_CORE_BASELINE:-/opt/observium-community-latest.tar.gz}"
REV="432ee2ad9efa4b18ef3dcc71e014d2ee203c226b"
RAW="https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/$REV"

[[ -r "$OBS/includes/definitions/version.inc.php" ]] || { echo 'ERRO: Observium ausente.' >&2; exit 2; }
[[ -r "$ZIP" ]] || { echo "ERRO: ZIP privado nao encontrado: $ZIP" >&2; exit 2; }
for cmd in python3 curl patch; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "ERRO: falta $cmd" >&2; exit 2; }
done

WORK="$(mktemp -d /root/observium-isp-tx.XXXXXX)"
trap 'rm -rf -- "$WORK"' EXIT

if [[ ! -r "$BASELINE" ]]; then
  BASELINE="$WORK/observium-community-latest.tar.gz"
  echo 'Baixando pacote oficial para o diretorio temporario (sem extrair no Observium).'
  curl --fail --location --retry 3 --connect-timeout 15 --max-time 600 \
    https://www.observium.org/observium-community-latest.tar.gz -o "$BASELINE"
fi

curl --fail --silent --show-error --location --retry 2 \
  "$RAW/core/test-core-transaction.py" -o "$WORK/transaction.py"
curl --fail --silent --show-error --location --retry 2 \
  "$RAW/core/validate-core-patches.py" -o "$WORK/validator.py"
python3 - "$WORK/transaction.py" "$WORK/validator.py" <<'PY'
from pathlib import Path
import sys
for f,marker in zip(sys.argv[1:],('def stage(', 'def load_bundle(')):
    script=Path(f).read_text(encoding='utf-8')
    if marker not in script: raise SystemExit('ERRO: script incompativel: '+f)
    compile(script,f,'exec')
PY
VERSION="$(python3 - "$OBS/includes/definitions/version.inc.php" <<'PY'
from pathlib import Path
import re,sys
match=re.search(rb"define\s*\(\s*['\"]OBSERVIUM_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]",Path(sys.argv[1]).read_bytes())
if match is None: raise SystemExit('ERRO: versao instalada nao identificada')
print(match.group(1).decode('ascii'))
PY
)"
echo "Versao instalada: $VERSION"
python3 "$WORK/transaction.py" --baseline "$BASELINE" --bundle "$ZIP" \
  --validator "$WORK/validator.py" --installed-version "$VERSION"
echo 'FINALIZADO: apenas simulacao em arquivos temporarios; sem acesso de escrita ao Observium.'
