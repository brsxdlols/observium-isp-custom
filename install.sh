#!/usr/bin/env bash
# Observium ISP: new install, validation, or incremental application.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 ]] || { echo 'Execute como root.' >&2; exit 1; }
RAW="${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}"
MODE="${1:---check}"
case "$MODE" in
  --new|--check|--apply|--categories-check|--categories-apply|--dashboard-check|--dashboard-apply) ;;
  *) echo 'Uso: bash install.sh [--new|--check|--apply|--categories-check|--categories-apply|--dashboard-check|--dashboard-apply]'; exit 2 ;;
esac
command -v curl >/dev/null || { echo 'curl nao instalado'; exit 1; }
TMP=$(mktemp -d /tmp/observium-isp-install.XXXXXX)
trap 'rm -rf "$TMP"' EXIT

fetch() {
  local path="$1" dest="$2"
  mkdir -p "$(dirname "$TMP/$dest")"
  curl -fsSL --retry 2 "$RAW/$path" -o "$TMP/$dest"
}

if [[ "$MODE" == --new ]]; then
  [[ ! -e /opt/observium ]] || { echo '/opt/observium ja existe: use --check ou --apply'; exit 1; }
  fetch base/instalar-observium.sh base.sh
  bash -n "$TMP/base.sh"
  bash "$TMP/base.sh"
  DEBIAN_FRONTEND=noninteractive apt-get install -y php8.2-sqlite3
  MODE=--apply
fi

if [[ "$MODE" == --check || "$MODE" == --apply ]]; then
  fetch apply-isp.sh apply.sh
  bash -n "$TMP/apply.sh"
  bash "$TMP/apply.sh" "$MODE"
fi

if [[ "$MODE" == --check || "$MODE" == --apply || "$MODE" == --categories-check || "$MODE" == --categories-apply ]]; then
  fetch install-category-ui.sh categories/install-category-ui.sh
  fetch category-ui/category-view.js categories/category-ui/category-view.js
  fetch category-ui/category-view.css categories/category-ui/category-view.css
  fetch category-ui/navbar-custom.inc.php categories/category-ui/navbar-custom.inc.php
  bash -n "$TMP/categories/install-category-ui.sh"
  case "$MODE" in
    --check|--categories-check) bash "$TMP/categories/install-category-ui.sh" --check ;;
    --apply|--categories-apply) bash "$TMP/categories/install-category-ui.sh" --apply ;;
  esac
fi

# Dashboard is seeded in a separate named dashboard and never overwrites an
# existing dashboard (including the production dashboard ID 1).
if [[ "$MODE" == --check || "$MODE" == --apply || "$MODE" == --dashboard-check || "$MODE" == --dashboard-apply ]]; then
  fetch install-dashboard.sh dashboard/install-dashboard.sh
  bash -n "$TMP/dashboard/install-dashboard.sh"
  case "$MODE" in
    --check|--dashboard-check) bash "$TMP/dashboard/install-dashboard.sh" --check ;;
    --apply|--dashboard-apply) bash "$TMP/dashboard/install-dashboard.sh" --apply ;;
  esac
fi

if [[ "$MODE" == --check || "$MODE" == --apply ]]; then
  fetch audit-isp.sh audit.sh
  bash -n "$TMP/audit.sh"
  bash "$TMP/audit.sh"
fi