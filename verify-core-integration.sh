#!/usr/bin/env bash
# OBS_ISP_CORE_INTEGRATION_AUDIT_V1
# Read only. Verify runtime dependencies of the production dashboard.
set -Eeuo pipefail
OBS="${OBSERVIUM_DIR:-/opt/observium}"
[[ -r "$OBS/config.php" ]] || { echo "ERRO: config.php ausente em $OBS" >&2; exit 2; }
failures=0
ok() { printf 'OK: %s\n' "$1"; }
missing() { printf 'PENDENTE: %s\n' "$1"; failures=$((failures+1)); }
check_file() { [[ -r "$OBS/$1" ]] && ok "$2" || missing "$2 ($1)"; }
check_marker() {
  local relative="$1" marker="$2" description="$3"
  if [[ -r "$OBS/$relative" ]] && grep -Fq "$marker" "$OBS/$relative"; then
    ok "$description"
  else
    missing "$description (marcador: $marker)"
  fi
}
echo '===== Observium ISP — core dashboard readiness (somente leitura) ====='
check_file html/includes/widgets/isp_bgp.inc.php 'Widget BGP'
check_file html/includes/widgets/isp_rib.inc.php 'Widget RIB/PPPoE'
check_file html/includes/widgets/isp_links.inc.php 'Widget Links'
check_file html/includes/widgets/isp_legend.inc.php 'Widget Legenda'
check_file html/pages/pppoe.inc.php 'Pagina PPPoE'
check_marker html/js/dashboard.js ISP_DASHBOARD_STABILITY_V1 'GridStack e altura de widgets'
check_marker html/js/dashboard.js ISP_STATUS_MANAGER_V2 'Gerenciador visual de alertas'
check_marker html/js/dashboard.js ISP_DEVICE_MODAL_V3 'Modal de dispositivos'
check_marker html/js/dashboard.js ISP_PORT_HIDDEN_FILTER_V5 'Filtros e portas ocultas'
check_marker html/ajax/widget.php ISP_STATUS_MANAGER_SERVER_V2 'Widget servidor de alertas'
check_marker html/ajax/widget.php ISP_PORT_INVENTORY_V2 'Inventario AJAX de portas'
check_marker html/includes/navbar.inc.php ISP_CATEGORY_VIEW_FIX_V3 'Seletor de categoria V3'
check_marker html/includes/navbar.inc.php ISP_PORTS_MENU_NAS_V1 'Menu NAS'
check_marker html/includes/navbar.inc.php ISP_DASHBOARD_AJAX_ONLY_V1 'Refresh sem reload geral'
for file in html/includes/navbar.inc.php html/ajax/widget.php html/includes/dashboard-generate.inc.php html/pages/dashboard.inc.php; do
  if [[ -r "$OBS/$file" ]] && command -v php >/dev/null && php -l "$OBS/$file" >/dev/null 2>&1; then
    ok "Sintaxe PHP: $file"
  else
    missing "Sintaxe PHP ou arquivo ausente: $file"
  fi
done
printf '=== Resultado: %d pendencia(s) de integracao ===\n' "$failures"
echo 'Nota: marcadores/sintaxe validos nao substituem testes de navegador, AJAX, SNMP ou SQL.'
if (( failures > 0 )); then
  echo 'PROTECAO: nao considere a instalacao do dashboard completa; patches do core ainda nao aplicados.'
  exit 4
fi
exit 0