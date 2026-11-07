#!/usr/bin/env bash
# Observium ISP - instala somente modulos proprios e grupos; nao sobrescreve o core.
set -Eeuo pipefail
umask 077

REPO_RAW="${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}"
OBS="${OBSERVIUM_DIR:-/opt/observium}"
MODE="${1:---check}"
[[ "$MODE" == --check || "$MODE" == --apply ]] || { echo 'Uso: bash apply-isp.sh [--check|--apply]'; exit 2; }
[[ $EUID == 0 ]] || { echo 'ERRO: requer root.' >&2; exit 1; }
[[ -f "$OBS/config.php" && -f "$OBS/html/includes/navbar.inc.php" ]] || { echo 'ERRO: Observium existente nao encontrado.' >&2; exit 1; }
for c in python3 curl php sha256sum; do command -v "$c" >/dev/null || { echo "ERRO: falta $c" >&2; exit 1; }; done

WORK=$(mktemp -d /tmp/observium-isp.XXXXXX)
BACKUP="/root/observium-isp-backups/$(date +%Y%m%d-%H%M%S)-$$"
PUBLISHED=0
RESTORE_LIST="$WORK/restores.txt"
: > "$RESTORE_LIST"
cleanup(){
  local status=$?
  if (( status != 0 && PUBLISHED == 1 )); then
    echo 'Falha: revertendo somente arquivos alterados nesta execucao...' >&2
    while IFS=$'\t' read -r exists rel; do
      if [[ "$exists" == yes ]]; then cp -a "$BACKUP/original/$rel" "$OBS/$rel"; else rm -f "$OBS/$rel"; fi
    done < "$RESTORE_LIST"
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT

MODULES=(
  html/includes/widgets/isp_bgp.inc.php
  html/includes/widgets/isp_links.inc.php
  html/includes/widgets/isp_rib.inc.php
  html/includes/widgets/isp_legend.inc.php
  html/pages/pppoe.inc.php
)

mkdir -p "$WORK/download" "$WORK/prepared"
echo '===== Observium ISP: preflight ====='
for rel in "${MODULES[@]}"; do
  mkdir -p "$WORK/download/$(dirname "$rel")"
  curl --retry 2 -fsSL --connect-timeout 10 --max-time 50 \
    "$REPO_RAW/custom/$rel" -o "$WORK/download/$rel"
  php -l "$WORK/download/$rel" >/dev/null
  if [[ -e "$OBS/$rel" ]] && ! cmp -s "$OBS/$rel" "$WORK/download/$rel"; then
    # Nao substituir um modulo local possivelmente mais novo ou editado.
    echo "DIFERENTE: $rel (protegido; exige revisao manual)"
    if [[ "$MODE" == --apply ]]; then
      echo 'ERRO: arquivo ja existe com conteudo diferente. Nenhuma alteracao efetuada.' >&2
      exit 3
    fi
  else
    echo "OK: $rel"
  fi
done

# Preparar config.php sem persistir antes de validar, sem reproduzir segredos em logs.
python3 - "$OBS/config.php" "$WORK/prepared/config.php" <<'PY'
from pathlib import Path
import re, sys
src=Path(sys.argv[1]).read_text()
block="""
// ISP_CUSTOM_GROUPS_MANAGED_V1 - grupos adicionais para descricao de portas
foreach (['CGNAT', 'PPPOE', 'TRUNK', 'PTP', 'MGMT', 'NAS'] as $isp_group) {
    if (!in_array($isp_group, (array)($config['int_groups'] ?? []), true)) {
        $config['int_groups'][] = $isp_group;
    }
}
$config['custom_port_icons']['NAS'] = $config['icon']['port'] ?? 'sprite-ethernet';
$config['custom_port_icons']['MGMT'] = $config['icon']['port'] ?? 'sprite-ethernet';
"""
if 'ISP_CUSTOM_GROUPS_MANAGED_V1' not in src:
    if re.search(r'<\?php',src) is None: raise SystemExit('config.php sem abertura PHP')
    # Insere antes do ultimo fechamento PHP caso exista.
    m=list(re.finditer(r'\?>',src))
    if m and not src[m[-1].end():].strip():
        at=m[-1].start()
        src=src[:at]+'\n'+block+'\n'+src[at:]
    else: src+='\n'+block+'\n'
Path(sys.argv[2]).write_text(src)
PY
php -l "$WORK/prepared/config.php" >/dev/null

# Relatar pendencias criticas sem alterar o codigo nativo do Observium.
echo '===== Integracoes do nucleo (somente verificacao) ====='
for marker in ISP_CATEGORY_VIEW_FIX_V3 ISP_PORTS_MENU_NAS_V1; do
  if grep -q "$marker" "$OBS/html/includes/navbar.inc.php"; then
    echo "OK navbar: $marker"
  else
    echo "PENDENTE navbar: $marker (nao sera sobrescrito automaticamente)"
  fi
done
if grep -q 'ISP_DASHBOARD_STABILITY_V1' "$OBS/html/js/dashboard.js" 2>/dev/null; then
  echo 'OK: dashboard.js com personalizacao ISP'
else
  echo 'PENDENTE: dashboard.js requer patch compativel com a versao do Observium'
fi

echo 'PENDENTE: coletores RIB/PPPoE, SQLite, cron e configuracao de widgets nao estao neste pacote.'
if [[ "$MODE" == --check ]]; then
  echo 'CHECK CONCLUIDO: nenhum arquivo modificado.'
  exit 0
fi

echo '===== Aplicando modulos com backup ====='
mkdir -p "$BACKUP/original"
chmod 700 "$(dirname "$BACKUP")" "$BACKUP"
for rel in "${MODULES[@]}"; do
  if [[ -e "$OBS/$rel" ]]; then
    mkdir -p "$BACKUP/original/$(dirname "$rel")"
    cp -a "$OBS/$rel" "$BACKUP/original/$rel"
    printf 'yes\t%s\n' "$rel" >> "$RESTORE_LIST"
  else
    printf 'no\t%s\n' "$rel" >> "$RESTORE_LIST"
  fi
  mkdir -p "$OBS/$(dirname "$rel")"
  cp -a "$WORK/download/$rel" "$OBS/$rel"
  PUBLISHED=1
done
rel=config.php
cp -a "$OBS/$rel" "$BACKUP/original/$rel"
printf 'yes\t%s\n' "$rel" >> "$RESTORE_LIST"
cat "$WORK/prepared/config.php" > "$OBS/config.php"
for rel in "${MODULES[@]}" config.php; do php -l "$OBS/$rel" >/dev/null; done
cp "$RESTORE_LIST" "$BACKUP/restore-list.txt"
echo "APLICADO: modulos proprios e grupos. Backup: $BACKUP"
echo 'NAO APLICADO: modificacoes do nucleo, layout e coletores (pendencias acima).'
PUBLISHED=0