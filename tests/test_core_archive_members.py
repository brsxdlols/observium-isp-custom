#!/usr/bin/env python3
"""Regression tests for official Community archives with unrelated symlinks."""
import importlib.util
import io
import tarfile
import unittest
from pathlib import Path

MODULE = Path(__file__).resolve().parents[1] / 'core' / 'build-core-patches.py'
spec = importlib.util.spec_from_file_location('build_core_patches', MODULE)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def archive(*entries):
    out = io.BytesIO()
    with tarfile.open(fileobj=out, mode='w') as tar:
        for name, kind in entries:
            member = tarfile.TarInfo(name)
            if kind == 'symlink':
                member.type = tarfile.SYMTYPE
                member.linkname = 'bin/observium-wrapper'
                tar.addfile(member)
            elif kind == 'hardlink':
                member.type = tarfile.LNKTYPE
                member.linkname = 'somewhere'
                tar.addfile(member)
            else:
                data = b'<?php echo 1;'
                member.size = len(data)
                tar.addfile(member, io.BytesIO(data))
    out.seek(0)
    return out


class TestArchiveMembers(unittest.TestCase):
    def inspect(self, entries):
        with tarfile.open(fileobj=archive(*entries), mode='r:') as tar:
            return mod.members(tar)

    def test_ignore_unrelated_symlink(self):
        result = self.inspect([
            ('observium/observium-wrapper', 'symlink'),
            ('observium/html/ajax/widget.php', 'file')])
        self.assertIn('observium/html/ajax/widget.php', result)
        self.assertNotIn('observium/observium-wrapper', result)

    def test_reject_required_symlink(self):
        with self.assertRaisesRegex(ValueError, 'not a regular file'):
            self.inspect([('observium/html/ajax/widget.php', 'symlink')])

    def test_reject_required_hardlink(self):
        with self.assertRaisesRegex(ValueError, 'not a regular file'):
            self.inspect([('files/html/js/dashboard.js', 'hardlink')])

    def test_reject_traversal_even_in_ignored_link(self):
        with self.assertRaisesRegex(ValueError, 'unsafe path'):
            self.inspect([('observium/../escape', 'symlink')])

    def test_reject_duplicate_required(self):
        with self.assertRaisesRegex(ValueError, 'Duplicate required'):
            self.inspect([('observium/html/ajax/widget.php', 'file'),
                          ('observium/html/ajax/widget.php', 'file')])


if __name__ == '__main__':
    unittest.main()
