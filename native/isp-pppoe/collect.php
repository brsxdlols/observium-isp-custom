<?php

declare(strict_types=1);

chdir('/opt/observium');

$cli = true;

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


$base = '/opt/observium/isp-pppoe';
$dbFile = $base . '/history.sqlite';
$lockFile = $base . '/collect.lock';

$lock = fopen($lockFile, 'c');

if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Outra coleta em andamento.\n");
}

try {
    $device = dbFetchRow(
        "SELECT * FROM devices WHERE device_id = ?",
        [(int)($ispNative['legacy_device_id'] ?? 0)]
    );

    if ((int)($ispNative['legacy_device_id'] ?? 0) <= 0 || !is_string($ispNative['legacy_hostname'] ?? null) || trim($ispNative['legacy_hostname']) === '') {
        throw new RuntimeException('Configure legacy_device_id e legacy_hostname');
    }
    if (!$device) {
        throw new RuntimeException('Device ID 2 nao encontrado.');
    }

    if ($device['hostname'] !== $ispNative['legacy_hostname']) {
        throw new RuntimeException(
            'IP inesperado: ' . $device['hostname']
        );
    }

    $sysName = trim((string) snmp_get(
        $device,
        '.1.3.6.1.2.1.1.5.0',
        '-OQv'
    ), " \t\n\r\0\x0B\"");

    if (!empty($ispNative['legacy_sysname']) && strcasecmp($sysName, (string)$ispNative['legacy_sysname']) !== 0) {
        throw new RuntimeException(
            'sysName inesperado: ' . $sysName
        );
    }

    $oid = (string)($ispNative['pppoe_huawei_oid'] ?? '.1.3.6.1.4.1.2011.5.2.1.14.1.2.0');

    $raw = trim((string) snmp_get(
        $device,
        $oid,
        '-OQv'
    ), " \t\n\r\0\x0B\"");

    if (!preg_match('/^\d+$/', $raw)) {
        throw new RuntimeException(
            'Resposta PPPoE invalida: ' . $raw
        );
    }

    $online = (int) $raw;
    $timestamp = time();

    $db = new SQLite3($dbFile);

    $db->busyTimeout(5000);

    $db->exec(
        'CREATE TABLE IF NOT EXISTS samples (
            timestamp INTEGER PRIMARY KEY,
            online INTEGER NOT NULL
        )'
    );

    $stmt = $db->prepare(
        'INSERT OR REPLACE INTO samples
         (timestamp, online)
         VALUES (:timestamp, :online)'
    );

    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar INSERT.');
    }

    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_INTEGER);
    $stmt->bindValue(':online', $online, SQLITE3_INTEGER);

    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao salvar amostra.');
    }

    $stmt->close();

    // Manter 30 dias de historico.
    $cutoff = $timestamp - (30 * 86400);

    $db->exec(
        'DELETE FROM samples WHERE timestamp < ' . $cutoff
    );

    $db->close();

    echo date('Y-m-d H:i:s')
        . " | " . ($ispNative['legacy_sysname'] ?? $device['hostname'])
        . " | PPPoE Online: $online"
        . " | OK\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        date('Y-m-d H:i:s')
        . ' | ERRO: '
        . $e->getMessage()
        . "\n"
    );

    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

/* ISP_PPPOE_EXPORT_HOOK_V1 */
if (is_file('/opt/observium/isp-pppoe/export.php')) {
    require '/opt/observium/isp-pppoe/export.php';
}