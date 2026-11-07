<?php

// ISP_WIDGET_TIMEZONE_BR_V1
// Converte apenas a exibicao; nao altera timestamps de coleta.
$ispLocalTime = static function ($timestamp) {
    return (new DateTimeImmutable('@' . (int)$timestamp))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
        ->format('d/m/Y H:i:s');
};

if (($mod['widget_type'] ?? '') !== 'isp_bgp') return;

$e = static fn($s) =>
    htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$rows = dbFetchRows("
    SELECT
        d.hostname,
        d.last_polled,
        b.device_id,
        b.bgpPeer_id,
        b.bgpPeerIdentifier,
        b.bgpPeerRemoteAddr,
        b.bgpPeerRemoteAs,
        b.bgpPeerState,
        b.bgpPeerAdminStatus,
        b.bgpPeer_polled,
        b.bgpPeerFsmEstablishedTime
    FROM bgpPeers b
    JOIN devices d ON d.device_id=b.device_id
    ORDER BY d.hostname,b.bgpPeerRemoteAddr
");

// ISP_BGP_PREFIXOS_POR_ID_V2
// Prefixos aceitos por sessao BGP e familia IPv4 unicast.
// Dados provenientes do poller existente do Observium.
$prefixesIPv4 = [];

try {
    $tables = dbFetchRows("SHOW TABLES LIKE 'bgpPeers_cbgp'");

    if ($tables) {
        $columns = dbFetchRows("SHOW COLUMNS FROM bgpPeers_cbgp");
        $names = [];

        foreach ((array)$columns as $column) {
            $names[] = $column['Field'] ?? '';
        }

        $needed = [
            'device_id', 'bgpPeer_id',
            'afi', 'safi', 'AcceptedPrefixes'
        ];

        if (!array_diff($needed, $names)) {
            $prefixRows = dbFetchRows("
                SELECT device_id, bgpPeer_id,
                       afi, safi, AcceptedPrefixes
                FROM bgpPeers_cbgp
                WHERE afi = 'ipv4' AND safi = 'unicast'
            ");

            foreach ((array)$prefixRows as $r) {
                if (
                    !isset($r['AcceptedPrefixes']) ||
                    !is_numeric($r['AcceptedPrefixes']) ||
                    (int)$r['AcceptedPrefixes'] < 0
                ) {
                    continue;
                }

                $key = (string)$r['device_id'] . '|' .
                       (string)$r['bgpPeer_id'];

                $prefixesIPv4[$key] =
                    (int)$r['AcceptedPrefixes'];
            }
        }
    }
} catch (Throwable $ex) {
    $prefixesIPv4 = [];
}

$items = [];
$established = 0;
$problems = 0;

foreach ($rows as $p) {
    $age = (int)$p['bgpPeer_polled'] > 0
        ? time() - (int)$p['bgpPeer_polled']
        : PHP_INT_MAX;

    $dev = strtotime((string)$p['last_polled']);
    $devAge = $dev ? time() - $dev : PHP_INT_MAX;

    $stale = $age > 900 || $age < -120 ||
             $devAge > 900 || $devAge < -120;

    $est = strtolower((string)$p['bgpPeerState']) === 'established';

    $healthy = !$stale && $est;

    if ($healthy) {
        $established++;
    } else {
        $problems++;
    }

    $prefixKey = (string)$p['device_id'] . '|' .
                 (string)$p['bgpPeer_id'];

    $p['_accepted_ipv4'] =
        $prefixesIPv4[$prefixKey] ?? null;

    $p['_age'] = $age;
    $p['_stale'] = $stale;
    $p['_healthy'] = $healthy;

    $items[] = $p;
}

usort($items, static function ($a, $b) {
    return ((int)$a['_healthy']) <=> ((int)$b['_healthy']);
});

$total = count($items);

echo '<div class="box box-solid isp-autoheight-box isp-bgp-box" style="height:auto;overflow:visible;margin:0;border:1px solid #dce3e8;border-radius:6px;padding-bottom:8px;box-sizing:border-box">';

echo '<div class="box-header">';
echo '<h3 class="box-title">Sessões BGP ('.$total.')</h3>';
echo '</div>';

echo '<div class="box-content" style="padding:10px">';

echo '<div style="font-size:12px;color:#64748b;margin-bottom:10px">';
echo 'Dados SNMP · atualização conforme poller do Observium.';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">';

foreach ([
    ['Total', $total, '#183d59'],
    ['Estabelecidas', $established, '#168449'],
    ['Inativas / sem coleta', $problems, $problems ? '#b91c1c' : '#168449']
] as $metric) {
    echo '<div style="flex:1;min-width:110px;border:1px solid #e2e8f0;border-radius:6px;padding:9px">';
    echo '<div style="font-size:11px;color:#64748b">'.$e($metric[0]).'</div>';
    echo '<strong style="font-size:20px;color:'.$metric[2].'">'.$metric[1].'</strong>';
    echo '</div>';
}

echo '</div>';

echo '<div style="display:flex;justify-content:flex-end;margin-bottom:10px">';
echo '<button type="button" class="btn btn-primary btn-xs isp-bgp-toggle" aria-expanded="true" onclick="event.preventDefault();event.stopPropagation();event.stopImmediatePropagation();return window.ispBGPToggle(this);">Resumir sessões</button>';
echo '</div>';

if (!$items) {
    echo '<p>Nenhuma sessão BGP descoberta.</p>';
} else {
    echo '<div class="isp-bgp-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,330px),1fr));gap:10px">';

    $healthyShown = 0;

    foreach ($items as $p) {
        $healthy = $p['_healthy'];
        $stale = $p['_stale'];
        $age = $p['_age'];

        $hiddenCompact = false;

        if ($healthy) {
            $healthyShown++;
            $hiddenCompact = true;
        }

        $state = $stale
            ? 'SEM COLETA'
            : ($healthy ? 'ESTABLISHED' : strtoupper((string)$p['bgpPeerState']));

        $color = $stale ? '#b9770e' : ($healthy ? '#198754' : '#c0392b');

        $sec = (int)$p['bgpPeerFsmEstablishedTime'];

        $uptime = ($healthy && $sec > 0)
            ? floor($sec / 86400).'d '.
              floor(($sec % 86400) / 3600).'h '.
              floor(($sec % 3600) / 60).'m'
            : '—';

        echo '<div class="isp-bgp-peer" data-compact-hidden="'.($hiddenCompact ? '1' : '0').'" style="'.
             ''.
             'border:1px solid #dce3e8;border-left:4px solid '.$color.';border-radius:7px;padding:12px;background:#fff">';

        // ISP_BGP_COMPACT_LINE_V1
        // Visivel somente no modo resumido.
        echo '<div class="isp-bgp-compact-line" title="AS'.
            $e($p['bgpPeerRemoteAs']).' - '.
            $e($p['bgpPeerRemoteAddr']).' - '.
            $e($state).'">';

        echo '<strong class="isp-bgp-compact-ip">'.
            $e($p['bgpPeerRemoteAddr']).'</strong>';

        echo '<strong class="isp-bgp-compact-state" style="color:'.
            $color.'">'.$e($state).'</strong>';

        echo '</div>';

        echo '<div style="display:flex;justify-content:space-between;gap:8px">';
        echo '<strong>AS'.$e($p['bgpPeerRemoteAs']).'</strong>';
        echo '<strong style="color:'.$color.'">'.$e($state).'</strong>';
        echo '</div>';

        echo '<div style="font-size:17px;font-weight:600;margin:5px 0">';
        echo $e($p['bgpPeerRemoteAddr']);
        echo '</div>';

        echo '<div style="color:#667;font-size:11px">';
        echo 'Equipamento: '.$e($p['hostname']);
        echo '</div>';

        echo '<hr style="margin:8px 0">';

        echo '<div style="display:flex;justify-content:space-between;gap:8px">';
        echo '<span>Tempo estabelecido</span><strong>'.$e($uptime).'</strong>';
        echo '</div>';

        echo '<div style="display:flex;justify-content:space-between;gap:8px;margin-top:5px">';
        echo '<span>Prefixos IPv4 recebidos (aceitos)</span>';

        $accepted = $p['_accepted_ipv4'];

        echo '<strong style="color:#183d59">' .
             ($accepted !== null
                ? number_format((int)$accepted, 0, ',', '.')
                : 'Indisponível') .
             '</strong>';
        echo '</div>';

        echo '<div style="display:flex;justify-content:space-between;gap:8px;margin-top:5px">';
        echo '<span>Última coleta</span>';
        echo '<strong>'.($age >= 0 && $age < PHP_INT_MAX ? floor($age / 60).' min atrás' : '—').'</strong>';
        echo '</div>';

        echo '<div style="font-size:11px;color:#667;margin-top:6px">';
        echo 'Horário da coleta: '.
             ((int)$p['bgpPeer_polled'] > 0
                ? $e($ispLocalTime((int)$p['bgpPeer_polled']))
                : 'indisponível');
        echo '</div>';

        echo '</div>';
    }

    echo '</div>';

    echo '<div class="isp-bgp-compact-note" style="margin-top:9px;color:#64748b;font-size:12px">';
    echo $problems > 0
        ? 'Modo resumido: exibindo somente sessões com problemas.'
        : 'Todas as '.$established.' sessões BGP estão estabelecidas.';
    echo '</div>';
}

echo '</div></div>';



