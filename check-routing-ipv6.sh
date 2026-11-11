#!/usr/bin/env bash
# ISP_ROUTING_IPV6_PREFLIGHT_V1 - read-only preflight of IPv6 widget updates.
set -Eeuo pipefail
umask 077
OBS="${OBSERVIUM_DIR:-/opt/observium}"
[[ -f "$OBS/config.php" ]] || { echo "ERRO: Observium nao encontrado em $OBS" >&2; exit 2; }
for cmd in curl python3 php; do command -v "$cmd" >/dev/null || { echo "ERRO: falta $cmd" >&2; exit 2; }; done
WORK="$(mktemp -d /tmp/observium-ipv6-check.XXXXXX)"
trap 'rm -rf -- "$WORK"' EXIT
REPO="https://raw.githubusercontent.com/brsxdlols/observium-isp-custom"
ORIGINAL_REV="1fc8c504b886989a6e38ccd72115db8f8692e15d"
UPDATED_REV="7465febb60384776a240f1cc94b34dbe1ced0cf6"
FILES=(
  "custom/html/includes/widgets/isp_bgp.inc.php:html/includes/widgets/isp_bgp.inc.php"
  "custom/html/includes/widgets/isp_rib.inc.php:html/includes/widgets/isp_rib.inc.php"
  "native/isp-rib/collect.php:isp-rib/collect.php"
)
STATUS=0
for pair in "${FILES[@]}"; do
  source="${pair%%:*}"
  rel="${pair#*:}"
  old="$WORK/old-$(basename "$rel")"
  new="$WORK/new-$(basename "$rel")"
  curl -fsSL --retry 2 "$REPO/$ORIGINAL_REV/$source" -o "$old"
  curl -fsSL --retry 2 "$REPO/$UPDATED_REV/$source" -o "$new"
  php -l "$new" || { echo "ERRO: PHP invalido no repositorio: $source"; exit 4; }
  if [[ ! -f "$OBS/$rel" ]]; then
    echo "AUSENTE: $rel"
    STATUS=4
  elif cmp -s "$OBS/$rel" "$new"; then
    echo "JA ATUALIZADO: $rel"
  elif cmp -s "$OBS/$rel" "$old"; then
    echo "PRONTO PARA ATUALIZACAO CONTROLADA: $rel"
  else
    echo "PROTEGIDO: conteudo local diferente do repositorio; requer reconciliacao manual: $rel"
    STATUS=4
  fi
done
echo "RESULTADO: nenhuma alteracao foi feita em $OBS."
echo "Nota: dados IPv6 e FIB dependem de coleta suportada pelo equipamento."
exit "$STATUS"
