#!/usr/bin/env bash
# OBS_ISP_CORE_EXPORT_V1
# Produces a private evidence archive for preparing version-pinned patches.
# Never changes /opt/observium, DB, cron, or running services.
set -Eeuo pipefail
umask 077
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'ERRO: execute como root.' >&2; exit 1; }
OBS="${OBSERVIUM_DIR:-/opt/observium}"
[[ -f "$OBS/config.php" ]] || { echo "ERRO: Observium nao encontrado em $OBS" >&2; exit 1; }
for cmd in tar sha256sum mktemp cp stat; do
  command -v "$cmd" >/dev/null || { echo "ERRO: comando $cmd ausente" >&2; exit 1; }
done

FILES=(
  html/includes/navbar.inc.php
  html/ajax/widget.php
  html/includes/dashboard-generate.inc.php
  html/pages/dashboard.inc.php
  html/js/dashboard.js
)

echo '===== EXPORTACAO PRIVADA DO CORE OBSERVIUM ISP ====='
echo 'Somente arquivos de integracao; nenhum banco, config.php, senha ou cron sera coletado.'
WORK=$(mktemp -d /tmp/observium-core-evidence.XXXXXX)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/files"
MANIFEST="$WORK/manifest.txt"
{
  echo 'OBSERVIUM ISP CORE EVIDENCE V1'
  echo 'OBS_PATH=/opt/observium'
  echo 'CAPTURE_DATE_UTC=' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo 'NOTE=This private archive contains Observium source and must never be committed directly to a public GitHub repository.'
} > "$MANIFEST"

for rel in "${FILES[@]}"; do
  src="$OBS/$rel"
  [[ -f "$src" && ! -L "$src" ]] || { echo "ERRO: arquivo ausente ou link: $src" >&2; exit 2; }
  mkdir -p "$WORK/files/$(dirname "$rel")"
  cp -- "$src" "$WORK/files/$rel"
  chmod 600 "$WORK/files/$rel"
  printf '%s  %s\n' "$(sha256sum "$src" | cut -d' ' -f1)" "$rel" >> "$MANIFEST"
  if [[ "$rel" == *.php ]] && command -v php >/dev/null 2>&1; then
    php -l "$src" >/dev/null || { echo "ERRO: sintaxe PHP invalida: $src" >&2; exit 3; }
  fi
  echo "INCLUIDO: $rel"
done

OUTDIR=/root/observium-isp-exports
mkdir -p "$OUTDIR"
chmod 700 "$OUTDIR"
OUTPUT="$OUTDIR/observium-core-evidence-$(date -u +%Y%m%dT%H%M%SZ)-$$.tar.gz"
tar -czf "$OUTPUT" -C "$WORK" manifest.txt files
chmod 600 "$OUTPUT"
echo
echo "ARQUIVO: $OUTPUT"
echo "SHA256: $(sha256sum "$OUTPUT" | cut -d' ' -f1)"
echo 'ATENCAO: os arquivos sao codigo-fonte do Observium; nao publique este TAR no GitHub publico.'
echo 'Revise eventuais endpoints, URLs e identificadores internos antes de compartilhar.'
echo 'FINALIZADO: nenhuma alteracao aplicada no Observium.'
