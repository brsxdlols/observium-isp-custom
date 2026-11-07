<?php
/** Observium ISP - seed a new dashboard, never change existing dashboards. */
$cli = true;
require_once '/opt/observium/includes/observium.inc.php';

$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--apply'], true)) { fwrite(STDERR, "Use --check or --apply\n"); exit(2); }
function ispSeedFail(string $s): void { fwrite(STDERR, "ERRO: $s\n"); exit(1); }
function ispTableColumns(string $table): array {
    $rows = dbFetchRows('SHOW COLUMNS FROM `' . $table . '`');
    if (!$rows) ispSeedFail('Tabela ausente: ' . $table);
    $columns = [];
    foreach ($rows as $r) $columns[$r['Field']] = $r;
    return $columns;
}
$dashCols = ispTableColumns('dashboards');
$widgetCols = ispTableColumns('dash_widgets');
foreach (['dash_id','dash_name'] as $col) if (!isset($dashCols[$col])) ispSeedFail('Campo requerido ausente: dashboards.' . $col);
foreach (['dash_id','widget_type','widget_config','x','y','width','height'] as $col) {
    if (!isset($widgetCols[$col])) ispSeedFail('Campo requerido ausente: dash_widgets.' . $col);
}
$name = 'ISP - Network Operations';
$existing = dbFetchRows('SELECT `dash_id` FROM `dashboards` WHERE `dash_name` = ?', [$name]);
if (count($existing) > 1) ispSeedFail('Mais de um dashboard com o nome reservado; revisar manualmente.');
if ($existing) {
    $id=(int)$existing[0]['dash_id'];
    $total=(int)dbFetchCell('SELECT COUNT(*) FROM `dash_widgets` WHERE `dash_id` = ?', [$id]);
    echo "EXISTENTE: dashboard $id, widgets=$total. Nao altera nem duplica.\n";
    exit(0);
}
$widgets = [
  ['isp_bgp', 0,0,6,8],
  ['isp_rib', 6,0,6,8],
  ['old_status_table',0,8,12,7],
  ['isp_links',0,15,12,14],
  ['isp_legend',0,29,12,3],
];
// The native ajax widget handler dynamically loads custom widgets; the
// collector-backed RIB/PPPoE panel displays missing-data states until configured.
foreach (['isp_bgp','isp_rib','isp_links','isp_legend'] as $nameWidget) {
    if (!is_readable('/opt/observium/html/includes/widgets/'.$nameWidget.'.inc.php')) {
        if ($mode === '--apply') ispSeedFail('Modulo PHP ausente: '.$nameWidget);
        echo 'PENDENTE: modulo PHP ausente: '.$nameWidget."\n";
    }
}
if ($mode === '--check') {
    echo "CHECK OK: um novo dashboard ISP pode ser criado; ".count($widgets)." widgets; nenhum dado modificado.\n";
    exit(0);
}
// Do not add widgets to a potentially unrelated default dashboard.
$data=['dash_name'=>$name];
if (isset($dashCols['descr'])) $data['descr']='Monitoramento ISP: BGP, RIB/PPPoE, alertas e links';
if (isset($dashCols['is_public'])) $data['is_public']=1;
if (isset($dashCols['dash_order'])) $data['dash_order']=20;
if (isset($dashCols['slug'])) $data['slug']='isp-network-operations';
// Explicit insertion with transaction: rollback if any widget insert fails.
if (dbQuery('START TRANSACTION') === false) ispSeedFail('Nao foi possivel iniciar transacao SQL');
try {
    $id=(int)dbInsert($data,'dashboards');
    if ($id <= 0) throw new RuntimeException('Insert dashboard retornou id invalido');
    foreach ($widgets as [$type,$x,$y,$w,$h]) {
        $result=dbInsert(['dash_id'=>$id,'widget_type'=>$type,'widget_config'=>'{}','x'=>$x,'y'=>$y,'width'=>$w,'height'=>$h], 'dash_widgets');
        if (!$result) throw new RuntimeException('Falha inserindo widget '.$type);
    }
    if (dbQuery('COMMIT') === false) throw new RuntimeException('Falha no COMMIT SQL');
    echo "CRIADO: dashboard ISP id=$id; widgets=".count($widgets).".\n";
} catch (Throwable $e) {
    dbQuery('ROLLBACK');
    ispSeedFail($e->getMessage());
}