#!/usr/bin/env python3
"""Opt-in, fail-closed deployment of staged core files for an exact Observium CE build.

Expected candidates and originals must already exist in a PRIVATE staging tree.
Do not distribute vendor source/patches or credentials through the repository.
"""
import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

FILES = {
    "html/includes/navbar.inc.php": (
        "7cdaec7afc34a974ee1b4f5f5d71957d5ed61671ea02e894591279550f773dc1",
        "82d0d3c4e38c087a359db6f4538d31a9dc7f21ec30a2ff8f83528c96d9f1503a",
    ),
    "html/ajax/widget.php": (
        "263bdee802257f7ae78793c4687fc8a21704cc218a7baf7db4b510366e31dc4d",
        "c3c30a41a9aa052c9b689244f1bbc2b5ce02dfc0f716002326be358148c3d8d4",
    ),
    "html/js/dashboard.js": (
        "cd4679a17f0f40d25e97183cfd803183a09f239f3effabc17a62a39432870bd6",
        "5733ed9c33ee78fc81fa023dd2398f8517109b839bf0d53104ea8a458957d6db",
    ),
}

def fail(message):
    raise RuntimeError(message)

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def required(path, expected):
    if path.is_symlink() or not path.is_file():
        fail("File missing or symlink: " + str(path))
    if digest(path) != expected:
        fail("Unexpected SHA-256: " + str(path))

def syntax_check(stage):
    for rel in ("html/includes/navbar.inc.php", "html/ajax/widget.php"):
        subprocess.run(["php", "-l", str(stage / rel)], check=True, timeout=20)
    node = shutil.which("node") or shutil.which("nodejs")
    if not node:
        fail("JavaScript validation pending: install an appropriate Node.js runtime before deployment")
    subprocess.run([node, "--check", str(stage / "html/js/dashboard.js")],
                   check=True, timeout=30)
    print("OK: PHP and JavaScript syntax")

def validate(root, staging):
    if not root.is_dir() or root.is_symlink() or staging.is_symlink():
        fail("Invalid Observium/staging directory")
    if not (staging / "preparado").is_dir() or not (staging / "originais").is_dir():
        fail("Private stage missing prepared/originals trees")
    for rel, (before, after) in FILES.items():
        required(root / rel, before)
        required(staging / "originais" / rel, before)
        required(staging / "preparado" / rel, after)
        print("OK: baseline, original and candidate:", rel)
    syntax_check(staging / "preparado")

def copy_into(src, dest):
    if dest.is_symlink() or not dest.is_file():
        fail("Unsafe deployment target: " + str(dest))
    fd, name = tempfile.mkstemp(prefix=".isp-transaction-", dir=str(dest.parent))
    temporary = Path(name)
    try:
        with os.fdopen(fd, "wb") as out, src.open("rb") as inp:
            shutil.copyfileobj(inp, out)
            out.flush()
            os.fsync(out.fileno())
        shutil.copystat(dest, temporary)
        os.chown(temporary, dest.stat().st_uid, dest.stat().st_gid)
        os.replace(temporary, dest)
    finally:
        temporary.unlink(missing_ok=True)

@contextmanager
def exclusive_lock(root):
    lock = Path("/root/observium-isp-core-deploy.lock")
    with lock.open("a+") as f:
        fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield

def transaction(root, staging, backup_parent, simulate=False):
    target_root = root
    if simulate:
        # All writes here take place in a scratch directory owned by the process.
        work = tempfile.TemporaryDirectory(prefix="obs-isp-rehearse-")
        working = Path(work.name)
        target_root = working / "live"
        for rel in FILES:
            dest = target_root / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(root / rel, dest)
        backup_parent = working / "backups"
    else:
        work = None
    try:
        backup_parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        backup = backup_parent / ("core-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S") + "-" + str(os.getpid()))
        backup.mkdir(mode=0o700)
        completed = []
        # Back up ALL three before ANY mutation.
        for rel, (before, _) in FILES.items():
            dest = target_root / rel
            required(dest, before)
            saved = backup / rel
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(dest, saved)
            required(saved, before)
        print("OK: all original files safely backed up:", backup)

        try:
            for index, (rel, (before, after)) in enumerate(FILES.items(), 1):
                dest = target_root / rel
                required(dest, before)
                copy_into(staging / "preparado" / rel, dest)
                completed.append(rel)
                required(dest, after)
                print("OK: deployed:", rel)
                if simulate and index == 2:
                    fail("INJECTED FAILURE: testing rollback")
            if simulate:
                fail("Expected injected failure did not occur")
            syntax_check(target_root)
        except Exception as e:
            rollback_errors = []
            for rel in reversed(completed):
                try:
                    copy_into(backup / rel, target_root / rel)
                    required(target_root / rel, FILES[rel][0])
                    print("ROLLBACK OK:", rel)
                except Exception as rollback_error:
                    rollback_errors.append(str(rollback_error))
            if rollback_errors:
                raise RuntimeError("CRITICAL: rollback incomplete: " + "; ".join(rollback_errors)) from e
            if simulate and str(e) == "INJECTED FAILURE: testing rollback":
                for rel, (before, _) in FILES.items():
                    required(target_root / rel, before)
                print("OK: simulated rollback restored all three originals")
                return
            raise
        for rel, (_, after) in FILES.items():
            required(target_root / rel, after)
        print("OK: deployment hashes verified")
        print("BACKUP:", backup)
    finally:
        if work:
            work.cleanup()

def main():
    p = argparse.ArgumentParser()
    modes = p.add_mutually_exclusive_group(required=True)
    modes.add_argument("--check", action="store_true")
    modes.add_argument("--rehearse", action="store_true")
    modes.add_argument("--apply", action="store_true")
    p.add_argument("--root", type=Path, default=Path("/opt/observium"))
    p.add_argument("--stage", type=Path, required=True)
    p.add_argument("--backup-dir", type=Path,
                   default=Path("/root/observium-isp-backups/core-transactions"))
    p.add_argument("--confirm-production", action="store_true")
    args = p.parse_args()

    # No lockfile is created in check mode; check remains read-only.
    if args.check:
        validate(args.root, args.stage)
        print("CHECK ONLY: no Observium files changed")
        return
    with exclusive_lock(args.root):
        validate(args.root, args.stage)
        if args.rehearse:
            transaction(args.root, args.stage, args.backup_dir, simulate=True)
            print("REHEARSAL ONLY: no Observium files changed")
        else:
            if os.geteuid() != 0 or not args.confirm_production:
                fail("Production apply requires root AND --confirm-production")
            transaction(args.root, args.stage, args.backup_dir, simulate=False)
            print("COMMIT: only three declared core files updated")

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print("BLOCKED/FAILED:", e, file=sys.stderr)
        sys.exit(4)
