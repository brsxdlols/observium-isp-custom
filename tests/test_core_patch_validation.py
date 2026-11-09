import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from zipfile import ZipFile
import difflib
import hashlib

P = Path(__file__).with_name('validate-core-patches.py')
spec = importlib.util.spec_from_file_location('corecheck', P)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class TestCorePatchValidation(unittest.TestCase):
    def test_valid_and_reject_changed_destination(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            root = base / 'observium'
            records=[]
            patch_data={}
            for rel in sorted(mod.TARGETS):
                a=b'old line\n'
                b=b'changed line\n'
                target=root/rel
                target.parent.mkdir(parents=True,exist_ok=True)
                target.write_bytes(a)
                records.append({'path':rel,'changed':True,'baseline_sha256':hashlib.sha256(a).hexdigest(),'customized_sha256':hashlib.sha256(b).hexdigest()})
                patch_data[mod.PATCH_NAMES[rel]]=''.join(difflib.unified_diff(a.decode().splitlines(True),b.decode().splitlines(True),fromfile='a/'+rel,tofile='b/'+rel))
            records.extend([{'path':x,'changed':False} for x in ('html/includes/dashboard-generate.inc.php','html/pages/dashboard.inc.php')])
            archive=base/'patch.zip'
            with ZipFile(archive,'w') as z:
                z.writestr('manifest.json',json.dumps({'format':'observium-isp-core-review-v1','files':records}))
                for name,content in patch_data.items():z.writestr(name,content)
            mod.validate(archive,root)
            self.assertEqual((root/'html/js/dashboard.js').read_bytes(),b'old line\n')
            (root/'html/js/dashboard.js').write_bytes(b'tampered\n')
            with self.assertRaisesRegex(ValueError,'HASH DIVERGENTE'):
                mod.validate(archive,root)
    def test_extra_header_rejected(self):
        with self.assertRaisesRegex(ValueError,'cabeçalho'):
            mod.check_patch_text('--- a/html/js/dashboard.js\n+++ b/html/js/dashboard.js\n@@ -1 +1 @@\n-x\n+y\n--- a/etc/passwd\n+++ b/etc/passwd\n', 'html/js/dashboard.js')
if __name__ == '__main__':unittest.main()