<?php
if (($mod['widget_type'] ?? '') !== 'isp_legend') { return; }

$items = [
 'Transit: OPERADORA' => 'Trânsito IP / upstream',
 'Peering: IX OU PARCEIRO' => 'Troca de tráfego',
 'CGNAT: EQUIPAMENTO' => 'Interligação CGNAT',
 'CORE: ENLACE' => 'Núcleo / backbone',
 'PPPOE: OLT' => 'Acesso PPPoE',
 'Trunk: SWITCH' => 'Tronco / agregação',
  'SERVER: SERVIDOR' => 'Servidores e virtualização',
  'Lag: BONDING' => 'Agregacao de links / LACP',
 'PTP: DESTINO' => 'Ponto a ponto',
 'MGMT: EQUIPAMENTO' => 'Gerência'
];

echo '<div class="box box-solid" style="height:100%;overflow:visible">';
echo '<div class="box-header"><h3 class="box-title">Legenda das Interfaces</h3></div>';
echo '<div class="box-content" style="padding:10px">';
echo '<details><summary style="cursor:pointer"><strong>Como classificar interfaces</strong> — clique para expandir</summary>';
echo '<div style="margin-top:10px">';

foreach ($items as $prefix => $desc) {
    echo '<div style="margin:5px 0"><code>' .
         htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') .
         '</code> — ' .
         htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') .
         '</div>';
}

echo '<p class="text-muted">Configure o prefixo no ifAlias do equipamento.
 A classificação depende da descoberta do Observium; os painéis ISP
 também reconhecem diretamente os prefixos configurados.</p>';
echo '</div></details></div></div>';
