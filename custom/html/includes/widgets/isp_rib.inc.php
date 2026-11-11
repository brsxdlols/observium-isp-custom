<?php
/* ISP_RIB_PPPOE_12H_V1 */
/* ISP_RIB_PPPOE_AUTOHEIGHT_V1 */

if (($mod['widget_type'] ?? '') !== 'isp_rib') {
    return;
}

$tz = new DateTimeZone('America/Sao_Paulo');

$rib = null;
$ribTime = null;
$rib6 = null;
$fib4 = null;
$fib6 = null;

$ribFile = '/opt/observium/isp-rib/rib.json';

if (is_readable($ribFile)) {
    $data = json_decode((string)file_get_contents($ribFile), true);

    if (
        is_array($data) &&
        isset($data['ipv4_entries'], $data['timestamp']) &&
        is_numeric($data['ipv4_entries']) &&
        is_numeric($data['timestamp']) &&
        (int)$data['ipv4_entries'] >= 0
    ) {
        $rib = (int)$data['ipv4_entries'];
        $ribTime = (int)$data['timestamp'];
        // ISP_RIB_IPV6_FIB_CARDS_V1
        // Optional counters must be collected separately; never infer FIB from RIB.
        foreach ([
            'ipv6_entries' => 'rib6',
            'fib_ipv4_entries' => 'fib4',
            'fib_ipv6_entries' => 'fib6'
        ] as $key => $metric) {
            if (isset($data[$key]) &&
                is_numeric($data[$key]) &&
                (float)$data[$key] >= 0) {
                $metric = (int)$data[$key];
            }
        }
    }
}

$pppoe = null;
$pppoeTime = null;
$samples = [];

$jsonFile = '/opt/observium/isp-pppoe/dashboard.json';

if (is_readable($jsonFile)) {
    $data = json_decode((string)file_get_contents($jsonFile), true);

    // ISP_PPPOE_LEGACY_SOURCE_FILTER_V2:
    // The legacy dashboard.json belongs to ONE configured concentrator.
    // Reject it by default to avoid showing an unrelated NAS/BNG's count.
    // The SQLite multi-concentrator consolidation below remains independent.
    $sourceDeviceId = $config['isp_pppoe_legacy_device_id'] ?? null;
    $sourceHostname = $config['isp_pppoe_legacy_hostname'] ?? null;
    $legacySourceConfigured =
        (is_int($sourceDeviceId) || (is_string($sourceDeviceId) && ctype_digit($sourceDeviceId))) &&
        (int)$sourceDeviceId > 0 &&
        is_string($sourceHostname) &&
        trim($sourceHostname) !== '';

    if (
        $legacySourceConfigured &&
        is_array($data) &&
        isset($data['device_id'], $data['hostname']) &&
        (string)$data['device_id'] === (string)$sourceDeviceId &&
        (string)$data['hostname'] === trim($sourceHostname) &&
        isset($data['timestamp'], $data['online']) &&
        is_numeric($data['timestamp']) &&
        is_numeric($data['online']) &&
        (int)$data['online'] >= 0
    ) {
        $pppoe = (int)$data['online'];
        $pppoeTime = (int)$data['timestamp'];

        if (isset($data['samples']) && is_array($data['samples'])) {
            foreach ($data['samples'] as $sample) {
                if (
                    is_array($sample) &&
                    isset($sample['timestamp'], $sample['online']) &&
                    is_numeric($sample['timestamp']) &&
                    is_numeric($sample['online'])
                ) {
                    $samples[] = [
                        'timestamp' => (int)$sample['timestamp'],
                        'online' => (int)$sample['online']
                    ];
                }
            }
        }
    }
}


// ISP_PPPOE_DASHBOARD_TOTAL_MULTI_V1
// Soma apenas concentradores com coleta recente.

$pppoeTotal = null;
$pppoeTotalTime = null;
$pppoeTotalDevice = 'Concentradores PPPoE';
// ISP_PPPOE_DASHBOARD_PARTIAL_V1
$pppoeTotalPartial = false;

$multiFile = '/opt/observium/isp-pppoe/history-multi.sqlite';

if (is_readable($multiFile)) {
    $multiDb = null;

    try {
        $multiDb = new SQLite3(
            $multiFile,
            SQLITE3_OPEN_READONLY
        );

        $multiDb->busyTimeout(2000);

        $result = $multiDb->query(
            'SELECT
                d.device_id,
                (SELECT s.online FROM samples s
                 WHERE s.device_id = d.device_id
                 ORDER BY s.timestamp DESC LIMIT 1) AS online,
                (SELECT s.timestamp FROM samples s
                 WHERE s.device_id = d.device_id
                 ORDER BY s.timestamp DESC LIMIT 1) AS ts
             FROM devices d'
        );

        if (!$result) {
            throw new RuntimeException(
                'Falha na consulta dos concentradores'
            );
        }

        $sum = 0;
        $totalDevices = 0;
        $freshDevices = 0;
        $oldestTime = time();

        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            $totalDevices++;

            if ($r['online'] === null ||
                $r['ts'] === null) {
                continue;
            }

            $ts = (int)$r['ts'];
            $age = time() - $ts;

            if ($age < -120 || $age > 900) {
                continue;
            }

            $sum += (int)$r['online'];
            $freshDevices++;
            $oldestTime = min($oldestTime, $ts);
        }

        $result->finalize();

        $pppoeTotalDevice =
            $freshDevices . '/' . $totalDevices .
            ' concentradores atualizados';

        // Continua exibindo a soma dos BNGs atualizados.
        // Identifica quando o consolidado esta parcial.
        if ($freshDevices > 0) {
            $pppoeTotal = $sum;
            $pppoeTotalTime = $oldestTime;

            if ($freshDevices < $totalDevices) {
                $pppoeTotalPartial = true;
                $pppoeTotalDevice .= ' (PARCIAL)';
            }
        }

    } catch (Throwable $e) {
        error_log(
            'ISP PPPoE total: ' . $e->getMessage()
        );
        $pppoeTotalDevice = 'Coleta indisponivel';
    } finally {
        if ($multiDb instanceof SQLite3) {
            $multiDb->close();
        }
    }
}

$format = static function ($value) {
    return $value === null
        ? '—'
        : number_format($value, 0, ',', '.');
};

$status = static function ($timestamp) {
    if ($timestamp === null) {
        return ['SEM DADOS', '#dc3545'];
    }

    $age = time() - $timestamp;

    if ($age >= -120 && $age <= 900) {
        return ['ATUALIZADO', '#198754'];
    }

    return ['DESATUALIZADO', '#b9770e'];
};

$ribStatus = $status($ribTime);
$rib6Status = $status($rib6 === null ? null : $ribTime);
$fib4Status = $status($fib4 === null ? null : $ribTime);
$fib6Status = $status($fib6 === null ? null : $ribTime);
$pppoeStatus = $status($pppoeTime);

echo '<div class="box box-solid isp-rib-pppoe-box" style="margin:0;overflow:visible;height:auto;min-height:0;border:1px solid #dce3e8;border-radius:6px;padding-bottom:8px;box-sizing:border-box">';
echo '<div class="box-header" style="padding-bottom:4px">';
echo '<h3 class="box-title">RIB IPv4 / PPPoE — Geral</h3>';
echo '</div>';

echo '<div class="box-content" style="padding:10px 12px">';

echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px">';

foreach ([
    ['Rotas IPv4 — RIB', $rib, $ribStatus, ($config['isp_rib_router_label'] ?? 'Roteador BGP')],
    ['Rotas IPv6 — RIB', $rib6, $rib6Status, ($config['isp_rib_router_label'] ?? 'Roteador BGP')],
    ['Rotas IPv4 — FIB', $fib4, $fib4Status, ($config['isp_rib_router_label'] ?? 'Roteador BGP')],
    ['Rotas IPv6 — FIB', $fib6, $fib6Status, ($config['isp_rib_router_label'] ?? 'Roteador BGP')],
    ['PPPoE totais', $pppoeTotal, ($pppoeTotalPartial ? ['PARCIAL', '#b9770e'] : $status($pppoeTotalTime)), $pppoeTotalDevice]
] as $item) {

    [$label, $value, $state, $device] = $item;

    // ISP_PPPOE_MULTI_CARD_LINK_V1
    $ispPppoeCard = ($label === 'PPPoE totais');

    if ($ispPppoeCard) {
        echo '<a href="?page=pppoe" target="_blank" rel="noopener noreferrer" '
           . 'title="Abrir concentradores PPPoE" '
           . 'style="display:block;color:inherit;text-decoration:none;min-width:0">';
    }

    echo '<div style="min-width:0;border:1px solid #e2e8f0;border-radius:5px;padding:10px;cursor:'
        . ($ispPppoeCard ? 'pointer' : 'default') . '">';
    echo '<div style="font-size:12px;color:#64748b">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';

    echo '<div style="font-size:26px;font-weight:700;margin:7px 0;color:'
        . $state[1] . '">'
        . htmlspecialchars($format($value), ENT_QUOTES, 'UTF-8')
        . '</div>';

    echo '<div style="font-size:10px;font-weight:600;color:'
        . $state[1] . '">' . $state[0] . '</div>';

    echo '<div style="font-size:10px;color:#64748b;margin-top:5px">'
        . htmlspecialchars($device, ENT_QUOTES, 'UTF-8') . '</div>';

    echo '</div>';

    if ($ispPppoeCard) {
        echo '</a>';
    }
}

echo '</div>';

echo '<div style="margin-top:13px;border:1px solid #dce3e8;border-radius:6px;padding:10px;box-sizing:border-box">';
echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center">';
echo '<strong style="font-size:12px">PPPoE BNG-FLYNET — últimas 12 horas</strong>';
echo '<span style="font-size:10px;color:#64748b">Coleta: 5 min</span>';
echo '</div>';

$now = time();
$start = $now - 43200;

$samples = array_values(array_filter(
    $samples,
    static function ($row) use ($start, $now) {
        return $row['timestamp'] >= $start
            && $row['timestamp'] <= $now + 120
            && $row['online'] >= 0;
    }
));

if (count($samples) > 0) {

    $values = array_column($samples, 'online');

    $min = min($values);
    $max = max($values);

    /* ISP_PPPOE_DYNAMIC_SCALE_20_V1 */
    $pad = max(20, (int)ceil(max(1, $max - $min) * 0.15));

    $low = max(0, $min - $pad);
    $high = $max + $pad;
    $range = max(1, $high - $low);

    $w = 400;
    $h = 240;
    $left = 43;
    $right = 12;
    $top = 12;
    $bottom = 26;

    $pw = $w - $left - $right;
    $ph = $h - $top - $bottom;

    $points = [];
    $hoverSamples = [];

    foreach ($samples as $row) {
        $x = $left + (($row['timestamp'] - $start) / 43200) * $pw;
        $y = $top + (1 - (($row['online'] - $low) / $range)) * $ph;

        $px = round(max($left, min($w - $right, $x)), 2);
        $py = round($y, 2);

        $points[] = $px . ',' . $py;

        $hoverSamples[] = [
            'x' => $px,
            'y' => $py,
            'timestamp' => (int)$row['timestamp'],
            'online' => (int)$row['online']
        ];
    }

    echo '<svg viewBox="0 0 400 240" role="img" aria-label="Histórico PPPoE de 12 horas" style="display:block;width:100%;height:auto;max-height:260px;margin-top:8px">';

    for ($i = 0; $i <= 4; $i++) {
        $y = $top + ($i / 4) * $ph;
        $value = (int)round($high - ($i / 4) * $range);

        echo '<line x1="' . $left . '" y1="' . $y
            . '" x2="' . ($w - $right) . '" y2="' . $y
            . '" stroke="#e2e8f0" stroke-width="1"/>';

        echo '<text x="38" y="' . ($y + 4)
            . '" text-anchor="end" font-size="10" fill="#64748b">'
            . $value . '</text>';
    }

    if (count($points) > 1) {
        $firstPoint = explode(',', $points[0]);
        $lastPoint = explode(',', end($points));
        $baseY = $top + $ph;

        $areaPoints = implode(' ', $points)
            . ' ' . $lastPoint[0] . ',' . $baseY
            . ' ' . $firstPoint[0] . ',' . $baseY;

        echo '<defs>';
        echo '<linearGradient id="isp-pppoe-area-gradient" x1="0" y1="0" x2="0" y2="1">';
        echo '<stop offset="0%" stop-color="#2563eb" stop-opacity="0.25"/>';
        echo '<stop offset="100%" stop-color="#2563eb" stop-opacity="0.01"/>';
        echo '</linearGradient>';
        echo '</defs>';

        echo '<polygon points="' . $areaPoints
            . '" fill="url(#isp-pppoe-area-gradient)" stroke="none"/>';

        echo '<polyline points="' . implode(' ', $points)
            . '" fill="none" stroke="#2563eb" stroke-width="2.5"'
            . ' stroke-linejoin="round" stroke-linecap="round"/>';
    } else {
        $xy = explode(',', $points[0]);
        echo '<circle cx="' . $xy[0] . '" cy="' . $xy[1]
            . '" r="4" fill="#2563eb"/>';
    }


    // ISP_PPPOE_MINI_ALERT_V5
    // Vermelho = queda brusca de sessoes.
    // Verde = aumento brusco de sessoes.
    // SVG pequeno, sem caixas e sem interferir no hover.

    $ispAlertMinSessions = 50;
    $ispAlertMinPercent = 5.0;
    $ispAlertMaxGap = 660;

    for ($j = 1; $j < count($hoverSamples); $j++) {

        $prev = $hoverSamples[$j - 1];
        $curr = $hoverSamples[$j];

        $gap = $curr['timestamp'] - $prev['timestamp'];

        if ($gap <= 0 || $gap > $ispAlertMaxGap) {
            continue;
        }

        $delta = $curr['online'] - $prev['online'];

        $percent = abs($delta) * 100 /
            max(1, $prev['online']);

        if (abs($delta) < $ispAlertMinSessions ||
            $percent < $ispAlertMinPercent) {
            continue;
        }

        $ax = round(max(
            $left + 7,
            min($w - $right - 7, $curr['x'])
        ), 2);

        $ay = round(max(
            $top + 9,
            min($top + $ph - 9, $curr['y'] - 14)
        ), 2);

        echo '<g pointer-events="none">';

        if ($delta > 0) {
            // Setinha verde de alta.
            echo '<circle cx="' . $ax .
                '" cy="' . $ay .
                '" r="7" fill="#f0fdf4"'
                . ' stroke="#16a34a" stroke-width="1.2"/>';

            echo '<path d="M ' . ($ax - 3) . ' ' . ($ay + 1)
                . ' L ' . $ax . ' ' . ($ay - 3)
                . ' L ' . ($ax + 3) . ' ' . ($ay + 1)
                . ' M ' . $ax . ' ' . ($ay - 3)
                . ' L ' . $ax . ' ' . ($ay + 4)
                . '" fill="none" stroke="#16a34a"'
                . ' stroke-width="1.5"'
                . ' stroke-linecap="round"'
                . ' stroke-linejoin="round"/>';
        } else {
            // ISP_PPPOE_MINI_DOWN_ARROW_V1
            // Queda brusca = seta vermelha para baixo.
            echo '<circle cx="' . $ax .
                '" cy="' . $ay .
                '" r="7" fill="#fff1f2"'
                . ' stroke="#dc3545"'
                . ' stroke-width="1.2"/>';

            echo '<path d="M ' . $ax . ' ' . ($ay - 4)
                . ' L ' . $ax . ' ' . ($ay + 4)
                . ' M ' . ($ax - 3) . ' ' . ($ay + 1)
                . ' L ' . $ax . ' ' . ($ay + 4)
                . ' L ' . ($ax + 3) . ' ' . ($ay + 1)
                . '" fill="none"'
                . ' stroke="#dc3545"'
                . ' stroke-width="1.5"'
                . ' stroke-linecap="round"'
                . ' stroke-linejoin="round"/>';
        }

        echo '</g>';
    }

        for ($i = 0; $i <= 4; $i++) {
        $x = $left + ($i / 4) * $pw;
        $t = $start + ($i / 4) * 43200;

        $label = (new DateTimeImmutable('@' . (int)$t))
            ->setTimezone($tz)->format('H:i');

        echo '<text x="' . round($x, 2)
            . '" y="' . ($top + $ph + 19) .
            '" text-anchor="middle" font-size="10" fill="#64748b">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</text>';
    }


    // ISP_PPPOE_HOVER_HEIGHT_V1
    // Faixas transparentes para consultar a amostra mais proxima.
    // O tooltip usa exclusivamente os valores reais coletados.
    $sampleCount = count($hoverSamples);

    foreach ($hoverSamples as $i => $sample) {
        $x0 = $i === 0
            ? $left
            : ($hoverSamples[$i - 1]['x'] + $sample['x']) / 2;

        $x1 = $i === $sampleCount - 1
            ? ($w - $right)
            : ($sample['x'] + $hoverSamples[$i + 1]['x']) / 2;

        $x0 = max($left, min($w - $right, $x0));
        $x1 = max($x0, min($w - $right, $x1));

        $date = (new DateTimeImmutable(
            '@' . $sample['timestamp']
        ))->setTimezone($tz)->format('d/m/Y H:i');

        $tooltip = $date . ' — ' .
            number_format($sample['online'], 0, ',', '.') .
            ' PPPoE conectados';

        echo '<rect x="' . round($x0, 2) .
             '" y="' . $top .
             '" width="' . round($x1 - $x0, 2) .
             '" height="' . $ph .
             '" fill="transparent" pointer-events="all"'
             . ' data-isp-pppoe-tooltip="'
             . htmlspecialchars(
                 $tooltip,
                 ENT_QUOTES | ENT_SUBSTITUTE,
                 'UTF-8'
               )
             . '">';

        echo '<title>' .
             htmlspecialchars(
                 $tooltip,
                 ENT_QUOTES | ENT_SUBSTITUTE,
                 'UTF-8'
             ) .
             '</title></rect>';
    }

    echo '</svg>';

    echo '<div style="display:flex;justify-content:space-between;gap:8px;font-size:10px;color:#64748b">';
    echo '<span>Mín: ' . $format($min) . '</span>';
    echo '<span>Máx: ' . $format($max) . '</span>';
    echo '<span>Amostras: ' . count($samples) . '</span>';
    echo '</div>';

    if (count($samples) < 2) {
        echo '<div style="font-size:10px;color:#64748b;margin-top:5px">';
        echo 'Aguardando novas coletas para formar a linha do gráfico.';
        echo '</div>';
    }

} else {
    echo '<div style="padding:15px;text-align:center;color:#64748b;font-size:11px">';
    echo 'Aguardando histórico PPPoE.';
    echo '</div>';
}

echo '</div>';
echo '</div></div>';
