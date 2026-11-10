#!/usr/bin/env python3
"""Read-only ISP core smoke diagnostics. No authentication, SNMP polling or writes."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
from datetime import datetime, timezone

PHP_TARGETS = (
    "html/includes/navbar.inc.php",
    "html/ajax/widget.php",
    "html/includes/dashboard-generate.inc.php",
    "html/pages/dashboard.inc.php",
)
JS_TARGET = "html/js/dashboard.js"
MARKERS = {
    "html/includes/navbar.inc.php": (
        "ISP_CATEGORY_VIEW_FIX_V3",
        "ISP_PORTS_MENU_NAS_V1",
        "ISP_DASHBOARD_AJAX_ONLY_V1",
    ),
    "html/ajax/widget.php": (
        "ISP_STATUS_MANAGER_SERVER_V2",
        "ISP_PORT_INVENTORY_V2",
    ),
    JS_TARGET: (
        "ISP_DASHBOARD_STABILITY_V1",
        "ISP_STATUS_MANAGER_V2",
        "ISP_DEVICE_MODAL_V3",
        "ISP_PORT_HIDDEN_FILTER_V5",
    ),
}
SNAPSHOTS = (
    "isp-rib/rib.json",
    "isp-pppoe/dashboard.json",
)
LIMIT = 4_000_000


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path("/opt/observium"))
    parser.add_argument("--json-out", type=Path, help="optional report file OUTSIDE Observium")
    args = parser.parse_args()
    root = args.root.resolve(strict=True)
    if not (root / "config.php").is_file():
        parser.error("Observium config.php nao encontrado")

    checks = []
    def record(name, state, note):
        checks.append({"check": name, "status": state, "note": note})
        print(f"{state}: {name} - {note}")

    def get_file(rel):
        p = root / rel
        if p.is_symlink() or not p.is_file():
            return None
        try:
            if p.stat().st_size > LIMIT:
                return None
            return p.read_bytes()
        except OSError:
            return None

    print("===== OBSERVIUM ISP - SMOKE READ ONLY =====")
    for rel, markers in MARKERS.items():
        blob = get_file(rel)
        if blob is None:
            record(rel, "FAIL", "arquivo ausente, link ou muito grande")
            continue
        txt = blob.decode("utf-8", errors="replace")
        missing = [marker for marker in markers if marker not in txt]
        record("integracao " + rel, "WARN" if missing else "PASS",
               "marcadores ausentes: " + ", ".join(missing) if missing else "marcadores presentes")
        record("sha256 " + rel, "PASS", hashlib.sha256(blob).hexdigest())

    php = shutil.which("php")
    for rel in PHP_TARGETS:
        if get_file(rel) is None:
            record("php-lint " + rel, "FAIL", "arquivo indisponivel")
        elif not php:
            record("php-lint " + rel, "WARN", "PHP CLI nao instalado")
        else:
            result = subprocess.run([php, "-l", str(root / rel)],
                                    capture_output=True, text=True, timeout=20)
            record("php-lint " + rel, "PASS" if result.returncode == 0 else "FAIL",
                   "sintaxe valida" if result.returncode == 0 else "sintaxe invalida (verificar localmente)")

    # Node's --check parses JavaScript without executing it or starting a browser.
    node = shutil.which("node")
    if get_file(JS_TARGET) is None:
        record("js-parse", "FAIL", "dashboard.js ausente")
    elif not node:
        record("js-parse", "WARN", "Node.js ausente; sintaxe JavaScript nao avaliada")
    else:
        result = subprocess.run([node, "--check", str(root / JS_TARGET)],
                                capture_output=True, text=True, timeout=20)
        record("js-parse", "PASS" if result.returncode == 0 else "FAIL",
               "sintaxe valida" if result.returncode == 0 else "sintaxe invalida (verificar localmente)")

    now = datetime.now(timezone.utc).timestamp()
    for rel in SNAPSHOTS:
        p = root / rel
        if p.is_symlink() or not p.is_file():
            record("snapshot " + rel, "WARN", "ausente")
            continue
        age = max(0, int(now - p.stat().st_mtime))
        record("snapshot " + rel, "PASS" if age <= 3600 else "WARN",
               f"mtime ha {age}s (nao comprova coleta SNMP)")

    failed = sum(item["status"] == "FAIL" for item in checks)
    warnings = sum(item["status"] == "WARN" for item in checks)
    report = {
        "format": "observium-isp-core-smoke-v1",
        "checks": checks,
        "summary": {"failed": failed, "warnings": warnings},
        "limitations": "Nao testa sessao autenticada, AJAX real, browser, SQL nem SNMP.",
    }
    if args.json_out:
        out = args.json_out.absolute()
        if root == out or root in out.parents:
            parser.error("relatorio nao pode ser gravado dentro do Observium")
        out.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        fd = os.open(out, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(report, handle, indent=2, ensure_ascii=False)
        print("Relatorio:", out)
    print(f"RESUMO: {failed} falha(s), {warnings} aviso(s)")
    print("AVISO: teste estatico/sintatico apenas, nao e homologacao funcional no navegador.")
    return 4 if failed else 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, subprocess.TimeoutExpired, ValueError) as exc:
        print("ERRO:", type(exc).__name__, file=sys.stderr)
        sys.exit(2)
