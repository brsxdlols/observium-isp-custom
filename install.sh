#!/usr/bin/env bash
# Observium ISP: new install, validation, or incremental application.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 ]] || { echo 'Execute como root.' >&2; exit 1; }
RAW="${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}"
MODE="${1:---check}"
case "$MODE" in
  --new|--check|--apply|--categories-check|--categories-apply|--dashboard-check|--dashboard-apply|--collectors-check|--collectors-apply|--native-check|--native-apply|--core-check|--core-export) ;;
  *) echo 'Uso: bash install.sh [--new|--check|--apply|--categories-check|--categories-apply|--dashboard-check|--dashboard-apply|--collectors-check|--collectors-apply|--native-check|--native-apply|--core-check|--core-export]'; exit 2 ;;
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

# The ingestion framework does not infer SNMP OIDs/credentials or fabricate samples.
# On an existing collector, conflicting code is left untouched.
if [[ "$MODE" == --check || "$MODE" == --apply || "$MODE" == --collectors-check || "$MODE" == --collectors-apply ]]; then
  fetch install-collectors.sh collectors/install-collectors.sh
  bash -n "$TMP/collectors/install-collectors.sh"
  case "$MODE" in
    --check|--collectors-check) bash "$TMP/collectors/install-collectors.sh" --check ;;
    --apply|--collectors-apply) bash "$TMP/collectors/install-collectors.sh" --apply ;;
  esac
fi

# Native SNMP/PHP collectors from the operational Observium (no secrets hardcoded).
# On existing systems, differences are protected; do not enable duplicate cron jobs.
if [[ "$MODE" == --check || "$MODE" == --apply || "$MODE" == --native-check || "$MODE" == --native-apply ]]; then
  fetch install-native-collectors.sh native/install-native-collectors.sh
  bash -n "$TMP/native/install-native-collectors.sh"
  case "$MODE" in
    --check|--native-check) bash "$TMP/native/install-native-collectors.sh" --check ;;
    --apply|--native-apply) bash "$TMP/native/install-native-collectors.sh" --apply ;;
  esac
fi

if [[ "$MODE" == --check || "$MODE" == --apply ]]; then
  fetch audit-isp.sh audit.sh
  bash -n "$TMP/audit.sh"
  bash "$TMP/audit.sh"
fi
# Private capture for deriving safe, version-pinned core patches.
# Collects selected core source files into /root; never commits them.
if [[ "$MODE" == --core-export ]]; then
  fetch export-core-evidence.sh core/export-core-evidence.sh
  bash -n "$TMP/core/export-core-evidence.sh"
  bash "$TMP/core/export-core-evidence.sh"
fi

# Read-only core integration validation. Failed readiness is reported with
# exit 4; the modules/database may be installed but are NOT fully operational.
if [[ "$MODE" == --check || "$MODE" == --apply || "$MODE" == --core-check ]]; then
  fetch verify-core-integration.sh core/verify-core-integration.sh
  bash -n "$TMP/core/verify-core-integration.sh"
  bash "$TMP/core/verify-core-integration.sh"
fi
