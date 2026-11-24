#!/usr/bin/env bash
# ISP_ALERT_PANEL_V1 — opt-in external assets; guarded navbar integration.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == --check || "$MODE" == --apply ]] || { echo 'Uso: --check|--apply'; exit 2; }
[[ $EUID == 0 ]] || { echo 'Execute como root'; exit 1; }
OBS="${OBSERVIUM_DIR:-/opt/observium}"
RAW="${ISP_REPO_RAW:-https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main}"
NAV="$OBS/html/includes/navbar.inc.php"
JS="$OBS/html/js/isp-alert-panel.js"
CSS="$OBS/html/css/isp-alert-panel.css"
[[ -f "$NAV" && ! -L "$NAV" ]] || { echo 'ERRO: navbar ausente ou link'; exit 1; }
for cmd in curl python3 php; do command -v "$cmd" >/dev/null || { echo "ERRO: falta $cmd"; exit 1; }; done
WORK="$(mktemp -d /tmp/isp-alert-install.XXXXXX)"
trap 'rm -rf "$WORK"' EXIT
curl -fsSL --retry 2 "$RAW/alert-panel/alert-panel.js" -o "$WORK/script.js"
curl -fsSL --retry 2 "$RAW/alert-panel/alert-panel.css" -o "$WORK/style.css"
[[ -s "$WORK/script.js" && -s "$WORK/style.css" ]]
if command -v node >/dev/null 2>&1; then node --check "$WORK/script.js"; else echo 'AVISO: Node.js ausente: aplicacao bloqueada'; [[ "$MODE" != --apply ]] || exit 3; fi
# Reject diverging pre-existing assets; no silent overwrite.
for pair in "$JS:$WORK/script.js" "$CSS:$WORK/style.css"; do
  dst="${pair%%:*}"; src="${pair#*:}"
  if [[ -e "$dst" || -L "$dst" ]] && { [[ -L "$dst" ]] || ! cmp -s "$dst" "$src"; }; then
    echo "BLOQUEADO: ativo divergente: $dst"; exit 3
  fi
done
python3 - "$NAV" "$WORK/navbar.new" <<'PY'
from pathlib import Path
import sys
nav = Path(sys.argv[1]).read_bytes()
mark = b'ISP_ALERT_PANEL_EXTERNAL_V1'
if mark in nav:
    if nav.count(mark) != 1:
        raise SystemExit('BLOQUEADO: marcador duplicado')
    result = nav
else:
    if nav.count(b'</header>') != 1:
        raise SystemExit('BLOQUEADO: </header> ausente ou ambiguo na navbar')
    snippet = (b'\n<!-- ISP_ALERT_PANEL_EXTERNAL_V1 -->\n'
               b'<link rel="stylesheet" href="/css/isp-alert-panel.css">\n'
               b'<script defer src="/js/isp-alert-panel.js"></script>\n')
    result = nav.replace(b'</header>', b'</header>' + snippet, 1)
Path(sys.argv[2]).write_bytes(result)
PY
php -l "$WORK/navbar.new" >/dev/null
if [[ "$MODE" == --check ]]; then
  echo 'CHECK OK: assets e navbar preparados em memoria; nenhuma alteracao feita.'
  exit 0
fi
BACKUP="/root/observium-isp-backups/alert-panel-$(date +%Y%m%dT%H%M%S)-$$"
mkdir -p "$BACKUP"
chmod 700 "$BACKUP"
cp -a "$NAV" "$BACKUP/navbar.inc.php"
for dst in "$JS" "$CSS"; do
  [[ ! -e "$dst" ]] || cp -a "$dst" "$BACKUP/$(basename "$dst")"
done
NAV_SHA="$(sha256sum "$NAV" | cut -d' ' -f1)"
CHANGED_JS=0
CHANGED_CSS=0
CHANGED_NAV=0
rollback() {
  code=$?
  if [[ $code -ne 0 ]]; then
    if [[ $CHANGED_NAV == 1 ]]; then cp -a "$BACKUP/navbar.inc.php" "$NAV"; fi
    if [[ $CHANGED_JS == 1 ]]; then rm -f "$JS"; fi
    if [[ $CHANGED_CSS == 1 ]]; then rm -f "$CSS"; fi
    echo "ERRO: rollback dos arquivos desta execucao efetuado" >&2
  fi
}
trap rollback EXIT
[[ "$(sha256sum "$NAV" | cut -d' ' -f1)" == "$NAV_SHA" ]] || { echo 'BLOQUEADO: navbar mudou'; exit 3; }
if [[ ! -e "$JS" ]]; then install -m 0644 "$WORK/script.js" "$JS"; CHANGED_JS=1; fi
if [[ ! -e "$CSS" ]]; then install -m 0644 "$WORK/style.css" "$CSS"; CHANGED_CSS=1; fi
if ! cmp -s "$NAV" "$WORK/navbar.new"; then
  cat "$WORK/navbar.new" > "$NAV"
  CHANGED_NAV=1
fi
php -l "$NAV" >/dev/null
cmp -s "$JS" "$WORK/script.js"
cmp -s "$CSS" "$WORK/style.css"
grep -q 'ISP_ALERT_PANEL_EXTERNAL_V1' "$NAV"
echo "APLICADO: botão de tela cheia e resumo lateral de alertas. BACKUP: $BACKUP"
echo 'NAO INCLUIDO: telemetria optica real; os valores sao marcados como indisponiveis.'
