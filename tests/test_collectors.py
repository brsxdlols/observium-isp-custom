#!/usr/bin/env python3
"""Isolated smoke tests; never touch real Observium database."""
import importlib.util
import sqlite3
import tempfile
import time
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / 'collectors' / 'collect.py'
spec = importlib.util.spec_from_file_location('isp_ingest', SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class TestIngest(unittest.TestCase):
    def test_valid(self):
        now = int(time.time())
        rib = mod.validate_rib({'timestamp':now, 'ipv4_entries': 1048576},now)
        self.assertEqual(rib['ipv4_entries'],1048576)
        rows = mod.validate_pppoe({'devices':[{'device_id':2,'name':'BNG','hostname':'bng.example.local','online':345,'timestamp':now}]},now)
        with tempfile.TemporaryDirectory() as temp:
            original = mod.BASE
            try:
                mod.BASE = Path(temp)
                mod.write_atomic(mod.BASE/'isp-rib/rib.json',rib)
                mod.save_pppoe(rows,2)
                mod.save_pppoe(rows,2)
                db = sqlite3.connect(str(mod.BASE/'isp-pppoe/history-multi.sqlite'))
                self.assertEqual(db.execute('select count(*) from samples').fetchone()[0], 1)
                db.close()
                self.assertTrue((mod.BASE/'isp-pppoe/dashboard.json').is_file())
            finally:
                mod.BASE = original
    def test_invalid(self):
        now = int(time.time())
        with self.assertRaises(ValueError):
            mod.validate_rib({'timestamp':now-4000,'ipv4_entries':1},now)
        with self.assertRaises(ValueError):
            mod.validate_rib({'timestamp':now,'ipv4_entries':True},now)
        with self.assertRaises(ValueError):
            mod.validate_pppoe({'devices':[{'device_id':1,'name':'A','hostname':'h','online':1,'timestamp':now},{'device_id':1,'name':'B','hostname':'h','online':2,'timestamp':now}]},now)

if __name__ == '__main__':
    unittest.main()
