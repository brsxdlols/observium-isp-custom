#!/usr/bin/env python3
"""Mock-only smoke tests for dashboard bootstrap. Never touches a real Observium DB."""
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / 'dashboard' / 'seed-dashboard.php'

MOCK = r'''<?php
$GLOBALS['dash'] = [];
$GLOBALS['widgets'] = [];
$GLOBALS['queries'] = [];
function dbFetchRows($sql,$args=[]) {
 if (str_contains($sql,'SHOW COLUMNS')) {
  if (str_contains($sql,'`dashboards`')) return array_map(fn($n)=>['Field'=>$n],['dash_id','dash_name','descr','is_public','dash_order','slug']);
  if (str_contains($sql,'`dash_widgets`')) return array_map(fn($n)=>['Field'=>$n],['dash_id','widget_type','widget_config','x','y','width','height']);
 }
 if (str_contains($sql,'SELECT `dash_id`')) {
  return count($GLOBALS['dash']) ? [['dash_id'=>1]] : [];
 }
 return [];
}
function dbFetchCell($sql,$args=[]) { return count($GLOBALS['widgets']); }
function dbInsert($data,$table) {
 if ($table==='dashboards') { $GLOBALS['dash'][]=$data;return 1; }
 $GLOBALS['widgets'][]=$data;return count($GLOBALS['widgets']);
}
function dbQuery($sql) {$GLOBALS['queries'][]=$sql;return true;}
'''

with tempfile.TemporaryDirectory() as tmp:
    temp=Path(tmp)
    code=PHP.read_text().replace("require_once '/opt/observium/includes/observium.inc.php';",'')
    code=code.replace("is_readable('/opt/observium/html/includes/widgets/'.$nameWidget.'.inc.php')",'true')
    for mode in ['--check','--apply']:
        # Tests are independent processes, so the mock database starts empty each time.
        runner = temp / f'run_{mode[2:]}.php'
        runner.write_text(MOCK+'\n?>\n'+code+'\n')
        run=subprocess.run(['php',str(runner),mode],capture_output=True,text=True)
        assert run.returncode == 0, (run.stdout,run.stderr)
        assert ('CHECK OK:' if mode=='--check' else 'CRIADO:') in run.stdout, run.stdout
        print(f'OK: simulated {mode}')
print('Dashboard seed smoke tests passed.')