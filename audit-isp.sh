#!/usr/bin/env bash
set -Eeuo pipefail
OBS="${OBSERVIUM_DIR:-/opt/observium}"
[[ -d "$OBS" ]] || { echo 'ERRO: /opt/observium ausente'; exit 1; }
count_missing=0
check_file() { if [[ -f "$OBS/$1" ]]; then echo "OK: $2"; else echo "PENDENTE: $2 ($1)"; count_missing=$((count_missing+1)); fi; }
echo '===== AUDITORIA ISP - SOMENTE LEITURA ====='
check_file html/includes/widgets/isp_bgp.inc.php 'Widget BGP'
check_file html/includes/widgets/isp_rib.inc.php 'Widget RIB/PPPoE'
check_file html/includes/widgets/isp_links.inc.php 'Widget links'
check_file html/includes/widgets/isp_legend.inc.php 'Legenda'
check_file html/pages/pppoe.inc.php 'Pagina PPPoE'
check_file isp-rib/rib.json 'Snapshot de coleta RIB'
check_file isp-pppoe/history-multi.sqlite 'Historico PPPoE (SQLite)'
for marker in ISP_DASHBOARD_STABILITY_V1 ISP_STATUS_MANAGER_V2 ISP_DEVICE_MODAL_V3; do
  if grep -Fq "$marker" "$OBS/html/js/dashboard.js" 2>/dev/null; then echo "OK: integracao dashboard.js $marker";
  else echo "PENDENTE: integracao dashboard.js $marker"; count_missing=$((count_missing+1)); fi
done
if grep -Fq ISP_STATUS_MANAGER_SERVER_V2 "$OBS/html/ajax/widget.php" 2>/dev/null; then
  echo 'OK: integracao de alertas widget.php'
else
  echo 'PENDENTE: integracao de alertas widget.php'; count_missing=$((count_missing+1))
fi
if [[ -r /etc/cron.d/observium ]]; then echo 'OK: cron nativo Observium existe'; else echo 'PENDENTE: cron nativo Observium'; count_missing=$((count_missing+1)); fi
if command -v php >/dev/null 2>&1 && [[ -r "$OBS/config.php" ]]; then
  php -l "$OBS/config.php" >/dev/null && echo 'OK: config.php sintaxe valida' || { echo 'PENDENTE: config.php sintaxe'; count_missing=$((count_missing+1)); }
fi
echo "Auditoria concluida. Pendencias detectadas: $count_missing"
echo 'ATENCAO: auditoria verifica presenca/marcadores, nao testa SNMP, coleta efetiva ou funcionamento no navegador.'
exit 0