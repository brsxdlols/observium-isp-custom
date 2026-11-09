#!/usr/bin/env bash
# OBS_ISP_CORE_REHEARSAL_V1: offline simulation only. No /opt/observium changes.
set -Eeuo pipefail
umask 077
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'ERRO: root obrigatorio.' >&2; exit 1; }
OBS="${OBSERVIUM_DIR:-/opt/observium}"
BUNDLE="${ISP_CORE_PATCH_ZIP:-/root/observium-isp-patches-revisao.zip}"
BASELINE="${ISP_CORE_BASELINE:-/opt/observium-community-latest.tar.gz}"
REV="9088cbaafe79d70206013df3c4ec24130711e4fb"
RAW="https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/$REV"
[[ "$OBS" == /opt/observium && -f "$OBS/includes/definitions/version.inc.php" ]] || { echo 'ERRO: Observium esperado em /opt/observium.' >&2; exit 2; }
[[ -r "$BUNDLE" ]] || { echo "ERRO: pacote privado nao encontrado: $BUNDLE" >&2; exit 2; }
for cmd in curl python3 patch tar; do command -v "$cmd" >/dev/null || { echo "ERRO: falta $cmd" >&2; exit 2; }; done

WORK="$(mktemp -d /root/observium-isp-rehearsal.XXXXXX)"
trap 'rm -rf -- "$WORK"' EXIT
if [[ ! -r "$BASELINE" ]]; then
  BASELINE="$WORK/observium-community-latest.tar.gz"
  echo 'Pacote original nao localizado. Baixando Community oficial somente para area temporaria.'
  curl --fail --location --retry 3 --connect-timeout 15 --max-time 600 \
    https://www.observium.org/observium-community-latest.tar.gz -o "$BASELINE"
fi

curl --fail --silent --show-error --location --retry 2 \
  "$RAW/core/rehearse-core-patches.py" -o "$WORK/rehearse-core-patches.py"
curl --fail --silent --show-error --location --retry 2 \
  "$RAW/core/validate-core-patches.py" -o "$WORK/validate-core-patches.py"
python3 - "$WORK/rehearse-core-patches.py" "$WORK/validate-core-patches.py" <<'PY'
from pathlib import Path
import sys
a,b=(Path(p).read_text(encoding='utf-8') for p in sys.argv[1:])
if 'def rehearse(' not in a or 'def load_bundle(' not in b:
    raise SystemExit('ERRO: codigo incompleto')
compile(a,sys.argv[1],'exec')
compile(b,sys.argv[2],'exec')
PY
VERSION=$(python3 - "$OBS/includes/definitions/version.inc.php" <<'PY'
from pathlib import Path
import re,sys
data=Path(sys.argv[1]).read_bytes()
m=re.search(rb"define\s*\(\s*['\"]OBSERVIUM_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]",data)
if not m: raise SystemExit('ERRO: versao do Observium ausente')
print(m.group(1).decode('ascii'))
PY
)
echo "Versao instalada: $VERSION"
python3 "$WORK/rehearse-core-patches.py" \
  --baseline "$BASELINE" \
  --bundle "$BUNDLE" \
  --validator "$WORK/validate-core-patches.py" \
  --installed-version "$VERSION"
