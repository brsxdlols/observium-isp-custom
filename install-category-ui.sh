#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OBS="${OBSERVIUM_ROOT:-/opt/observium}"
MODE="${1:---check}"
[[ "$MODE" == --check || "$MODE" == --apply ]] || { echo 'Use --check ou --apply'; exit 2; }
[[ $EUID == 0 ]] || { echo 'Execute como root'; exit 1; }
for cmd in php python3; do command -v "$cmd" >/dev/null || { echo "Falta: $cmd"; exit 1; }; done
NAV="$OBS/html/includes/navbar.inc.php"
CONF="$OBS/config.php"
CUSTOM="$OBS/html/includes/navbar-custom.inc.php"
JS="$OBS/html/js/isp-category-view.js"
CSS="$OBS/html/css/isp-category-view.css"
[[ -f "$NAV" && -f "$CONF" ]] || { echo 'Observium ausente'; exit 1; }

# Do not replace user-installed custom navbar logic or third-party files.
for pair in "$CUSTOM|$ROOT/category-ui/navbar-custom.inc.php" "$JS|$ROOT/category-ui/category-view.js" "$CSS|$ROOT/category-ui/category-view.css"; do
    dst="${pair%%|*}"; src="${pair#*|}"
    [[ -f "$src" ]] || { echo "Falta recurso: $src"; exit 1; }
    if [[ -e "$dst" ]] && ! cmp -s "$dst" "$src"; then
        echo "CONFLITO: $dst já existe e é diferente. Nada alterado."; exit 1
    fi
done

# Existing production customization V3 is already functional: leave its UI alone.
if grep -q 'ISP_CATEGORY_VIEW_FIX_V3' "$NAV"; then
    echo 'Seletor V3 já presente no núcleo. Não será duplicado.'
    ENABLE_NAV=0
else
    ENABLE_NAV=1
    [[ $(grep -c '</header>' "$NAV" || :) -eq 1 ]] || {
        echo 'Estrutura navbar incompatível: esperado um </header>. Nada alterado.'; exit 1
    }
fi

TMP=$(mktemp -d /tmp/isp-cat-ui.XXXXXX)
trap 'rm -rf "$TMP"' EXIT
cat "$CONF" > "$TMP/config.php"
cat "$NAV" > "$TMP/navbar.inc.php"
python3 - "$TMP/config.php" "$TMP/navbar.inc.php" "$ENABLE_NAV" <<'PY'
from pathlib import Path
import re,sys
conf,nav=map(Path,sys.argv[1:3]); enable=sys.argv[3]=='1'
s=conf.read_text()
for group in ('CGNAT','PPPOE','TRUNK','PTP','MGMT','NAS'):
    if not re.search(r"\$config\s*\[\s*['\"]int_groups['\"]\s*\]\s*\[\s*\]\s*=\s*['\"]" + group + r"['\"]",s,re.I):
        s+="\n$config['int_groups'][] = '%s';\n"%group
# Description parser in Observium will label NAS ifAlias as NAS after discovery.
conf.write_text(s)
if enable:
    s=nav.read_text()
    snippet="""\n<!-- ISP_CATEGORY_VIEW_EXTERNAL_V1 -->
<link rel="stylesheet" href="/css/isp-category-view.css" />
<script defer src="/js/isp-category-view.js"></script>
"""
    if 'ISP_CATEGORY_VIEW_EXTERNAL_V1' not in s:
        s=s.replace('</header>','</header>'+snippet,1)
    nav.write_text(s)
PY
php -l "$TMP/config.php" >/dev/null
php -l "$TMP/navbar.inc.php" >/dev/null
php -l "$ROOT/category-ui/navbar-custom.inc.php" >/dev/null
if command -v node >/dev/null; then node --check "$ROOT/category-ui/category-view.js"; fi

echo "Preflight OK: modo=$MODE; navbar_patch=$ENABLE_NAV; destino=$OBS"
if [[ "$MODE" == --check ]]; then echo 'Nenhuma alteração efetuada.'; exit 0; fi

BACKUP="/root/observium-isp-category-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP"
chmod 700 "$BACKUP"
cp -a "$CONF" "$BACKUP/config.php"
cp -a "$NAV" "$BACKUP/navbar.inc.php"
[[ -e "$CUSTOM" ]] && cp -a "$CUSTOM" "$BACKUP/navbar-custom.inc.php" || true
[[ -e "$JS" ]] && cp -a "$JS" "$BACKUP/isp-category-view.js" || true
[[ -e "$CSS" ]] && cp -a "$CSS" "$BACKUP/isp-category-view.css" || true
mkdir -p "$(dirname "$JS")" "$(dirname "$CSS")"
cp "$ROOT/category-ui/navbar-custom.inc.php" "$CUSTOM"
cp "$ROOT/category-ui/category-view.js" "$JS"
cp "$ROOT/category-ui/category-view.css" "$CSS"
# Preserve original permissions, owner and group for existing core files.
cat "$TMP/config.php" > "$CONF"
cat "$TMP/navbar.inc.php" > "$NAV"
php -l "$CONF" >/dev/null && php -l "$NAV" >/dev/null && php -l "$CUSTOM" >/dev/null
printf 'Aplicação concluída. Backup: %s\n' "$BACKUP"
printf 'Após discovery, prefixes NAS: ficam disponíveis em gráficos agrupados.\n'