#!/usr/bin/env python3
"""Synthetic smoke tests for verify-core-integration.sh. No Observium needed."""
import os
import pathlib
import subprocess
import tempfile
import unittest

BASE = pathlib.Path(__file__).resolve().parents[1]
CHECK = BASE / 'verify-core-integration.sh'

class CoreReadinessTest(unittest.TestCase):
    def prepare(self, root):
        root.joinpath('config.php').write_text('<?php $config = [];\n')
        phpfiles = ['html/includes/navbar.inc.php','html/ajax/widget.php','html/includes/dashboard-generate.inc.php','html/pages/dashboard.inc.php']
        markers = {
            'html/includes/navbar.inc.php': ['ISP_CATEGORY_VIEW_FIX_V3','ISP_PORTS_MENU_NAS_V1','ISP_DASHBOARD_AJAX_ONLY_V1'],
            'html/ajax/widget.php': ['ISP_STATUS_MANAGER_SERVER_V2','ISP_PORT_INVENTORY_V2'],
            'html/js/dashboard.js': ['ISP_DASHBOARD_STABILITY_V1','ISP_STATUS_MANAGER_V2','ISP_DEVICE_MODAL_V3','ISP_PORT_HIDDEN_FILTER_V5'],
        }
        for path in phpfiles + ['html/js/dashboard.js','html/includes/widgets/isp_bgp.inc.php','html/includes/widgets/isp_rib.inc.php','html/includes/widgets/isp_links.inc.php','html/includes/widgets/isp_legend.inc.php','html/pages/pppoe.inc.php']:
            p = root/path; p.parent.mkdir(parents=True,exist_ok=True)
            prefix = '<?php\n' if path.endswith('.php') else ''
            p.write_text(prefix + '\n'.join('// '+x for x in markers.get(path,[]))+'\n')
    def run_check(self,root):
        env=os.environ.copy();env['OBSERVIUM_DIR']=str(root)
        return subprocess.run(['bash',str(CHECK)],capture_output=True,text=True,env=env,check=False)
    def test_complete_fixture(self):
        with tempfile.TemporaryDirectory() as d:
            root=pathlib.Path(d);self.prepare(root)
            result=self.run_check(root)
            self.assertEqual(result.returncode,0,result.stdout+result.stderr)
    def test_missing_alert_patch_fails_closed(self):
        with tempfile.TemporaryDirectory() as d:
            root=pathlib.Path(d);self.prepare(root)
            file=root/'html/ajax/widget.php'
            file.write_text(file.read_text().replace('ISP_PORT_INVENTORY_V2','NOT_INSTALLED'))
            result=self.run_check(root)
            self.assertEqual(result.returncode,4,result.stdout+result.stderr)
            self.assertIn('PENDENTE: Inventario AJAX de portas',result.stdout)
    def test_missing_file_fails_closed(self):
        with tempfile.TemporaryDirectory() as d:
            root=pathlib.Path(d);self.prepare(root)
            (root/'html/pages/pppoe.inc.php').unlink()
            result=self.run_check(root)
            self.assertEqual(result.returncode,4,result.stdout+result.stderr)

if __name__=='__main__':unittest.main()