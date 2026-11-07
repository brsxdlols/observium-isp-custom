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
echo '===== AUDITORIA DE COLETA RIB/PPPoE ====='
legacy_count=0
for entry in \
  'isp-rib/collect.php:RIB IPv4' \
  'isp-pppoe/collect.php:PPPoE individual' \
  'isp-pppoe/collect-multi.php:PPPoE multi'; do
  file=${entry%%:*}
  label=${entry#*:}
  if [[ -f "$OBS/$file" ]]; then
    echo "OK: coletor legado $label"
    legacy_count=$((legacy_count + 1))
  else
    echo "AUSENTE: coletor legado $label (pode ser ambiente novo)"
  fi
done
legacy_cron_count=0
for cron in observium-isp-rib observium-isp-pppoe observium-isp-multi; do
  if [[ -f "/etc/cron.d/$cron" ]]; then
    echo "OK: agendamento legado $cron"
    legacy_cron_count=$((legacy_cron_count+1))
  else
    echo "AUSENTE: agendamento legado $cron"
  fi
done
if [[ -f "$OBS/isp-collectors/collect.py" ]]; then
  echo 'OK: ingestor JSON alternativo instalado'
  if [[ -r /etc/observium-isp/sources.json ]]; then
    echo 'OK: fontes do ingestor JSON configuradas (validar dados separadamente)'
  else
    echo 'INFO: ingestor JSON sem fontes; não gera dados'
  fi
elif (( legacy_count == 3 && legacy_cron_count == 3 )); then
  echo 'OK: coleta legada presente; ingestor JSON alternativo não necessário'
else
  echo 'PENDENTE: configurar coletores reais (legados ou ingestor JSON com fontes)'
  count_missing=$((count_missing+1))
fi
if command -v stat >/dev/null 2>&1; then
  for snapshot in isp-rib/rib.json isp-pppoe/dashboard.json isp-pppoe/history-multi.sqlite; do
    if [[ -f "$OBS/$snapshot" ]]; then
      modified=$(stat -c %Y "$OBS/$snapshot" 2>/dev/null || echo 0)
      now=$(date +%s)
      age=$((now-modified))
      echo "DADO: $snapshot; idade=$age segundos (mtime, nao validacao SNMP)"
    fi
  done
fi
echo 'AVISO: arquivos, cron e mtime não comprovam sucesso do polling; verificar logs e dados reais.'
echo "Auditoria concluida. Pendencias detectadas: $count_missing"
echo 'ATENCAO: auditoria verifica presenca/marcadores, nao testa SNMP, coleta efetiva ou funcionamento no navegador.'
exit 0