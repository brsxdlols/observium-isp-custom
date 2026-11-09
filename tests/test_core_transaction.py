import difflib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from zipfile import ZipFile

HERE = Path(__file__).parent
spec = importlib.util.spec_from_file_location('transaction', HERE.parent / 'core' / 'test-core-transaction.py')
trans = importlib.util.module_from_spec(spec)
spec.loader.exec_module(trans)

class TransactionTests(unittest.TestCase):
    def test_success_and_injected_rollback(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            archive = root / 'baseline.tar.gz'
            bundle = root / 'bundle.zip'
            # The real repo validator exports load_bundle; test its documented API.
            validator = root / 'validator.py'
            validator.write_text('''import json,zipfile\ndef load_bundle(path):\n with zipfile.ZipFile(path) as z:\n  return {x["path"]:x for x in json.loads(z.read("manifest.json"))["files"]}, {name: z.read(name.replace("/","__")+".patch") for name in ("html/includes/navbar.inc.php", "html/ajax/widget.php", "html/js/dashboard.js")}\n''')
            records = []
            patches = {}
            with tarfile.open(archive, 'w:gz') as tar:
                for rel in trans.TARGETS:
                    a = b'hello\n'
                    b = b'world\n'
                    records.append({'path': rel, 'changed':True, 'baseline_sha256':trans.sha(a), 'customized_sha256':trans.sha(b)})
                    patches[rel.replace('/', '__')+'.patch'] = ''.join(difflib.unified_diff(a.decode().splitlines(True), b.decode().splitlines(True), 'a/'+rel, 'b/'+rel))
                    member = tarfile.TarInfo('observium/'+rel)
                    member.size = len(a)
                    tar.addfile(member, io.BytesIO(a))
                content = b"<?php define('OBSERVIUM_VERSION', '26.1.14545');\n"
                member = tarfile.TarInfo(trans.VERSION_REL)
                member.size = len(content)
                tar.addfile(member, io.BytesIO(content))
            with ZipFile(bundle,'w') as z:
                z.writestr('manifest.json', json.dumps({'format':'observium-isp-core-review-v1','files':records}))
                for name, data in patches.items():
                    z.writestr(name,data)
            self.assertEqual(trans.stage(archive,bundle,'26.1.14545',validator),'commit')
            self.assertEqual(trans.stage(archive,bundle,'26.1.14545',validator,inject_failure_at=2),'rollback')
            with self.assertRaisesRegex(ValueError, 'Versao divergente'):
                trans.stage(archive,bundle,'25.1.1',validator)
            self.assertEqual(list(root.glob('obs-isp-transaction-*')), [])

if __name__=='__main__':unittest.main()