#!/usr/bin/env bash
# Entrada unificada: instalacao nova a partir do instalador-base ou verificacao/aplicacao incremental.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 ]] || { echo 'ERRO: execute como root.' >&2; exit 1; }
RAW="${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}"
MODE="${1:---check}"
case "$MODE" in --new|--check|--apply) ;; *) echo 'Uso: bash install.sh [--new|--check|--apply]'; exit 2;; esac
command -v curl >/dev/null || { echo 'ERRO: curl ausente.'; exit 1; }
TMP=$(mktemp -d /tmp/observium-isp-install.XXXXXX)
trap 'rm -rf "$TMP"' EXIT
if [[ "$MODE" == --new ]]; then
  [[ ! -e /opt/observium ]] || { echo 'ERRO: /opt/observium existe; use --check ou --apply.'; exit 1; }
  curl -fsSL --retry 2 "$RAW/base/instalar-observium.sh" -o "$TMP/base.sh"
  bash -n "$TMP/base.sh"
  bash "$TMP/base.sh"
  DEBIAN_FRONTEND=noninteractive apt-get install -y php8.2-sqlite3
  MODE=--apply
fi
curl -fsSL --retry 2 "$RAW/apply-isp.sh" -o "$TMP/apply.sh"
bash -n "$TMP/apply.sh"
bash "$TMP/apply.sh" "$MODE"