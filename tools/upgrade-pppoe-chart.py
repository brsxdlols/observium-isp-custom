#!/usr/bin/env python3
"""Safe, idempotent update of the FLYNET PPPoE history chart. No DB writes."""
import argparse
from datetime import datetime, timezone
import hashlib
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

PERIODS = [
    ("30min", 1800, 60), ("1h", 3600, 60),
    ("3h", 10800, 120), ("6h", 21600, 180),
    ("12h", 43200, 300), ("24h", 86400, 300),
    ("7d", 604800, 1800), ("30d", 2592000, 7200)
]

def digest(data):
    return hashlib.sha256(data).hexdigest()

def transform(data):
    for marker in (b"ISP_PPPOE_CONSOLIDATION_CARRY_V2",
                   b"ISP_PPPOE_TRANSITION_BRIDGE_V2", b"ispAlertIcon("):
        if marker not in data:
            raise ValueError("Unsupported PPPoE page: missing " + marker.decode())

    for name in ("periods", "bucketSeconds"):
        pattern = rb"\$" + name.encode() + rb"\s*=\s*\[.*?\]\s*(?:\[\s*\$period\s*\])?\s*;"
        matches = list(re.finditer(pattern, data, re.S))
        if len(matches) != 1:
            raise ValueError(name + ": expected exactly one definition")
        match = matches[0]
        values = re.findall(rb"'([^']+)'\s*=>\s*(\d+)", match.group())
        complete = [(key.encode(), str(sec if name == "periods" else bucket).encode())
                    for key, sec, bucket in PERIODS]
        if values == complete:
            continue
        if values != complete[4:]:
            raise ValueError(name + ": unknown values; refusing to overwrite")
        rows = "\n".join("        '%s' => %d," % (key, sec if name == "periods" else bucket)
                         for key, sec, bucket in PERIODS).rstrip(",")
        suffix = "];" if name == "periods" else "][$period];"
        replacement = ("$%s = [\n%s\n    %s" % (name, rows, suffix)).encode()
        data = data[:match.start()] + replacement + data[match.end():]

    replacements = (
        (b"var normalGapLimit =\n                ispMultiBucket * 2.5;",
         b"var normalGapLimit = Math.max(\n                750,\n                ispMultiBucket * 2.5\n            );",
         "continuity"),
        (b"var bw = label === 'Sem coleta' ? 94 : (label === 'Retorno' ? 78 : 67);",
         "var bw = label === 'Recuperação' ? 116 :\n                 label === 'Sem coleta' ? 94 :\n                 label === 'Retorno' ? 78 : 67;".encode(),
         "badge width"),
    )
    for old, new, name in replacements:
        if data.count(old) == 1:
            data = data.replace(old, new, 1)
        elif data.count(new) != 1:
            raise ValueError(name + ": unknown source; refusing to overwrite")

    desired = "delta > 0 ? 'Recuperação' : 'Queda'".encode()
    if data.count(desired) != 1:
        candidates = (b"delta > 0 ? 'Alta' : 'Queda'",
                      b"delta > 0 ? 'Volta' : 'Queda'")
        found = [x for x in candidates if data.count(x) == 1]
        if len(found) != 1:
            raise ValueError("Unknown event label")
        data = data.replace(found[0], desired, 1)
    return data

def main():
    ap = argparse.ArgumentParser(description=__doc__)
    choices = ap.add_mutually_exclusive_group(required=True)
    choices.add_argument("--check", action="store_true")
    choices.add_argument("--apply", action="store_true")
    ap.add_argument("--file", type=Path, default=Path("/opt/observium/html/pages/pppoe.inc.php"))
    ap.add_argument("--backup-dir", type=Path, default=Path("/root/observium-isp-backups"))
    args = ap.parse_args()
    path = args.file
    if not path.is_file() or path.is_symlink():
        ap.error("Missing or symlinked PPPoE page")
    before = path.read_bytes()
    try:
        after = transform(before)
    except ValueError as e:
        ap.error(str(e))
    print("Current:", digest(before), "Candidate:", digest(after))
    if after == before:
        print("ALREADY_CURRENT")
        return
    if args.check:
        print("READY: changes available; no files changed")
        return
    if os.geteuid() != 0:
        ap.error("--apply requires root")
    backup_dir = args.backup_dir / ("pppoe-chart-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ"))
    backup_dir.mkdir(mode=0o700, parents=True, exist_ok=False)
    backup = backup_dir / path.name
    shutil.copy2(path, backup)
    if digest(backup.read_bytes()) != digest(before):
        raise RuntimeError("Backup verification failed")
    temp = None
    installed = False
    try:
        fd, tmp = tempfile.mkstemp(prefix=".pppoe-chart-", dir=str(path.parent))
        temp = Path(tmp)
        with os.fdopen(fd, "wb") as f:
            f.write(after)
            f.flush()
            os.fsync(f.fileno())
        shutil.copystat(path, temp)
        os.chown(temp, path.stat().st_uid, path.stat().st_gid)
        subprocess.run(["php", "-l", str(temp)], check=True)
        if digest(path.read_bytes()) != digest(before):
            raise RuntimeError("Source file changed during preparation")
        os.replace(temp, path)
        temp = None
        installed = True
        subprocess.run(["php", "-l", str(path)], check=True)
        if digest(path.read_bytes()) != digest(after):
            raise RuntimeError("Installed file SHA mismatch")
    except Exception:
        if installed:
            shutil.copy2(backup, path)
            print("ROLLBACK: original restored")
        raise
    finally:
        if temp:
            temp.unlink(missing_ok=True)
    print("APPLIED; backup:", backup)
    print("SQLite, RRD, devices and cron untouched")

if __name__ == "__main__":
    main()
