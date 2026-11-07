<?php
/** ISP public category navigation integration. No Observium core source copied. */
if (!isset($navbar['ports']['entries']) || !is_array($navbar['ports']['entries'])) {
    return;
}

$entries = &$navbar['ports']['entries'];
$names = ['NAS', 'MGMT', 'LAG', 'TRANSIT', 'PEERING', 'CGNAT', 'CORE', 'PPPOE', 'PTP', 'TRUNK'];
$defaults = ['NAS' => 'iftype', 'MGMT' => 'ports', 'LAG' => 'ports'];
$found = [];

foreach ($entries as $key => &$entry) {
    if (!is_array($entry) || !isset($entry['title'])) {
        continue;
    }
    $name = strtoupper(trim((string)$entry['title']));
    if ($name === 'SERVER' || $name === 'SERVERS') {
        // Native Servers category is intentionally replaced by NAS.
        unset($entries[$key]);
        continue;
    }
    if (!in_array($name, $names, true)) {
        continue;
    }
    if (isset($found[$name])) {
        unset($entries[$key]); // Avoid duplicate category entries.
        continue;
    }
    $found[$name] = true;
    if ($name === 'MGMT' || $name === 'LAG') {
        $entry['url'] = generate_url(['page' => 'ports', 'ifAlias' => $name . ':']);
    } elseif ($name === 'NAS') {
        $entry['url'] = generate_url(['page' => 'iftype', 'type' => $name]);
    }
    $entry['icon'] = $config['icon']['port'] ?? 'sprite-ethernet';
}
unset($entry);

foreach (['NAS', 'LAG', 'MGMT'] as $name) {
    if (isset($found[$name])) {
        continue;
    }
    $page = ($name === 'NAS') ? 'iftype' : 'ports';
    $url = ($page === 'iftype') ?
        generate_url(['page' => $page, 'type' => $name]) :
        generate_url(['page' => $page, 'ifAlias' => $name . ':']);
    $entries[] = [
        'url' => $url,
        'icon' => $config['icon']['port'] ?? 'sprite-ethernet',
        'title' => $name
    ];
}
unset($entries);