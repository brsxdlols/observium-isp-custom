#!/usr/bin/env python3
"""Read-only preflight for a future complete Observium ISP deployment.

This is not the installer itself. It reports *explicit* blockers. Never opens
database connections or modifies the Observium tree.
"""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile

CORE = {
    "html/includes/navbar.inc.php",
    "html/ajax/widget.php",
    "html/js/dashboard.js",
}
MODULES = [
    "html/includes/widgets/isp_bgp.inc.php",
    "html/includes/widgets/isp_rib.inc.php",
    "html/includes/widgets/isp_links.inc.php",
    "html/includes/widgets/isp_legend.inc.php",
    "html/pages/pppoe.inc.php",
]

def sha(data):
    return hashlib.sha256(data).hexdigest()

def checked(path):
    if path.is_symlink() or not path.is_file():
        raise ValueError("Unsafe or missing file: " + str(path))
    return path.read_bytes()

def private_bundle(path):
    if not path.exists():
        return None, "private patch bundle absent (expected for a fresh host)"
    try:
        if path.is_symlink() or path.stat().st_size > 5_000_000:
            raise ValueError("invalid/symlinked bundle")
        with tarfile.open(path, "r:gz") as tf:
            required = {"manifest.json"} | {x.replace("/", "__") + ".patch" for x in CORE}
            members = tf.getmembers()
            if len(members) != len(required) or {m.name for m in members} != required:
                raise ValueError("unexpected archive members")
            if any(not m.isfile() or m.size > 2_000_000 for m in members):
                raise ValueError("unsafe archive entries")
            manifest = json.loads(tf.extractfile("manifest.json").read())
            rows = manifest.get("files")
            if manifest.get("format") != "observium-isp-core-review-v1" or not isinstance(rows, list):
                raise ValueError("unexpected manifest")
            byname = {x.get("path"): x for x in rows if isinstance(x, dict)}
            if len(byname) != len(rows) or not CORE.issubset(byname):
                raise ValueError("missing or duplicated manifest entries")
            for name in CORE:
                for field in ("baseline_sha256", "customized_sha256"):
                    value = byname[name].get(field)
                    if not isinstance(value, str) or len(value) != 64:
                        raise ValueError("invalid manifest hash: " + name)
                filename = name.replace("/", "__") + ".patch"
                patch = tf.extractfile(filename).read()
                if not patch.startswith(("--- a/" + name + "\n+++ b/" + name + "\n").encode()):
                    raise ValueError("unexpected patch header: " + name)
        return byname, None
    except (OSError, ValueError, tarfile.TarError, json.JSONDecodeError) as exc:
        return None, "private bundle rejected: " + str(exc)

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--root", type=Path, default=Path("/opt/observium"))
    p.add_argument("--bundle", type=Path,
                   default=Path("/root/observium-isp-core-v80-PRIVADO.tar.gz"))
    args = p.parse_args()
    root = args.root
    print("OBSERVIUM ISP — FULL PRECHECK (NO WRITES)")
    if not root.is_dir() or root.is_symlink():
        print("BLOCKED: Observium directory missing/unsafe")
        return 4
    issues = []
    for name in ("config.php", "includes/definitions/version.inc.php"):
        try:
            checked(root / name)
            print("OK:", name)
        except ValueError as exc:
            issues.append(str(exc))
    for executable in ("php", "python3", "patch", "curl"):
        if shutil.which(executable):
            print("OK: tool", executable)
        else:
            issues.append("missing tool: " + executable)
    for rel in MODULES:
        f = root / rel
        print(("PRESENT" if f.is_file() and not f.is_symlink() else "MISSING") + ": " + rel)
        if not f.is_file() or f.is_symlink():
            issues.append("missing ISP module: " + rel)
    rows, error = private_bundle(args.bundle)
    if error:
        issues.append(error)
    else:
        print("OK: private bundle structure checked (not a security/license review)")
        for rel in sorted(CORE):
            try:
                before = sha(checked(root / rel))
                known = rows[rel]
                state = ("BASELINE" if before == known["baseline_sha256"] else
                         "CUSTOMIZED" if before == known["customized_sha256"] else
                         "CONFLICT")
                print(state + ": " + rel + " " + before)
                if state == "CONFLICT":
                    issues.append("manual/core merge required: " + rel)
            except ValueError as exc:
                issues.append(str(exc))
    print("IMPORTANT: menu/dashboard AJAX functional tests, sources, cron and")
    print("transactional production rollback are NOT certified by this check.")
    issues.append("full automatic core installer not yet approved for production")
    print("BLOCKERS:", len(issues))
    for issue in issues:
        print(" -", issue)
    print("NO OBSERVIUM FILES OR DATABASES WERE MODIFIED")
    return 4

if __name__ == "__main__":
    sys.exit(main())
