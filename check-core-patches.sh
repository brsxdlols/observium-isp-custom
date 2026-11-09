#!/usr/bin/env bash
# OBS_ISP_CORE_PATCH_CHECK_V1 - validates a private patch bundle only.
set -Eeuo pipefail
umask 077
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'ERRO: execute como root.' >&2; exit 1; }
OBS="${OBSERVIUM_DIR:-/opt/observium}"
BUNDLE="${ISP_CORE_PATCH_ZIP:-/root/observium-isp-patches-revisao.zip}"
RAW="${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}"
[[ -f "$OBS/config.php" ]] || { echo 'ERRO: Observium nao encontrado.' >&2; exit 1; }
[[ -r "$BUNDLE" ]] || { echo "ERRO: ZIP privado nao encontrado: $BUNDLE" >&2; exit 2; }
for cmd in python3 curl patch; do command -v "$cmd" >/dev/null || { echo "ERRO: falta $cmd" >&2; exit 1; }; done
WORK=$(mktemp -d /tmp/obs-isp-core-verify.XXXXXX)
trap 'rm -rf -- "$WORK"' EXIT
curl --fail --silent --show-error --location --retry 2 \
  "$RAW/core/validate-core-patches.py" -o "$WORK/validate-core-patches.py"
python3 -m py_compile "$WORK/validate-core-patches.py"
python3 "$WORK/validate-core-patches.py" --bundle "$BUNDLE" --root "$OBS"
echo 'ATENCAO: checagem concluida; nenhum patch aplicado.'
