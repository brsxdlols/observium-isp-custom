<?php
declare(strict_types=1);

/* ISP_PPPOE_EXPORT_V1 */
if (!isset($ispNative) || !is_array($ispNative)) {
    $configFile = '/etc/observium-isp/native-collectors.php';
    if (!is_readable($configFile)) {
        fwrite(STDERR, "Configure native-collectors.php\n"); exit(2);
    }
    $ispNative = require $configFile;
}
if ((int)($ispNative['legacy_device_id'] ?? 0) <= 0 || empty($ispNative['legacy_hostname'])) {
    fwrite(STDERR, "Configure legacy_device_id e legacy_hostname\n"); exit(2);
}


$base = '/opt/observium/isp-pppoe';
$dbFile = $base . '/history.sqlite';
$jsonFile = $base . '/dashboard.json';

try {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);

    $latest = $db->querySingle(
        'SELECT timestamp, online FROM samples
         ORDER BY timestamp DESC LIMIT 1',
        true
    );

    if (!is_array($latest)) {
        throw new RuntimeException('Sem amostras');
    }

    $cutoff = time() - 43200;

    $stmt = $db->prepare(
        'SELECT timestamp, online FROM samples
         WHERE timestamp >= :cutoff
         ORDER BY timestamp ASC'
    );

    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if (!$result) {
        throw new RuntimeException('Consulta historica falhou');
    }

    $samples = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $samples[] = [
            'timestamp' => (int)$row['timestamp'],
            'online' => (int)$row['online']
        ];
    }

    $result->finalize();
    $stmt->close();
    $db->close();

    $payload = [
        'device_id' => (int)$ispNative['legacy_device_id'],
        'hostname' => (string)$ispNative['legacy_hostname'],
        'sysname' => (string)($ispNative['legacy_sysname'] ?? $ispNative['legacy_hostname']),
        'timestamp' => (int)$latest['timestamp'],
        'online' => (int)$latest['online'],
        'exported_at' => time(),
        'samples' => $samples
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException('Falha no JSON');
    }

    $tmp = tempnam($base, '.dashboard-');

    if ($tmp === false) {
        throw new RuntimeException('Falha no arquivo temporario');
    }

    if (file_put_contents($tmp, $json) === false) {
        @unlink($tmp);
        throw new RuntimeException('Falha ao gravar JSON');
    }

    chmod($tmp, 0644);

    if (!rename($tmp, $jsonFile)) {
        @unlink($tmp);
        throw new RuntimeException('Falha ao publicar JSON');
    }

    echo 'PPPoE: ' . $payload['online']
        . ' | Amostras 12h: ' . count($samples)
        . " | JSON OK\n";

} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO EXPORT: ' . $e->getMessage() . "\n");
    exit(1);
}