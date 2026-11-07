#!/usr/bin/env bash
# Safe installation of the Observium ISP local-JSON ingestion framework.
set -Eeuo pipefail
umask 077
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'ERRO: execute como root'; exit 1; }
MODE="${1:---check}"
[[ "$MODE" == --check || "$MODE" == --apply ]] || { echo 'Uso: install-collectors.sh --check|--apply'; exit 2; }
OBS="${OBSERVIUM_DIR:-/opt/observium}"
RAW="${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}"
[[ "$OBS" == /opt/observium && -f "$OBS/config.php" ]] || { echo 'ERRO: Observium esperado em /opt/observium'; exit 1; }
for c in curl python3 flock; do command -v "$c" >/dev/null || { echo "ERRO: comando ausente: $c"; exit 1; }; done
WORK=$(mktemp -d /tmp/obs-isp-collect.XXXXXX)
trap 'rm -rf "$WORK"' EXIT
curl -fsSL --retry 2 "$RAW/collectors/collect.py" -o "$WORK/collect.py"
python3 - "$WORK/collect.py" <<'PY'
from pathlib import Path
import sys
compile(Path(sys.argv[1]).read_text(encoding='utf-8'),sys.argv[1],'exec')
PY
TARGET="$OBS/isp-collectors/collect.py"
CRON="/etc/cron.d/observium-isp-collectors"
CFG="/etc/observium-isp/sources.json"
if [[ -e "$TARGET" ]] && ! cmp -s "$TARGET" "$WORK/collect.py"; then
  echo "DIFERENTE: $TARGET - protegido contra sobrescrita"
  [[ "$MODE" == --check ]] || exit 3
fi
if [[ -e "$CRON" ]] && ! grep -Fq 'ISP_COLLECTORS_CRON_V1' "$CRON"; then
  echo "DIFERENTE: cron local protegido em $CRON"
  [[ "$MODE" == --check ]] || exit 3
fi
if [[ "$MODE" == --check ]]; then
  if [[ -r "$CFG" ]]; then
    python3 "$WORK/collect.py" --check --config "$CFG"
  else
    echo 'PENDENTE: fontes JSON não configuradas (sem coleta real).'
  fi
  echo 'CHECK OK: nenhum arquivo alterado.'
  exit 0
fi
BACKUP="/root/observium-isp-backups/collectors-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP" "$OBS/isp-collectors" /etc/observium-isp
chmod 700 "$BACKUP" /etc/observium-isp
if [[ -f "$TARGET" ]]; then cp -a "$TARGET" "$BACKUP/collect.py"; fi
if [[ -f "$CRON" ]]; then cp -a "$CRON" "$BACKUP/cron"; fi
if [[ ! -e "$TARGET" ]]; then
  install -m 0755 "$WORK/collect.py" "$TARGET"
fi
if [[ ! -e "$CRON" ]]; then
  cat > "$CRON" <<'CRONFILE'
# ISP_COLLECTORS_CRON_V1
# Collector only runs with explicitly configured local JSON sources.
*/5 * * * * root test ! -f /etc/observium-isp/sources.json || /usr/bin/flock -n /run/observium-isp-collector.lock /usr/bin/python3 /opt/observium/isp-collectors/collect.py >> /var/log/observium-isp-collectors.log 2>&1
CRONFILE
  chmod 644 "$CRON"
  chown root:root "$CRON"
fi
echo "APLICADO: ingestor em $TARGET; cron condicional em $CRON"
echo "Configure as fontes em $CFG; não foram criados dados fictícios."
echo "Backup: $BACKUP"