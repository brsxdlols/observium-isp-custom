#!/usr/bin/env bash
# ISP_NATIVE_PHP_INSTALLER_V1 - safe installation of real SNMP collectors
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == --check || "$MODE" == --apply ]] || { echo 'Uso: install-native-collectors.sh [--check|--apply]'; exit 2; }
[[ $EUID -eq 0 ]] || { echo 'ERRO: root obrigatorio'; exit 1; }
OBS="${OBSERVIUM_DIR:-/opt/observium}"
[[ "$OBS" == /opt/observium && -r "$OBS/config.php" ]] || { echo 'ERRO: requer /opt/observium/config.php'; exit 1; }
RAW="${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}"
for cmd in curl php cmp install; do command -v "$cmd" >/dev/null || { echo "ERRO: falta $cmd"; exit 1; }; done
TMP=$(mktemp -d /tmp/obs-native.XXXXXX)
trap 'rm -rf "$TMP"' EXIT
FILES=(isp-rib/collect.php isp-pppoe/collect.php isp-pppoe/collect-multi.php isp-pppoe/export.php)
for rel in "${FILES[@]}"; do
  mkdir -p "$TMP/$(dirname "$rel")"
  curl --retry 2 -fsSL "$RAW/native/$rel" -o "$TMP/$rel"
  php -l "$TMP/$rel" >/dev/null
  if [[ -e "$OBS/$rel" ]] && ! cmp -s "$OBS/$rel" "$TMP/$rel"; then
    echo "PROTEGIDO: $OBS/$rel ja existe e e diferente."
    [[ "$MODE" == --check ]] || { echo 'ERRO: nenhuma alteracao aplicada.'; exit 3; }
  else
    echo "OK: $rel"
  fi
done
CONF=/etc/observium-isp/native-collectors.php
CRONDIR=/etc/cron.d
if [[ "$MODE" == --check ]]; then
  legacy_crons=0
  for name in observium-isp-rib observium-isp-pppoe observium-isp-multi; do
    if [[ -e "$CRONDIR/$name" ]]; then
      echo "EXISTENTE: $CRONDIR/$name (nao sera substituido)"
      legacy_crons=$((legacy_crons+1))
    else
      echo "AUSENTE: $CRONDIR/$name"
    fi
  done
  legacy_files=0
  for rel in "${FILES[@]}"; do
    [[ -f "$OBS/$rel" ]] && legacy_files=$((legacy_files+1))
  done
  if [[ -r "$CONF" ]]; then
    php -l "$CONF"
    echo 'CONFIGURADO: arquivo privado do novo coletor nativo presente.'
  elif (( legacy_files == 4 && legacy_crons == 3 )); then
    echo 'AMBIENTE LEGADO DETECTADO: quatro coletores e tres cron encontrados.'
    echo 'INFO: configuracao privada nova NAO e obrigatoria para os coletores legados.'
    echo 'INFO: validar logs, fontes SNMP e atualizacao dos snapshots separadamente.'
  else
    echo 'PENDENTE: configuracao privada para o novo padrao de coletores.'
    echo 'AVISO: arquivos/cron parciais; nao ativar coleta nova sem revisao.'
  fi
  echo 'CHECK OK: nenhuma alteracao efetuada.'
  exit 0
fi
# Important: if any legacy files exist with different content, preflight above exits before writes.
mkdir -p /etc/observium-isp
chmod 700 /etc/observium-isp
for rel in "${FILES[@]}"; do
  if [[ ! -e "$OBS/$rel" ]]; then
    install -D -m 0644 "$TMP/$rel" "$OBS/$rel"
  fi
done
if [[ ! -e "$CONF" ]]; then
  curl --retry 2 -fsSL "$RAW/native-config.example.php" -o "$TMP/native-config.php"
  php -l "$TMP/native-config.php" >/dev/null
  install -m 0600 "$TMP/native-config.php" "$CONF"
  echo "CONFIGURAR: $CONF (ID de RIB e concentrador, sem dados ficticios)"
fi
# Never enable cron until private config has been filled with appropriate device IDs.
# The multi collector runs only after explicit enablement, to prevent duplicate polling.
echo 'MODULOS INSTALADOS; cron preservado e nao ativado automaticamente.'
echo 'Use docs/NATIVE-COLLECTORS.md para configurar e ativar com validacao.'