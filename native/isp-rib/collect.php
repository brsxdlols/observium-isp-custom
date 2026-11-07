<?php
date_default_timezone_set('America/Sao_Paulo');

chdir('/opt/observium');

$cli = TRUE;
require_once '/opt/observium/includes/observium.inc.php';
// ISP_NATIVE_CONFIG_V1 - local private settings, never publish credentials.
$ispNativeConfig = '/etc/observium-isp/native-collectors.php';
if (!is_file($ispNativeConfig) || !is_readable($ispNativeConfig)) {
    fwrite(STDERR, "Configure /etc/observium-isp/native-collectors.php antes da coleta\n");
    exit(2);
}
$ispNative = require $ispNativeConfig;
if (!is_array($ispNative)) {
    fwrite(STDERR, "Configuracao ISP invalida\n");
    exit(2);
}


$device = dbFetchRow(
    "SELECT * FROM devices WHERE device_id = ?",
    [(int)($ispNative['rib_device_id'] ?? 0)]
);

if ((int)($ispNative['rib_device_id'] ?? 0) <= 0) {
    fwrite(STDERR, "Configure rib_device_id\n"); exit(2);
}
if (!$device) {
    fwrite(STDERR, "Dispositivo nao encontrado\n");
    exit(1);
}

$oid = (string)($ispNative['rib_oid'] ?? '.1.3.6.1.2.1.4.24.3.0');
$value = snmp_get($device, $oid, '-OQv');

if (empty($GLOBALS['snmp_status']) ||
    !is_numeric(trim((string)$value))) {
    fwrite(STDERR, "Falha na coleta SNMP\n");
    exit(1);
}

$value = (int) trim($value);

if ($value < 0) {
    fwrite(STDERR, "Valor SNMP invalido\n");
    exit(1);
}

$data = [
    'device_id' => (int)$ispNative['rib_device_id'],
    'hostname' => $device['hostname'],
    'ipv4_entries' => $value,
    'oid' => $oid,
    'collected_at' => date('c'),
    'timestamp' => time()
];

$file = '/opt/observium/isp-rib/rib.json';
$tmp = tempnam(dirname($file), '.rib-');

if ($tmp === false) {
    fwrite(STDERR, "Falha ao criar arquivo temporario\n");
    exit(1);
}

chmod($tmp, 0644);

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($json === false || file_put_contents($tmp, $json) === false) {
    @unlink($tmp);
    fwrite(STDERR, "Falha ao gravar cache\n");
    exit(1);
}

if (!rename($tmp, $file)) {
    @unlink($tmp);
    fwrite(STDERR, "Falha ao publicar cache\n");
    exit(1);
}

echo "IPv4 SNMP: " . number_format($value, 0, ',', '.') . "\n";
echo "Coleta: " . $data['collected_at'] . "\n";