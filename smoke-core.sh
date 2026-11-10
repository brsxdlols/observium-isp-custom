#!/usr/bin/env bash
# OBS_ISP_SMOKE_V1 - only read source and data metadata; no writes in Observium
set -Eeuo pipefail
umask 077
OBS="${OBSERVIUM_DIR:-/opt/observium}"
[[ -f "$OBS/config.php" ]] || { echo "ERRO: Observium nao encontrado: $OBS" >&2; exit 2; }
for cmd in curl python3; do command -v "$cmd" >/dev/null || { echo "ERRO: falta $cmd" >&2; exit 2; }; done
REV="8295449012c69e258827ebb57789fff2ff26e626"
WORK="$(mktemp -d /tmp/obs-isp-smoke.XXXXXX)"
trap 'rm -rf -- "$WORK"' EXIT
curl -fsSL --retry 2 \
  "https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/$REV/core/smoke-core.py" \
  -o "$WORK/smoke-core.py"
python3 -m py_compile "$WORK/smoke-core.py"
python3 "$WORK/smoke-core.py" --root "$OBS"
