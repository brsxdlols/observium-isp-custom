#!/usr/bin/env bash
# ISP_RIB_THREE_WAY_CHECK_V1 - read only; preview merge of local RIB changes
set -Eeuo pipefail
umask 077
[[ $(id -u) -eq 0 ]] || { echo 'ERRO: execute como root'; exit 2; }
for command_name in git curl php sha256sum; do
  command -v "$command_name" >/dev/null || { echo "DEPENDENCIA AUSENTE: $command_name"; exit 2; }
done
TARGET="/opt/observium/html/includes/widgets/isp_rib.inc.php"
[[ -f "$TARGET" && ! -L "$TARGET" ]] || { echo 'ERRO: widget local ausente ou link'; exit 2; }
TEMP="$(mktemp -d /root/observium-rib-merge-check.XXXXXX)"
trap 'rm -rf -- "$TEMP"' EXIT
OLD_REV=1fc8c504b886989a6e38ccd72115db8f8692e15d
NEW_REV=5c8bfd839f1c245c500da23ec765aebf1f436f96
URL="https://raw.githubusercontent.com/brsxdlols/observium-isp-custom"
PATH_REL=custom/html/includes/widgets/isp_rib.inc.php
curl -fsSL --retry 2 "$URL/$OLD_REV/$PATH_REL" -o "$TEMP/baseline.php"
curl -fsSL --retry 2 "$URL/$NEW_REV/$PATH_REL" -o "$TEMP/updated.php"
cp -a "$TARGET" "$TEMP/local.php"
echo '===== COMPARACAO DOS TRES ARQUIVOS ====='
sha256sum "$TEMP/baseline.php" "$TEMP/local.php" "$TEMP/updated.php"
echo
echo '===== CHECAGEM DE MERGE 3 VIAS (SOMENTE TEMPORARIO) ====='
set +e
git merge-file -p --diff3 "$TEMP/local.php" "$TEMP/baseline.php" "$TEMP/updated.php" >"$TEMP/merged.php" 2>"$TEMP/git-errors.txt"
MERGE_RC=$?
set -e
if [[ "$MERGE_RC" != 0 ]]; then
  if [[ "$MERGE_RC" -ge 1 && "$MERGE_RC" -le 127 ]]; then
    echo "CONFLITOS DE MERGE: $MERGE_RC"
    echo 'Nenhum arquivo do Observium foi modificado.'
    echo 'Precisamos reconciliar os trechos em conflito antes de qualquer implantacao.'
    exit 4
  fi
  echo 'ERRO: git merge-file nao concluiu.'
  exit 2
fi
if grep -Eq '^(<<<<<<< |=======$|>>>>>>> )' "$TEMP/merged.php"; then
  echo 'ERRO: marcadores de conflito na copia gerada'; exit 4
fi
php -l "$TEMP/merged.php" >/dev/null || { echo 'ERRO: copia combinada nao passou no PHP lint'; exit 4; }
echo 'MERGE COMPATIVEL: tres vias sem conflito; PHP lint OK.'
echo "SHA256 COMBINADO: $(sha256sum "$TEMP/merged.php" | cut -d' ' -f1)"
echo
echo 'ATENCAO: sintaxe e merge nao comprovam compatibilidade funcional.'
echo 'NENHUM arquivo do Observium foi modificado.'
