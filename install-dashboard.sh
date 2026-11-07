#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'ERRO: execute como root.' >&2; exit 1; }
MODE="${1:---check}"
[[ "$MODE" == --check || "$MODE" == --apply ]] || { echo 'Uso: install-dashboard.sh --check|--apply'; exit 2; }
OBS="${OBSERVIUM_DIR:-/opt/observium}"
RAW="${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}"
[[ "$OBS" == /opt/observium ]] || { echo 'ERRO: dashboard seed atual suporta apenas /opt/observium'; exit 1; }
[[ -f "$OBS/config.php" ]] || { echo 'ERRO: Observium nao instalado.'; exit 1; }
for cmd in php curl; do command -v "$cmd" >/dev/null || { echo "Falta comando: $cmd"; exit 1; }; done
TMP=$(mktemp -d /tmp/observium-isp-seed.XXXXXX)
trap 'rm -rf "$TMP"' EXIT
curl -fsSL --retry 3 "$RAW/dashboard/seed-dashboard.php" -o "$TMP/seed-dashboard.php"
php -l "$TMP/seed-dashboard.php" >/dev/null
if [[ "$MODE" == --apply ]]; then
  BACKUP="/root/observium-isp-backups/dashboard-$(date +%Y%m%d-%H%M%S)-$$"
  mkdir -p "$BACKUP"
  chmod 700 "$BACKUP"
  # No passwords printed; use the Observium-configured DB through PHP for safe inspection.
  php "$TMP/seed-dashboard.php" --check
  # Database credentials are not necessarily in root's mysql config.
  # Do not presume mysqldump --defaults-file contains credentials.
  # Export table snapshot via Observium's DB access, only limited to dashboard tables.
  ISP_DASHBOARD_BACKUP="$BACKUP" php <<'PHP'
<?php
$cli=true;
require_once '/opt/observium/includes/observium.inc.php';
$dest=getenv('ISP_DASHBOARD_BACKUP') ?: null;
if (!$dest || !is_dir($dest)) {fwrite(STDERR,"Invalid backup destination\n");exit(2);}
foreach (['dashboards','dash_widgets'] as $t) {
  $data=dbFetchRows('SELECT * FROM `'.$t.'`');
  $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false || file_put_contents($dest.'/'.$t.'.json',$json)===false) {fwrite(STDERR,"Backup failed: $t\n");exit(3);}
}
PHP
  chmod 600 "$BACKUP"/*
  php "$TMP/seed-dashboard.php" --apply
  echo "Backup dos dashboards: $BACKUP"
else
  php "$TMP/seed-dashboard.php" --check
fi