<?php
declare(strict_types=1);

// ISP_PPPOE_MULTI_COLLECTOR_V1
// Coleta por equipamento, preservando o legado Huawei.

chdir('/opt/observium');
$cli = true;
require_once '/opt/observium/includes/observium.inc.php';

$base = '/opt/observium/isp-pppoe';
$dbFile = $base . '/history-multi.sqlite';
$legacyFile = $base . '/history.sqlite';


// ISP_PPPOE_AUTO_DISCOVERY_V1
// Detecta candidatos Huawei/MikroTik cadastrados no Observium.
// Apenas consultas SNMP; nenhuma configuracao nos roteadores.

$devices = [];

$knownOids = [
    'mikrotik' => (string)($ispNative['pppoe_mikrotik_oid'] ?? '.1.3.6.1.4.1.9.9.150.1.1.1.0'),
    'huawei'   => (string)($ispNative['pppoe_huawei_oid'] ?? '.1.3.6.1.4.1.2011.5.2.1.14.1.2.0')
];

$allDevices = dbFetchRows('SELECT * FROM devices');

foreach ($allDevices as $candidate) {
    $id = (int)($candidate['device_id'] ?? 0);
    $host = (string)($candidate['hostname'] ?? '');
    $name = (string)($candidate['sysName'] ?? '');
    $os = strtolower((string)($candidate['os'] ?? ''));
    $object = (string)($candidate['sysObjectID'] ?? '');

    if ($id <= 0 || $host === '') {
        continue;
    }

    // Huawei principal: mantido no coletor original.
    if ($id === (int)($ispNative['legacy_device_id'] ?? 0) && $host === (string)($ispNative['legacy_hostname'] ?? '')) {
        continue;
    }

    $vendor = null;

    if (
        preg_match('/routeros|mikrotik/i', $os) ||
        preg_match('/(?:^|\.)14988(?:\.|$)/', $object)
    ) {
        $vendor = 'mikrotik';
    } elseif (
        preg_match('/huawei|vrp/i', $os) ||
        preg_match('/(?:^|\.)2011(?:\.|$)/', $object)
    ) {
        $vendor = 'huawei';
    }

    if ($vendor === null) {
        continue;
    }

    $oid = $knownOids[$vendor];

    $raw = trim(
        (string)snmp_get($candidate, $oid, '-OQv'),
        " \t\n\r\0\x0B\""
    );

    // Sem contador valido: nao incluir equipamento novo.
    if (!preg_match('/^\d+$/', $raw)) {
        continue;
    }

    $online = (int)$raw;

    $isBngName = (bool)preg_match(
        '/BNG|PPPOE|PPP-OE|BRAS|CONCENTRADOR/i',
        $name . ' ' . $host
    );

    // ISP_PPPOE_SAFE_DISCOVERY_V2
    // OID valido sozinho nao identifica um BNG.
    // Exige nome que indique funcao de concentrador.
    // Aceita zero sessoes para monitorar BNGs vazios.
    if (!$isBngName) {
        echo "IGNORADO: ID={$id} | {$name}" .
            " | nao identificado como BNG PPPoE\n";
        continue;
    }

    $devices[$id] = [
        'name' => $name !== '' ? $name : $host,
        'host' => $host,
        'oid' => $oid,
        'type' => $vendor . '-snmp'
    ];

    echo "DESCOBERTO: ID={$id}" .
        " | {$devices[$id]['name']}" .
        " | {$vendor}" .
        " | sessoes={$online}\n";
}


$lock = fopen($base . '/collect-multi.lock', 'c');

if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Outra coleta multiequipamentos em andamento.\n");
}

$db = null;
$failures = 0;

try {
    $db = new SQLite3($dbFile);
    $db->busyTimeout(5000);

    if (!$db->exec('
        CREATE TABLE IF NOT EXISTS devices (
            device_id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            hostname TEXT NOT NULL,
            source TEXT NOT NULL
        )
    ')) {
        throw new RuntimeException($db->lastErrorMsg());
    }

    if (!$db->exec('
        CREATE TABLE IF NOT EXISTS samples (
            device_id INTEGER NOT NULL,
            timestamp INTEGER NOT NULL,
            online INTEGER NOT NULL CHECK (online >= 0),
            PRIMARY KEY (device_id, timestamp)
        )
    ')) {
        throw new RuntimeException($db->lastErrorMsg());
    }

    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_samples_time
         ON samples(timestamp)'
    );

    $upsert = $db->prepare('
        INSERT OR REPLACE INTO devices
        (device_id, name, hostname, source)
        VALUES (:id, :name, :host, :source)
    ');

    if (!$upsert) {
        throw new RuntimeException($db->lastErrorMsg());
    }

    $saveDevice = static function (
        int $id,
        string $name,
        string $host,
        string $source
    ) use ($upsert): void {
        $upsert->reset();
        $upsert->clear();
        $upsert->bindValue(':id', $id, SQLITE3_INTEGER);
        $upsert->bindValue(':name', $name, SQLITE3_TEXT);
        $upsert->bindValue(':host', $host, SQLITE3_TEXT);
        $upsert->bindValue(':source', $source, SQLITE3_TEXT);

        $result = $upsert->execute();

        if (!$result) {
            throw new RuntimeException('Falha ao registrar equipamento');
        }

        $result->finalize();
    };

    // Mantem o historico Huawei existente.
    // O banco original permanece somente leitura.
    if (is_file($legacyFile) && (int)($ispNative['legacy_device_id'] ?? 0) > 0 && !empty($ispNative['legacy_hostname'])) {
        $saveDevice(
            (int)$ispNative['legacy_device_id'],
            (string)($ispNative['legacy_sysname'] ?? $ispNative['legacy_hostname']),
            (string)$ispNative['legacy_hostname'], 'huawei-snmp'
        );

        $legacy = new SQLite3(
            $legacyFile,
            SQLITE3_OPEN_READONLY
        );

        try {
            $query = $legacy->query(
                'SELECT timestamp, online
                 FROM samples ORDER BY timestamp'
            );

            if (!$query) {
                throw new RuntimeException(
                    'Falha ao consultar historico Huawei'
                );
            }

            $import = $db->prepare('
                INSERT OR IGNORE INTO samples
                (device_id, timestamp, online)
                VALUES (:legacy_id, :ts, :online)
            ');

            if (!$import) {
                throw new RuntimeException(
                    'Falha ao preparar importacao Huawei'
                );
            }

            $imported = 0;

            $db->exec('BEGIN IMMEDIATE');

            try {
                while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
                    $import->reset();
                    $import->clear();

                    $import->bindValue(':legacy_id', (int)$ispNative['legacy_device_id'], SQLITE3_INTEGER);
                    $import->bindValue(
                        ':ts',
                        (int)$row['timestamp'],
                        SQLITE3_INTEGER
                    );

                    $import->bindValue(
                        ':online',
                        (int)$row['online'],
                        SQLITE3_INTEGER
                    );

                    $result = $import->execute();

                    if (!$result) {
                        throw new RuntimeException(
                            'Falha ao importar amostra Huawei'
                        );
                    }

                    $result->finalize();
                    $imported++;
                }

                $db->exec('COMMIT');
            } catch (Throwable $e) {
                $db->exec('ROLLBACK');
                throw $e;
            }

            echo "HUAWEI: {$imported} amostras verificadas.\n";
        } finally {
            $legacy->close();
        }
    }

    // Coleta somente os MikroTiks previamente validados.
    foreach ($devices as $id => $cfg) {
        try {
            $device = dbFetchRow(
                'SELECT * FROM devices WHERE device_id = ?',
                [$id]
            );

            if (!$device) {
                throw new RuntimeException(
                    "Device ID {$id} nao encontrado"
                );
            }

            if ($device['hostname'] !== $cfg['host']) {
                throw new RuntimeException(
                    "IP diferente do esperado para ID {$id}"
                );
            }

            // ISP_PPPOE_AUTO_VENDOR_V1
            // Fabricante ja identificado na descoberta SNMP.
            $raw = trim(
                (string)snmp_get(
                    $device,
                    $cfg['oid'],
                    '-OQv'
                ),
                " \t\n\r\0\x0B\""
            );

            if (!preg_match('/^\d+$/', $raw)) {
                throw new RuntimeException(
                    'Contador SNMP invalido ou sem resposta'
                );
            }

            $online = (int)$raw;
            $timestamp = time();

            $saveDevice(
                $id,
                $cfg['name'],
                $cfg['host'],
                $cfg['type']
            );

            $stmt = $db->prepare('
                INSERT OR REPLACE INTO samples
                (device_id, timestamp, online)
                VALUES (:id, :ts, :online)
            ');

            if (!$stmt) {
                throw new RuntimeException(
                    'Falha ao preparar amostra'
                );
            }

            $stmt->bindValue(
                ':id', $id, SQLITE3_INTEGER
            );
            $stmt->bindValue(
                ':ts', $timestamp, SQLITE3_INTEGER
            );
            $stmt->bindValue(
                ':online', $online, SQLITE3_INTEGER
            );

            $result = $stmt->execute();

            if (!$result) {
                throw new RuntimeException(
                    'Falha ao gravar amostra'
                );
            }

            $result->finalize();

            echo date('Y-m-d H:i:s') .
                " | {$cfg['name']}" .
                " | AAA ativas: {$online}" .
                " | OK\n";

        } catch (Throwable $e) {
            $failures++;
            fwrite(
                STDERR,
                "ERRO {$cfg['name']}: " .
                $e->getMessage() . "\n"
            );
        }
    }

    // ISP_PPPOE_HISTORY_FOREVER_V1
    // Historico permanente: nenhuma exclusao por idade.
    // Novos concentradores preservam as amostras antigas.

} catch (Throwable $e) {
    $failures++;
    fwrite(
        STDERR,
        "ERRO GERAL: " . $e->getMessage() . "\n"
    );
} finally {
    if ($db instanceof SQLite3) {
        $db->close();
    }

    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($failures > 0 ? 1 : 0);