<?php
// ISP_PPPOE_MULTI_PAGE_V1
// Pagina autenticada pelo roteamento normal do Observium.

$e = static function ($v) {
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$tz = new DateTimeZone('America/Sao_Paulo');
$dbFile = '/opt/observium/isp-pppoe/history-multi.sqlite';

echo '<div class="box box-solid">';
echo '<div class="box-header">';
echo '<h3 class="box-title">Concentradores PPPoE</h3>';
echo '</div><div class="box-content">';

if (!is_readable($dbFile)) {
    echo '<div class="alert alert-danger">';
    echo 'Banco historico PPPoE indisponivel para leitura.';
    echo '</div></div></div>';
    return;
}

try {
    $db = new SQLite3(
        $dbFile,
        SQLITE3_OPEN_READONLY
    );
    $db->busyTimeout(3000);

    $devices = [];
    $query = $db->query(
        'SELECT device_id, name, hostname, source
         FROM devices ORDER BY name'
    );

    while ($r = $query->fetchArray(SQLITE3_ASSOC)) {
        $devices[(int)$r['device_id']] = $r;
    }

    if (!$devices) {
        echo '<p>Nenhum concentrador cadastrado.</p>';
        echo '</div></div>';
        $db->close();
        return;
    }

    $periods = [
        '12h' => 43200,
        '24h' => 86400,
        '7d'  => 604800,
        '30d' => 2592000
    ];

    $period = (string)($_GET['period'] ?? '12h');
    if (!isset($periods[$period])) {
        $period = '12h';
    }

    $selected = filter_input(
        INPUT_GET,
        'device',
        FILTER_VALIDATE_INT
    );

    if (!$selected || !isset($devices[$selected])) {
        $selected = 0;
    }

    $now = time();
    $cutoff = $now - $periods[$period];

    $fmt = static function ($n) {
        return number_format((int)$n, 0, ',', '.');
    };

    $when = static function ($timestamp) use ($tz) {
        return (new DateTimeImmutable(
            '@' . (int)$timestamp
        ))->setTimezone($tz)->format('d/m/Y H:i:s');
    };

    $latestStmt = $db->prepare(
        'SELECT timestamp, online FROM samples
         WHERE device_id = :id
         ORDER BY timestamp DESC LIMIT 1'
    );

    $latest = [];
    $total = 0;
    $freshDevices = 0;

    foreach ($devices as $id => $device) {
        $latestStmt->reset();
        $latestStmt->clear();
        $latestStmt->bindValue(':id', $id, SQLITE3_INTEGER);

        $result = $latestStmt->execute();
        $r = $result
            ? $result->fetchArray(SQLITE3_ASSOC)
            : false;

        if ($result) $result->finalize();

        if (!$r) continue;

        $age = $now - (int)$r['timestamp'];
        $fresh = ($age >= -120 && $age <= 900);

        $latest[$id] = [
            'timestamp' => (int)$r['timestamp'],
            'online' => (int)$r['online'],
            'fresh' => $fresh
        ];

        if ($fresh) {
            $total += (int)$r['online'];
            $freshDevices++;
        }
    }

    echo '<style>
    .isp-multi-summary {
        display:grid;
        grid-template-columns:repeat(auto-fit,minmax(175px,1fr));
        gap:12px;margin:12px 0 18px;
    }
    .isp-multi-card {
        display:block;background:#fff;
        border:1px solid #dce3e8;
        border-radius:8px;padding:15px;
        color:inherit;text-decoration:none;
    }
    .isp-multi-card:hover {
        border-color:#2879c7;
        text-decoration:none;
        box-shadow:0 2px 8px rgba(0,0,0,.07);
    }
    .isp-multi-number {
        font-size:28px;font-weight:700;
        margin:6px 0;
    }
    .isp-multi-filters {
        display:flex;gap:10px;
        flex-wrap:wrap;align-items:end;
        margin:15px 0;
    }
    .isp-multi-filters select {
        min-width:160px;
        max-width:100%;
    }
    #isp-multi-chart {
        display:block;width:100%;
        height:auto;min-height:200px;
        background:#fff;
    }
    #isp-multi-tooltip {
        position:fixed;display:none;
        pointer-events:none;
        z-index:99999;
        background:#1e293b;color:#fff;
        border-radius:6px;padding:8px 11px;
        font-size:12px;
        box-shadow:0 3px 12px rgba(0,0,0,.18);
        white-space:nowrap;
    }
    </style>';

    echo '<div class="isp-multi-summary">';

    // ISP_PPPOE_TOTAL_CARD_CLICK_V1
    // Volta ao consolidado mantendo o periodo selecionado.
    $totalUrl = '?page=pppoe&device=0&period=' .
        rawurlencode($period);

    echo '<a class="isp-multi-card" href="' .
        $e($totalUrl) . '" title="Mostrar todos os concentradores">';
    echo '<div style="font-size:12px;color:#64748b">';
    echo 'PPPoE totais</div>';
    echo '<div class="isp-multi-number" style="color:#198754">';
    echo $fmt($total) . '</div>';
    echo '<div style="font-size:11px;color:#64748b">';
    echo $freshDevices . ' de ' . count($devices);
    echo ' concentradores com coleta recente</div>';
    echo '</a>';

    foreach ($devices as $id => $device) {
        $r = $latest[$id] ?? null;
        $fresh = $r && $r['fresh'];

        $url = '?page=pppoe&device=' . $id .
            '&period=' . rawurlencode($period);

        echo '<a class="isp-multi-card" href="' .
            $e($url) . '">';

        echo '<strong>' . $e($device['name']) . '</strong>';

        echo '<div class="isp-multi-number" style="color:' .
            ($fresh ? '#198754' : '#b9770e') . '">';

        echo $r ? $fmt($r['online']) : '—';
        echo '</div>';

        echo '<div style="font-size:11px;color:#64748b">';
        echo $e($device['hostname']) . '</div>';

        echo '<div style="font-size:11px;margin-top:5px">';
        echo $fresh ? 'ATUALIZADO' : 'SEM COLETA RECENTE';
        echo '</div>';

        if ($r) {
            echo '<div style="font-size:10px;color:#64748b">';
            echo $e($when($r['timestamp'])) . '</div>';
        }

        echo '</a>';
    }

    echo '</div>';

    echo '<form class="isp-multi-filters" method="get">';
    echo '<input type="hidden" name="page" value="pppoe">';

    echo '<div><label>Concentrador</label>';
    echo '<select name="device" class="form-control">';

    echo '<option value="0">Todos (total consolidado)</option>';

    foreach ($devices as $id => $device) {
        echo '<option value="' . $id . '"';
        if ($selected === $id) echo ' selected';
        echo '>' . $e($device['name']) . '</option>';
    }

    echo '</select></div>';

    echo '<div><label>Período</label>';
    echo '<select name="period" class="form-control">';

    foreach ($periods as $key => $seconds) {
        echo '<option value="' . $e($key) . '"';
        if ($period === $key) echo ' selected';
        echo '>' . $e($key) . '</option>';
    }

    echo '</select></div>';

    echo '<div><button class="btn btn-primary" type="submit">';
    echo 'Aplicar filtros</button></div>';
    echo '</form>';

    $bucketSeconds = [
        '12h' => 300,
        '24h' => 300,
        '7d'  => 1800,
        '30d' => 7200
    ][$period];

    $historyStmt = $db->prepare(
        'SELECT timestamp, online FROM samples
         WHERE device_id = :id AND timestamp >= :cutoff
         ORDER BY timestamp'
    );


    // ISP_PPPOE_CONSOLIDATION_CARRY_V2
    // Mantem ultima amostra valida por BNG.
    // Nao inventa historico anterior a inclusao.
    // Nao cria falsos zeros por falta de coleta.

    $maxAge = 900;
    $deviceCount = count($devices);

    $buckets = [];
    $firstSeen = [];
    $last = [];

    $firstStmt = $db->prepare(
        'SELECT MIN(timestamp) AS first_ts
         FROM samples WHERE device_id = :id'
    );

    $beforeStmt = $db->prepare(
        'SELECT timestamp, online FROM samples
         WHERE device_id = :id
           AND timestamp < :cutoff
         ORDER BY timestamp DESC LIMIT 1'
    );

    $historyStmt = $db->prepare(
        'SELECT timestamp, online FROM samples
         WHERE device_id = :id
           AND timestamp >= :cutoff
           AND timestamp <= :now
         ORDER BY timestamp'
    );

    if (!$firstStmt || !$beforeStmt || !$historyStmt) {
        throw new RuntimeException(
            'Falha ao preparar historico PPPoE'
        );
    }

    foreach ($devices as $id => $device) {

        // Inicio real do historico do concentrador.
        $firstStmt->reset();
        $firstStmt->clear();
        $firstStmt->bindValue(
            ':id', $id, SQLITE3_INTEGER
        );

        $result = $firstStmt->execute();

        if (!$result) {
            throw new RuntimeException(
                'Falha ao ler inicio do historico'
            );
        }

        $first = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();

        if (!$first || $first['first_ts'] === null) {
            continue;
        }

        $firstSeen[$id] = (int)$first['first_ts'];

        // Ultima leitura anterior ao periodo.
        // So sera usada se ainda estiver recente.
        $beforeStmt->reset();
        $beforeStmt->clear();
        $beforeStmt->bindValue(
            ':id', $id, SQLITE3_INTEGER
        );
        $beforeStmt->bindValue(
            ':cutoff', $cutoff, SQLITE3_INTEGER
        );

        $result = $beforeStmt->execute();

        if (!$result) {
            throw new RuntimeException(
                'Falha ao consultar amostra anterior'
            );
        }

        $previous = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();

        if ($previous) {
            $last[$id] = [
                't' => (int)$previous['timestamp'],
                'v' => (int)$previous['online']
            ];
        }

        // Todas as leituras reais do periodo.
        $historyStmt->reset();
        $historyStmt->clear();
        $historyStmt->bindValue(
            ':id', $id, SQLITE3_INTEGER
        );
        $historyStmt->bindValue(
            ':cutoff', $cutoff, SQLITE3_INTEGER
        );
        $historyStmt->bindValue(
            ':now', $now, SQLITE3_INTEGER
        );

        $result = $historyStmt->execute();

        if (!$result) {
            throw new RuntimeException(
                'Falha ao consultar amostras PPPoE'
            );
        }

        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            $ts = (int)$r['timestamp'];

            $bucket = (int)(
                floor($ts / $bucketSeconds) *
                $bucketSeconds
            );

            // Ultima amostra real do BNG no intervalo.
            $buckets[$bucket][$id] = [
                't' => $ts,
                'v' => (int)$r['online']
            ];
        }

        $result->finalize();
    }

    ksort($buckets, SORT_NUMERIC);

    $series = [];

    foreach ($buckets as $bucket => $updates) {

        // Atualiza a ultima leitura de cada equipamento.
        foreach ($updates as $id => $sample) {
            $last[$id] = $sample;
        }

        // Instante da ultima coleta real do intervalo.
        $pointTime = max(array_column($updates, 't'));

        $value = 0;
        $active = 0;
        $valid = 0;

        foreach ($firstSeen as $id => $begin) {

            if ($selected > 0 && $id !== $selected) {
                continue;
            }

            // Este concentrador ainda nao era monitorado.
            if ($begin > $pointTime) {
                continue;
            }

            $active++;

            if (!isset($last[$id])) {
                continue;
            }

            $age = $pointTime - $last[$id]['t'];

            // Nao soma leitura futura ou desatualizada.
            if ($age < 0 || $age > $maxAge) {
                continue;
            }

            $value += $last[$id]['v'];
            $valid++;
        }

        if ($active === 0) {
            continue;
        }

        // Se um BNG ja integrado estiver sem leitura
        // valida, nao produzir um falso total parcial.
        // ISP_PPPOE_PARTIAL_HISTORY_V3
        // Continua registrando BNGs com coleta valida.
        // Nao soma leituras vencidas.
        // Nao grava ponto se nenhum BNG estiver atualizado.
        if ($valid === 0) {
            continue;
        }

        $series[] = [
            't' => (int)$bucket,
            'v' => (int)$value,
            'n' => (int)$valid,
            'expected' => $selected > 0
                ? 1
                : $deviceCount
        ];
    }

    $title = $selected
        ? $devices[$selected]['name']
        : 'Total consolidado';

    echo '<h4>Histórico — ' . $e($title) .
        ' — ' . $e($period) . '</h4>';

    echo '<div style="border:1px solid #dce3e8;';
    echo 'border-radius:7px;padding:12px">';
    echo '<svg id="isp-multi-chart" viewBox="0 0 900 320"';
    echo ' role="img" aria-label="Histórico de sessões PPPoE">';
    echo '</svg>';
    echo '<div id="isp-multi-tooltip"></div>';

    echo '<div style="font-size:11px;color:#64748b">';
    echo 'Amostras exibidas: ' . count($series);
    echo ' · Coleta de origem: 5 minutos';
    echo '</div>';

    if (!$selected) {
        echo '<div style="font-size:11px;color:#64748b">';
        echo 'Histórico progressivo: períodos antigos preservados. ';
        echo 'Cada ponto soma os concentradores com leitura naquele ';
        echo 'intervalo. A inclusão de um novo BNG pode causar um ';
        echo 'salto no gráfico que não representa novos clientes.';
        echo '</div>';
    }

    echo '</div>';

    echo '<script>';
    echo 'const ispMultiData = ' . json_encode(
        $series,
        JSON_HEX_TAG | JSON_HEX_AMP |
        JSON_HEX_APOS | JSON_HEX_QUOT
    ) . ';';
    echo 'const ispMultiBucket = ' . (int)$bucketSeconds . ';';
    // ISP_PPPOE_CHART_FULL_PERIOD_V1
    echo 'const ispMultiWindowStart = ' . (int)$cutoff . ';';
    echo 'const ispMultiWindowEnd = ' . (int)$now . ';';
    echo '</script>';

    $db->close();

} catch (Throwable $ex) {
    echo '<div class="alert alert-danger">';
    echo 'Falha ao consultar o histórico PPPoE.';
    echo '</div>';
    error_log(
        'Observium PPPoE multi: ' . $ex->getMessage()
    );
}

echo '</div></div>';
?>

<script>
(function () {
    var svg = document.getElementById('isp-multi-chart');
    var tip = document.getElementById('isp-multi-tooltip');

    if (!svg || !tip || typeof ispMultiData === 'undefined') {
        return;
    }

    var data = ispMultiData;
    var ns = 'http://www.w3.org/2000/svg';

    function element(tag, attrs, parent) {
        var el = document.createElementNS(ns, tag);
        Object.keys(attrs || {}).forEach(function (key) {
            el.setAttribute(key, attrs[key]);
        });
        (parent || svg).appendChild(el);
        return el;
    }

    function text(x, y, value, anchor) {
        var el = element('text', {
            x: x, y: y, fill: '#64748b',
            'font-size': 12,
            'text-anchor': anchor || 'start'
        });
        el.textContent = String(value);
        return el;
    }

    if (!data.length) {
        text(450, 155, 'Sem histórico completo para este período', 'middle');
        return;
    }

    var w = 900, h = 320;
    var left = 65, right = 18, top = 18, bottom = 42;
    var pw = w - left - right;
    var ph = h - top - bottom;

    // Eixo X representa todo o periodo selecionado,
    // mesmo quando as amostras sao recentes.
    var minT = ispMultiWindowStart;
    var maxT = ispMultiWindowEnd;
    var values = data.map(function (r) { return r.v; });
    var minV = Math.min.apply(null, values);
    var maxV = Math.max.apply(null, values);

    var pad = Math.max(5, Math.ceil((maxV - minV) * 0.15));
    var low = Math.max(0, minV - pad);
    var high = maxV + pad;

    function x(t) {
        return left + (maxT === minT
            ? pw / 2
            : ((t - minT) / (maxT - minT)) * pw);
    }

    function y(v) {
        return top + (1 - (v - low) / Math.max(1, high - low)) * ph;
    }

    for (var i = 0; i <= 4; i++) {
        var gy = top + ph * i / 4;
        element('line', {
            x1:left, y1:gy, x2:w-right, y2:gy,
            stroke:'#e2e8f0', 'stroke-width':1
        });
        text(left - 9, gy + 4,
            Math.round(high - (high-low)*i/4), 'end');
    }

    for (var j = 0; j <= 4; j++) {
        var t = minT + (maxT-minT)*j/4;
        var d = new Date(t*1000);
        var label = d.toLocaleString('pt-BR', {
            timeZone:'America/Sao_Paulo',
            day:'2-digit',month:'2-digit',
            hour:'2-digit',minute:'2-digit'
        });
        text(left + pw*j/4, h-10, label, 'middle');
    }

    // ISP_PPPOE_TRANSITION_BRIDGE_V2
    // Separa trechos quando a cobertura muda.
    // Liga a transicao com traco cinza pontilhado.
    // Nao une periodos grandes sem amostras.

    var segments = [];
    var bridges = [];
    var current = [];

    data.forEach(function (p, index) {

        if (index > 0) {
            var previous = data[index - 1];

            var gapSeconds = p.t - previous.t;

            var normalGapLimit =
                ispMultiBucket * 2.5;

            var transitionGapLimit = Math.max(
                1800,
                ispMultiBucket * 2.5
            );

            var coverageChanged =
                p.n !== previous.n;

            var normalGap =
                gapSeconds > normalGapLimit;

            if (coverageChanged || normalGap) {

                if (current.length) {
                    segments.push(current);
                }

                current = [];

                // A transicao pode levar mais de
                // 5 minutos, pois o BNG so e considerado
                // vencido apos 15 minutos sem coleta.
                //
                // Permite ligar transicoes proximas,
                // mas nao une interrupcoes longas.
                if (
                    coverageChanged &&
                    gapSeconds >= 0 &&
                    gapSeconds <= transitionGapLimit
                ) {
                    bridges.push({
                        a: previous,
                        b: p,
                        from: previous.n,
                        to: p.n
                    });
                }
            }
        }

        current.push(
            x(p.t) + ',' + y(p.v)
        );
    });

    if (current.length) {
        segments.push(current);
    }


    // ISP_PPPOE_MULTI_AREA_GRADIENT_V1
    // Preenchimento azul transparente sob a curva.
    // Cada segmento e preenchido separadamente.
    // Nao conecta periodos com cobertura diferente.

    var areaDefs = element('defs', {});

    var areaGradient = element('linearGradient', {
        id: 'isp-multi-area-gradient-v1',
        x1: '0',
        y1: '0',
        x2: '0',
        y2: '1'
    }, areaDefs);

    element('stop', {
        offset: '0%',
        'stop-color': '#2563eb',
        'stop-opacity': '0.25'
    }, areaGradient);

    element('stop', {
        offset: '100%',
        'stop-color': '#2563eb',
        'stop-opacity': '0.01'
    }, areaGradient);

    // Fecha cada trecho na base do grafico,
    // criando a area preenchida.
    var areaBaseY = top + ph;

    segments.forEach(function (points) {

        if (points.length < 2) {
            return;
        }

        var first = points[0].split(',');
        var last = points[points.length - 1].split(',');

        var areaPoints = points.join(' ') +
            ' ' + last[0] + ',' + areaBaseY +
            ' ' + first[0] + ',' + areaBaseY;

        element('polygon', {
            points: areaPoints,
            fill: 'url(#isp-multi-area-gradient-v1)',
            stroke: 'none',
            'pointer-events': 'none'
        });
    });

    // Primeiro desenha as transicoes pontilhadas.
    bridges.forEach(function (bridge) {

        element('line', {
            x1: x(bridge.a.t),
            y1: y(bridge.a.v),
            x2: x(bridge.b.t),
            y2: y(bridge.b.v),
            stroke: '#94a3b8',
            'stroke-width': 2.5,
            'stroke-dasharray': '5,4',
            'stroke-linecap': 'round'
        });

        // Pontos de referencia da transicao.
        [bridge.a, bridge.b].forEach(function (p) {
            element('circle', {
                cx: x(p.t),
                cy: y(p.v),
                r: 3.5,
                fill: '#2563eb',
                stroke: '#ffffff',
                'stroke-width': 1
            });
        });
    });

    // Cada trecho azul representa leituras com
    // a mesma quantidade de BNGs contribuindo.
    segments.forEach(function (points) {

        element('polyline', {
            points: points.join(' '),
            fill: 'none',
            stroke: '#2563eb',
            'stroke-width': 2.5,
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round'
        });
    });

    // ISP_PPPOE_MULTI_SINGLE_POINT_V1
    // Exibe a primeira amostra isolada no grafico.
    if (data.length === 1) {
        element('circle', {
            cx: x(data[0].t),
            cy: y(data[0].v),
            r: 5,
            fill: '#2563eb',
            stroke: '#ffffff',
            'stroke-width': 2
        });
    }


    
    // ISP_PPPOE_MULTI_ALERT_V5
    // Perda de coleta: aviso especifico.
    // Variacao de sessoes: Alta ou Queda.
    // Mantem o hover, area azul e linhas tracejadas.

    function ispAlertIcon(px, py, kind, label) {

        // ISP_PPPOE_NO_COLLECTION_YELLOW_V1
        // Verde: alta de sessoes.
        // Vermelho: queda de sessoes.
        // Amarelo: perda de coleta do concentrador.
        var isUp = kind === 'up';
        var isNoCollection = label === 'Sem coleta';

        var color = isNoCollection
            ? '#d97706'
            : (isUp ? '#16a34a' : '#dc3545');

        var bg = isNoCollection
            ? '#fffbeb'
            : (isUp ? '#f0fdf4' : '#fff1f2');

        // Marca o evento no ponto real.
        element('circle', {
            cx: px,
            cy: py,
            r: 3.3,
            fill: color,
            stroke: '#ffffff',
            'stroke-width': 1.2,
            'pointer-events': 'none'
        });

        // Aviso discreto, acima do ponto.
        var bw = label === 'Sem coleta' ? 94 : (label === 'Retorno' ? 78 : 67);
        var bh = 22;

        var bx = Math.max(
            left + 2,
            Math.min(px - bw / 2, w - right - bw - 2)
        );

        var by = Math.max(
            top + 3,
            py - 43
        );

        element('line', {
            x1: bx + bw / 2,
            y1: by + bh,
            x2: px,
            y2: py - 5,
            stroke: color,
            'stroke-width': 1.1,
            'stroke-dasharray': '3,3',
            'stroke-opacity': 0.65,
            'pointer-events': 'none'
        });

        var group = element('g', {
            'pointer-events': 'none'
        });

        element('rect', {
            x: bx,
            y: by,
            width: bw,
            height: bh,
            rx: 5,
            fill: bg,
            'fill-opacity': 0.80,
            stroke: color,
            'stroke-width': 1.1
        }, group);

        // ISP_PPPOE_RETURN_CIRCULAR_V1
        // Retorno: seta circular; Alta: seta para cima.
        if (label === 'Retorno') {

            element('path', {
                d:
                    'M ' + (bx+19) + ' ' + (by+9) +
                    ' A 6 6 0 1 0 ' +
                    (bx+19) + ' ' + (by+14) +
                    ' M ' + (bx+19) + ' ' + (by+9) +
                    ' L ' + (bx+19) + ' ' + (by+5) +
                    ' M ' + (bx+19) + ' ' + (by+9) +
                    ' L ' + (bx+15) + ' ' + (by+9),
                fill: 'none',
                stroke: color,
                'stroke-width': 1.6,
                'stroke-linecap': 'round',
                'stroke-linejoin': 'round'
            }, group);

        } else if (isUp) {
            element('path', {
                d: 'M ' + (bx+8) + ' ' + (by+13) +
                   ' L ' + (bx+14) + ' ' + (by+7) +
                   ' L ' + (bx+20) + ' ' + (by+13) +
                   ' M ' + (bx+14) + ' ' + (by+7) +
                   ' L ' + (bx+14) + ' ' + (by+17),
                fill: 'none',
                stroke: color,
                'stroke-width': 1.6,
                'stroke-linecap': 'round',
                'stroke-linejoin': 'round'
            }, group);
        } else if (label === 'Queda') {
            // ISP_PPPOE_BIG_DOWN_ARROW_V1
            // Vermelho para baixo: queda de sessoes.
            element('circle', {
                cx: bx + 14,
                cy: by + 11,
                r: 7,
                fill: '#fff1f2',
                stroke: '#dc3545',
                'stroke-width': 1
            }, group);

            element('path', {
                d:
                    'M ' + (bx+14) + ' ' + (by+6) +
                    ' L ' + (bx+14) + ' ' + (by+16) +
                    ' M ' + (bx+10) + ' ' + (by+12) +
                    ' L ' + (bx+14) + ' ' + (by+16) +
                    ' L ' + (bx+18) + ' ' + (by+12),
                fill: 'none',
                stroke: '#dc3545',
                'stroke-width': 1.6,
                'stroke-linecap': 'round',
                'stroke-linejoin': 'round'
            }, group);

        } else {
            element('polygon', {
                points:
                    (bx+8)+','+(by+16)+' '+
                    (bx+14)+','+(by+5)+' '+
                    (bx+20)+','+(by+16),
                fill: '#fff3cd',
                stroke: color,
                'stroke-width': 1
            }, group);

            var excl = element('text', {
                x: bx+14, y: by+14,
                'text-anchor': 'middle',
                fill: color,
                'font-size': 8,
                'font-weight': 700
            }, group);
            excl.textContent = '!';
        }

        var txt = element('text', {
            x: bx + 26,
            y: by + 15,
            fill: color,
            'font-size': 10,
            'font-weight': 600
        }, group);

        txt.textContent = label;
    }

    var lastAlertX = -9999;
    var minAlertDistance = 38;

    data.forEach(function (p, index) {

        if (index === 0) return;

        var previous = data[index-1];
        var seconds = p.t - previous.t;
        var px = x(p.t);
        var py = y(p.v);

        // Evita avisos sobrepostos em intervalos curtos.
        if (px - lastAlertX < minAlertDistance) {
            return;
        }

        var coverageChanged = p.n !== previous.n;

        if (coverageChanged) {
            // Mudanca de cobertura do monitoramento.
            if (seconds <= Math.max(
                1800, ispMultiBucket * 2.5
            )) {
                if (p.n < previous.n) {
                    ispAlertIcon(
                        px, py, 'down', 'Sem coleta'
                    );
                } else {
                    ispAlertIcon(
                        px, py, 'up', 'Retorno'
                    );
                }

                lastAlertX = px;
            }
            return;
        }

        // Oscilacoes PPPoE: analisar apenas quando
        // a cobertura dos BNGs permanece constante.
        // Somente pontos separados por ate 11 minutos.
        if (seconds <= 0 || seconds > 660) {
            return;
        }

        var delta = p.v - previous.v;

        var percent = Math.abs(delta) * 100 /
            Math.max(1, previous.v);

        if (Math.abs(delta) < 50 || percent < 5) {
            return;
        }

        ispAlertIcon(
            px,
            py,
            delta > 0 ? 'up' : 'down',
            delta > 0 ? 'Alta' : 'Queda'
        );

        lastAlertX = px;
    });

        var cross = element('line', {
        x1:left,y1:top,x2:left,y2:top+ph,
        stroke:'#2563eb',
        'stroke-dasharray':'4,4',
        visibility:'hidden'
    });

    var dot = element('circle', {
        cx:left,cy:top,r:5,
        fill:'#2563eb',
        stroke:'#fff',
        'stroke-width':2,
        visibility:'hidden'
    });

    var overlay = element('rect', {
        x:left,y:top,width:pw,height:ph,
        fill:'transparent',
        'pointer-events':'all'
    });

    var formatter = new Intl.DateTimeFormat('pt-BR', {
        timeZone:'America/Sao_Paulo',
        day:'2-digit',month:'2-digit',year:'numeric',
        hour:'2-digit',minute:'2-digit'
    });

    var numberFormatter = new Intl.NumberFormat('pt-BR');

    overlay.addEventListener('pointermove', function (ev) {
        var rect = svg.getBoundingClientRect();
        if (!rect.width) return;

        var px = (ev.clientX - rect.left) / rect.width * w;

        var best = data[0];
        var distance = Infinity;

        data.forEach(function (p) {
            var dx = Math.abs(x(p.t) - px);
            if (dx < distance) {
                distance = dx;
                best = p;
            }
        });

        var bx = x(best.t), by = y(best.v);

        cross.setAttribute('x1', bx);
        cross.setAttribute('x2', bx);
        cross.setAttribute('visibility','visible');

        dot.setAttribute('cx', bx);
        dot.setAttribute('cy', by);
        dot.setAttribute('visibility','visible');

        // ISP_PPPOE_COVERAGE_TOOLTIP_V1
        var coverage = '';
        if (best.expected > 1) {
            coverage = ' | ' + best.n + '/' +
                best.expected + ' BNGs com leitura';
        }

        tip.textContent =
            formatter.format(new Date(best.t*1000)) +
            ' — ' + numberFormatter.format(best.v) +
            ' conectados' + coverage;

        tip.style.display = 'block';

        var tx = Math.min(
            ev.clientX + 12,
            window.innerWidth - tip.offsetWidth - 12
        );
        var ty = Math.max(
            8, ev.clientY - tip.offsetHeight - 14
        );

        tip.style.left = Math.max(8,tx) + 'px';
        tip.style.top = ty + 'px';
    });

    overlay.addEventListener('pointerleave', function () {
        tip.style.display = 'none';
        cross.setAttribute('visibility','hidden');
        dot.setAttribute('visibility','hidden');
    });
})();
</script>
