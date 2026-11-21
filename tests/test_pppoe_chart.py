#!/usr/bin/env python3
"""Unit tests for the safe PPPoE chart migration (no Observium required)."""
import importlib.util
from pathlib import Path
import unittest

PATH = Path(__file__).resolve().parents[1] / "tools" / "upgrade-pppoe-chart.py"
spec = importlib.util.spec_from_file_location("upgrade_pppoe_chart", PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

OLD = b"""<?php
// ISP_PPPOE_CONSOLIDATION_CARRY_V2
// ISP_PPPOE_TRANSITION_BRIDGE_V2
$periods = [
    '12h' => 43200,
    '24h' => 86400,
    '7d' => 604800,
    '30d' => 2592000
];
$bucketSeconds = [
    '12h' => 300,
    '24h' => 300,
    '7d' => 1800,
    '30d' => 7200
][$period];
?>
<script>
var normalGapLimit =
                ispMultiBucket * 2.5;
var bw = label === 'Sem coleta' ? 94 : (label === 'Retorno' ? 78 : 67);
ispAlertIcon(x,y,delta > 0 ? 'Alta' : 'Queda');
</script>
"""

class PPPoEChartMigration(unittest.TestCase):
    def test_migrates_all_four_features(self):
        new = module.transform(OLD)
        for key in (b"'30min'", b"'1h'", b"'3h'", b"'6h'"):
            self.assertEqual(new.count(key), 2)
        self.assertIn(b"Math.max(\n                750,", new)
        self.assertIn("Recuperação".encode(), new)
        self.assertIn(b"? 116 :", new)
        self.assertNotIn(b"'Alta'", new)

    def test_idempotent(self):
        updated = module.transform(OLD)
        self.assertEqual(updated, module.transform(updated))

    def test_preserves_unrelated_content(self):
        content = OLD + b"<!-- unrelated custom note -->"
        self.assertTrue(module.transform(content).endswith(b"<!-- unrelated custom note -->"))

    def test_refuses_unknown_bucket_data(self):
        altered = OLD.replace(b"'12h' => 300", b"'12h' => 42")
        with self.assertRaises(ValueError):
            module.transform(altered)

    def test_refuses_missing_signatures(self):
        with self.assertRaises(ValueError):
            module.transform(OLD.replace(b"ISP_PPPOE_TRANSITION_BRIDGE_V2", b"OTHER"))

    def test_supports_prior_volta_label(self):
        changed = OLD.replace(b"'Alta'", b"'Volta'")
        self.assertIn("Recuperação".encode(), module.transform(changed))

if __name__ == "__main__":
    unittest.main()
