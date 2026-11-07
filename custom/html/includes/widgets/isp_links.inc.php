<?php
// ISP_CATEGORIA_NAS_V1
// ISP_SERVER_SUBSTITUIDO_NAS_V2
// ISP_CATEGORIA_LAG_V1
// ISP_CATEGORIAS_SERVER_TRUNK_V2

// ISP_WIDGET_TIMEZONE_BR_V1
// Converte apenas a exibicao; nao altera timestamps de coleta.
$ispLocalTime = static function ($timestamp) {
    return (new DateTimeImmutable('@' . (int)$timestamp))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
        ->format('d/m/Y H:i:s');
};

if (($mod['widget_type'] ?? '') !== 'isp_links') return;
$e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmt = static function ($bits) { if (!is_numeric($bits)) return '—'; $bits=max(0,(float)$bits); return number_format($bits >= 1e9 ? $bits/1e9 : $bits/1e6,2,',','.') . ($bits >= 1e9 ? ' Gbps' : ' Mbps'); };
$rows=dbFetchRows("SELECT d.device_id,d.hostname,d.last_polled,p.port_id,p.ifName,p.ifAlias,p.port_descr_type,p.ifOperStatus,p.ifSpeed,p.ifInOctets_rate,p.ifOutOctets_rate,p.ifInErrors_rate,p.ifOutErrors_rate,p.ifInDiscards_rate,p.ifOutDiscards_rate FROM ports p JOIN devices d ON d.device_id=p.device_id WHERE LOWER(COALESCE(p.port_descr_type,'')) IN ('transit','peering','cgnat','core','ptp','trunk','mgmt','lag','nas') OR LOWER(COALESCE(p.ifAlias,'')) REGEXP '^[[:space:]]*(transit|peering|cgnat|core|ptp|trunk|mgmt|lag|nas)[[:space:]]*:' ORDER BY d.hostname,p.ifName");
$stackRows=dbFetchRows("SELECT s.port_id_high,s.port_id_low,s.device_id,p.ifName,p.ifAlias,p.ifOperStatus,p.ifAdminStatus,p.ifSpeed FROM ports_stack s JOIN ports p ON p.port_id=s.port_id_low AND p.device_id=s.device_id WHERE s.ifStackStatus='active'");
$members=[];foreach($stackRows as $m){$members[(int)$m['device_id']][(int)$m['port_id_high']][]=$m;}
$groups=['transit'=>[],'peering'=>[],'cgnat'=>[],'core'=>[],'ptp'=>[],'trunk'=>[],'mgmt'=>[],'nas'=>[],'lag'=>[]];
foreach($rows as $p){$cat=strtolower(trim((string)$p['port_descr_type']));if(preg_match('/^\s*nas\s*:/i',(string)$p['ifAlias']))$cat='nas';if(!isset($groups[$cat]) && preg_match('/^\s*(transit|peering|cgnat|core|ptp|trunk|mgmt|lag|nas)\s*:/i',(string)$p['ifAlias'],$m))$cat=strtolower($m[1]);if(isset($groups[$cat]))$groups[$cat][]=$p;}
$now=time();$fresh=0;foreach($rows as $p){$t=strtotime((string)$p['last_polled']);if($t && $t>$fresh)$fresh=$t;}
$staleAge=static function($t) use($now){return !$t || ($now-$t)>900 || ($now-$t)<-120;};
$percent=static fn($n)=>$n===null?'—':number_format($n,1,',','.').'%';
echo '<div class="box box-solid isp-autoheight-box isp-links-box do-not-update" style="height:auto;overflow:visible"><div class="box-header"><h3 class="box-title">Monitoramento ISP — Links e Tráfego</h3></div><div class="box-content" style="padding:10px">';
echo '<div style="font-size:12px;color:#64748b;margin-bottom:8px">Atualização dos dados: coleta SNMP do Observium (normalmente a cada 5 minutos). Última coleta: '.($fresh? $e($ispLocalTime($fresh)):'indisponível').' · Atualização automática conforme a coleta do Observium.</div>';
foreach($groups as $cat=>$ports){$catLabel=in_array($cat,['trunk','lag'],true)?ucfirst($cat):strtoupper($cat);$count=count($ports);if($count===0)continue;$open=$count>0?' open':'';echo '<details data-isp-group="'.$cat.'"'.$open.' style="margin:8px 0 12px;border:1px solid #dbe4eb;border-radius:7px;background:#fff"><summary style="cursor:pointer;padding:10px 12px;background:#f5f8fa;color:#155b84;font-weight:700">'.$catLabel.' ('.$count.') — clique para recolher/expandir</summary><div style="padding:10px">';
if(!$ports){echo '<span style="color:#718096">Nenhuma interface identificada.</span></div></details>';continue;}
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,390px),1fr));gap:10px">';
foreach($ports as $p){$name=preg_replace('/^\s*(transit|peering|cgnat|core|ptp|trunk|mgmt|lag|nas)\s*:\s*/i','',(string)$p['ifAlias']);if(!$name)$name=$p['ifName'];$last=strtotime((string)$p['last_polled']);$stale=$staleAge($last);$up=strtolower((string)$p['ifOperStatus'])==='up';$status=$stale?'SEM COLETA':($up?'UP':'DOWN');$color=$stale?'#a66c13':($up?'#168449':'#b91c1c');$speed=(float)$p['ifSpeed'];$nominal=null;$operational=null;$lag=[];
if($cat==='core'){$lag=$members[(int)$p['device_id']][(int)$p['port_id']]??[];$lag=array_values(array_filter($lag,static fn($m)=>preg_match('/^(?:ethernet|ieee8023ad)/i',(string)$m['ifName']) || preg_match('/\bLAG\s*:/i',(string)$m['ifAlias']) || preg_match('/^(?:ethernet|ethernetCsmacd)$/i',(string)($m['ifType']??''))));if($lag){$nominal=0;$operational=0;foreach($lag as $m){$v=max(0,(float)$m['ifSpeed']);$nominal+=$v;if(strtolower((string)$m['ifOperStatus'])==='up' && strtolower((string)$m['ifAdminStatus'])==='up')$operational+=$v;}$upCount=count(array_filter($lag,static fn($m)=>strtolower((string)$m['ifOperStatus'])==='up' && strtolower((string)$m['ifAdminStatus'])==='up'));if(!$stale && $up && $upCount<count($lag)){$status='DEGRADADO · '.$upCount.'/'.count($lag).' UP';$color='#b9770e';}elseif(!$stale && $up){$status='UP · '.$upCount.'/'.count($lag).' membros';}}}
$rx=is_numeric($p['ifInOctets_rate'])?max(0,(float)$p['ifInOctets_rate'])*8:null;$tx=is_numeric($p['ifOutOctets_rate'])?max(0,(float)$p['ifOutOctets_rate'])*8:null;$capacity=($cat==='core' && $operational!==null)?$operational:$speed;$rxpct=$capacity>0 && $rx!==null?100*$rx/$capacity:null;$txpct=$capacity>0 && $tx!==null?100*$tx/$capacity:null;$url='graph.php?type=port_bits&amp;id='.(int)$p['port_id'].'&amp;from=-1d';
echo '<div style="border:1px solid #dce3e8;border-left:4px solid '.$color.';border-radius:7px;padding:12px;min-width:0">';echo '<div style="display:flex;justify-content:space-between;gap:8px"><strong style="color:#183d59">'.$e($catLabel.' · '.$name).'</strong><strong style="color:'.$color.';text-align:right">'.$e($status).'</strong></div><div style="font-size:11px;color:#667;margin:4px 0 10px">'.$e($p['hostname'].' · '.$p['ifName']).'</div>';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px"><div><small>DOWNLOAD · RX</small><div style="font-size:18px;font-weight:700;color:#168449">'.$fmt($rx).'</div></div><div><small>UPLOAD · TX</small><div style="font-size:18px;font-weight:700;color:#2879c7">'.$fmt($tx).'</div></div></div>';
foreach([['RX',$rxpct,'#22a35a'],['TX',$txpct,'#2980c9']] as $b){$w=$b[1]===null?0:min(100,max(0,$b[1]));echo '<div style="display:flex;justify-content:space-between;font-size:11px;margin:5px 0 3px"><span>Utilização '.$b[0].'</span><strong>'.$percent($b[1]).'</strong></div><div style="height:8px;background:#e8edf1;border-radius:8px;overflow:hidden"><div style="height:100%;width:'.$w.'%;background:'.$b[2].'"></div></div>';}
echo '<div style="font-size:11px;color:#667;margin-top:9px">Capacidade informada: '.$fmt($speed);if($nominal!==null)echo ' · Nominal dos membros: '.$fmt($nominal).' · Operacional estimada: '.$fmt($operational);echo ' · Erros RX/TX: '.$e($p['ifInErrors_rate']??'—').'/'.$e($p['ifOutErrors_rate']??'—').' · Descartes RX/TX: '.$e($p['ifInDiscards_rate']??'—').'/'.$e($p['ifOutDiscards_rate']??'—').'</div>';
if($lag){echo '<details style="margin-top:9px"><summary style="cursor:pointer;color:#17618b;font-weight:600">Membros físicos do CORE ('.count($lag).')</summary><div style="padding:6px 0">';foreach($lag as $m){$memberUp=strtolower((string)$m['ifOperStatus'])==='up' && strtolower((string)$m['ifAdminStatus'])==='up';echo '<div style="padding:4px 0;border-bottom:1px solid #edf1f4"><strong style="color:'.($memberUp?'#168449':'#b91c1c').'">'.($memberUp?'UP':'DOWN').'</strong> · '.$e($m['ifName']).' · '.$fmt($m['ifSpeed']).'<div style="font-size:11px;color:#667">'.$e($m['ifAlias']).'</div></div>';}echo '</div></details>';}

// ISP_TRANSIT_NATIVE_REALTIME_V2
if ($cat === 'transit') {
    $rtPort = (int)$p['port_id'];

    echo '<div class="isp-native-rt" data-port="'.$rtPort.'" style="margin-top:12px;border-top:1px solid #e5eaf0;padding-top:10px">';

    echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">';
    echo '<strong style="font-size:12px;color:#183d59">Monitoramento de tráfego</strong>';
    echo '<button type="button" class="btn btn-default btn-xs isp-native-rt-toggle" aria-expanded="false">Tempo real · 5s</button>';
    echo '</div>';

    echo '<div class="isp-native-rt-panel" style="display:none;margin-top:10px">';
    echo '<div style="font-size:11px;color:#64748b;margin-bottom:7px">Gráfico nativo do Observium · atualização a cada 5 segundos</div>';
    echo '<div class="isp-native-rt-frame" style="width:100%;height:260px;border:1px solid #e5eaf0;border-radius:5px;overflow:hidden;background:#fff"></div>';
    echo '<div style="margin-top:6px;font-size:11px"><a href="device/device='.(int)$p['device_id'].'/tab=port/port='.$rtPort.'/view=realtime/" target="_blank" rel="noopener">Abrir monitoramento completo ↗</a></div>';
    echo '</div></div>';
}

// ISP_LINKS_EXPAND_24H_V1
echo '<div style="margin-top:8px;font-size:11px;color:#667">Última coleta: '.($last?$e($ispLocalTime($last)):'indisponível').'</div>';

// ISP_LINKS_GRAPH24_PERSIST_V3
echo '<details class="isp-graph24" data-graph-url="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'" style="margin-top:8px;border-top:1px solid #e5eaf0;padding-top:8px">';

echo '<summary style="cursor:pointer;color:#17618b;font-weight:600;font-size:12px"></summary>';

echo '<div style="padding-top:10px">';

// ISP_LINKS_GRAPH24_DIRECT_V2
echo '<img class="isp-graph24-image" src="'.$url.'&amp;width=760&amp;height=220" loading="lazy" alt="Histórico de tráfego das últimas 24 horas" style="display:block;width:100%;height:auto;max-width:100%">';

echo '<div style="font-size:11px;margin-top:6px"><a href="'.$url.'" target="_blank" rel="noopener">Abrir gráfico completo ↗</a></div>';

echo '</div></details></div>';}
echo '</div></div></details>';}
echo '<div style="font-size:11px;color:#718096;margin-top:8px">Capacidade operacional estimada = soma das velocidades dos membros UP identificados em ports_stack. Não confirma negociação LACP/distribuição efetiva; valide no equipamento se necessário.</div></div></div>';


?>
<script>
// ISP_LINKS_GRAPH24_PERSIST_JS_V3
(function () {
  if (window.ISPGraph24PersistV3) return;
  window.ISPGraph24PersistV3 = true;

  var prefix = 'observium_isp_graph24_';

  function key(el) {
    return prefix + (el.getAttribute('data-graph-url') || '');
  }

  function restore() {
    document.querySelectorAll(
      'details.isp-graph24[data-graph-url]'
    ).forEach(function (el) {
      if (el.dataset.graph24Restored === '1') return;

      el.dataset.graph24Restored = '1';

      try {
        el.open = '0' === '1';
      } catch (e) {}
    });
  }

  document.addEventListener('toggle', function (event) {
    var el = event.target;

    if (!el.matches ||
        !el.matches('details.isp-graph24[data-graph-url]')) return;

    try {
      void 0;
    } catch (e) {}
  }, true);

  var observer = new MutationObserver(function () {
    restore();
  });

  function init() {
    restore();
    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

// ISP_LINKS_GRAPH24_LAZY_V1
(function () {
  if (window.ISPLinksGraph24LazyV1) return;
  window.ISPLinksGraph24LazyV1 = true;

  document.addEventListener('toggle', function (event) {
    var panel = event.target;

    if (!panel.matches ||
        !panel.matches('.isp-links-box details.isp-graph24') ||
        !panel.open) return;

    var img = panel.querySelector('img.isp-graph24-image');
    if (!img || img.hasAttribute('src')) return;

    img.setAttribute('src', img.getAttribute('data-src'));
  }, true);
})();
</script>
<script>
// ISP_LINKS_PERSISTENCE_V1
(function () {
  var PREFIX = 'observium-isp-links-v1-';

  function read(key) {
    try { return null; }
    catch (e) { return null; }
  }

  function write(key, value) {
    try { /* estado somente durante a pagina atual */ }
    catch (e) {}
  }

  function restore() {
    document.querySelectorAll('.isp-links-box').forEach(function (widget) {
      widget.querySelectorAll('details[data-isp-group]').forEach(function (group) {
        var category = group.getAttribute('data-isp-group');
        var saved = read('group-' + category);

        if (saved !== null) {
          group.open = saved === 'open';
        }
      });

      widget.querySelectorAll('.isp-native-rt').forEach(function (card) {
        var port = card.getAttribute('data-port');

        if (read('realtime-' + port) !== 'on') return;

        var panel = card.querySelector('.isp-native-rt-panel');
        var button = card.querySelector('.isp-native-rt-toggle');

        if (!panel || !button || panel.style.display !== 'none') return;

        button.click();
      });
    });
  }

  document.addEventListener('toggle', function (event) {
    var group = event.target;

    if (!group.matches ||
        !group.matches('.isp-links-box details[data-isp-group]')) return;

    write('group-' + group.getAttribute('data-isp-group'),
          group.open ? 'open' : 'closed');
  }, true);

  document.addEventListener('click', function (event) {
    var button = event.target.closest &&
                 event.target.closest('.isp-native-rt-toggle');

    if (!button) return;

    var card = button.closest('.isp-native-rt');
    if (!card) return;

    var port = card.getAttribute('data-port');
    var panel = card.querySelector('.isp-native-rt-panel');

    if (!panel) return;

    // O script original executa a alternância neste mesmo clique.
    // O estado anterior indica qual será o novo estado.
    write('realtime-' + port,
          panel.style.display === 'none' ? 'on' : 'off');
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restore);
  } else {
    restore();
  }
})();

// ISP_TRANSIT_NATIVE_REALTIME_V2
(function () {
  if (window.ISPNativeRealtimeCardsV2) return;
  window.ISPNativeRealtimeCardsV2 = true;

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.isp-native-rt-toggle');
    if (!button) return;

    var card = button.closest('.isp-native-rt');
    if (!card) return;

    var panel = card.querySelector('.isp-native-rt-panel');
    var frame = card.querySelector('.isp-native-rt-frame');
    var port = Number(card.getAttribute('data-port'));

    if (!panel || !frame || !Number.isInteger(port)) return;

    var active = panel.style.display !== 'none';

    if (active) {
      panel.style.display = 'none';
      frame.replaceChildren();
      button.textContent = 'Tempo real · 5s';
      button.className = 'btn btn-default btn-xs isp-native-rt-toggle';
      button.setAttribute('aria-expanded', 'false');
      return;
    }

    var object = document.createElement('object');
    object.type = 'image/svg+xml';
    object.data = 'graph-realtime.php?type=bits&id=' +
                  encodeURIComponent(port) + '&interval=5';
    object.style.width = '100%';
    object.style.height = '100%';
    object.style.display = 'block';

    frame.replaceChildren(object);
    panel.style.display = 'block';

    button.textContent = 'Desativar tempo real';
    button.className = 'btn btn-success btn-xs isp-native-rt-toggle';
    button.setAttribute('aria-expanded', 'true');
  });
})();

// ISP_TRANSIT_SYNC_V1
(function () {
  if (window.ISPTransitSyncV1) return;
  window.ISPTransitSyncV1 = true;

  function parseSpeed(text) {
    var match = String(text || '').trim().match(
      /([\d.,]+)\s*([TGMK]?)\s*(?:bits?\/s|bps)/i
    );
    if (!match) return null;

    var raw = match[1];
    var value;

    if (raw.includes(',') && raw.includes('.')) {
      value = Number(raw.replace(/\./g, '').replace(',', '.'));
    } else if (raw.includes(',')) {
      value = Number(raw.replace(',', '.'));
    } else {
      value = Number(raw);
    }

    var factors = {T: 1e12, G: 1e9, M: 1e6, K: 1e3};
    var factor = factors[(match[2] || '').toUpperCase()] || 1;

    return Number.isFinite(value) ? value * factor : null;
  }

  function formatSpeed(bits) {
    var unit = bits >= 1e9 ? 'Gbps' : 'Mbps';
    var value = bits >= 1e9 ? bits / 1e9 : bits / 1e6;

    return value.toLocaleString('pt-BR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }) + ' ' + unit;
  }

  function updateCard(card) {
    var panel = card.querySelector('.isp-native-rt-panel');
    var object = card.querySelector('.isp-native-rt-frame object');

    if (!panel || panel.style.display === 'none' || !object) return;

    var svg;
    try {
      svg = object.contentDocument;
    } catch (e) {
      return;
    }

    if (!svg) return;

    var input = svg.getElementById('graph_in_txt');
    var output = svg.getElementById('graph_out_txt');

    if (!input || !output) return;

    var rx = parseSpeed(input.textContent);
    var tx = parseSpeed(output.textContent);

    // Não substituir valores válidos por dados ainda não coletados.
    if (rx === null || tx === null) return;

    // ISP_TRANSIT_SCOPE_V3
      var linkCard = card.parentElement;
      if (!linkCard) return;
      var smalls = Array.from(linkCard.querySelectorAll('small'));
    var rxLabel = smalls.find(function (el) {
      return /DOWNLOAD\s*·?\s*RX/i.test(el.textContent);
    });
    var txLabel = smalls.find(function (el) {
      return /UPLOAD\s*·?\s*TX/i.test(el.textContent);
    });

    if (!rxLabel || !txLabel) return;

    var rxValue = rxLabel.nextElementSibling;
    var txValue = txLabel.nextElementSibling;

    if (rxValue) rxValue.textContent = formatSpeed(rx);
    if (txValue) txValue.textContent = formatSpeed(tx);

    // Capacidade informada no próprio card.
    var capacityText = Array.from(linkCard.querySelectorAll('div'))
      .filter(function (el) {
        return el.children.length === 0 &&
          /Capacidade informada:/i.test(el.textContent);
      })
      .map(function (el) { return el.textContent; })[0];

    var capacityMatch = capacityText &&
      capacityText.match(/Capacidade informada:\s*([\d.,]+\s*[TGMK]?\s*bps)/i);

    var capacity = capacityMatch ? parseSpeed(capacityMatch[1]) : null;

    if (capacity && capacity > 0) {
      [['RX', rx], ['TX', tx]].forEach(function (entry) {
        var label = Array.from(linkCard.querySelectorAll('span')).find(function (el) {
          return el.textContent.trim() === 'Utilização ' + entry[0];
        });

        if (!label) return;

        var row = label.parentElement;
        var percentage = 100 * entry[1] / capacity;
        var strong = row && row.querySelector('strong');

        if (strong) {
          strong.textContent = percentage.toLocaleString('pt-BR', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1
          }) + '%';
        }

        var barContainer = row && row.nextElementSibling;
        var bar = barContainer && barContainer.firstElementChild;

        if (bar) {
          bar.style.width = Math.min(100, Math.max(0, percentage)) + '%';
        }
      });
    }

    card.setAttribute('data-isp-live', 'true');
  }

  setInterval(function () {
    if (document.hidden) return;

    document.querySelectorAll('.isp-native-rt').forEach(function (card) {
      updateCard(card);
    });
  }, 1000);

  // Mantém objetos e cards dentro da largura disponível.
  var style = document.createElement('style');
  style.textContent =
    '.isp-links-box,.isp-links-box *{box-sizing:border-box;}' +
    '.isp-links-box{max-width:100%;min-width:0;}' +
    '.isp-links-box .isp-native-rt{' +
    'min-width:0;max-width:100%;}' +
    '.isp-links-box .isp-native-rt-frame{' +
    'min-width:0;max-width:100%;overflow:hidden;}' +
    '.isp-links-box .isp-native-rt-frame object{' +
    'max-width:100%;}';
  document.head.appendChild(style);
})();

</script>


<!-- ISP_GRAPH24_DYNAMIC_LABEL_V2 -->
<script>
(function () {
  if (window.ISPGraph24DynamicLabelV2) return;
  window.ISPGraph24DynamicLabelV2 = true;

  function update(details) {
    if (!details || !details.matches('details.isp-graph24')) return;
    var summary = details.querySelector(':scope > summary');
    if (summary) {
      summary.textContent = details.open
        ? 'Recolher gráfico 24h'
        : 'Expandir gráfico 24h';
    }
  }

  function scan(root) {
    if (!root) return;
    if (root.matches && root.matches('details.isp-graph24')) {
      update(root);
    }
    if (root.querySelectorAll) {
      root.querySelectorAll('details.isp-graph24').forEach(update);
    }
  }

  document.addEventListener('toggle', function (event) {
    update(event.target);
  }, true);

  function initialize() {
    scan(document);

    if (document.body) {
      new MutationObserver(function (records) {
        records.forEach(function (record) {
          record.addedNodes.forEach(function (node) {
            if (node.nodeType === 1) scan(node);
          });
        });
      }).observe(document.body, {
        childList: true,
        subtree: true
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
})();
</script>
