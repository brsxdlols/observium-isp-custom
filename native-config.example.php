<?php
// Copie somente para /etc/observium-isp/native-collectors.php (chmod 600).
// IDs referem-se ao cadastro local no Observium. NUNCA publicar este arquivo preenchido.
return [
    'rib_device_id' => 0,         // 0: desabilitado ate configurar
    'rib_oid' => '.1.3.6.1.2.1.4.24.3.0',
    'legacy_device_id' => 0,      // 0: somente coletor multiconcentrador
    'legacy_hostname' => '',      // hostname exato do dispositivo no Observium
    'legacy_sysname' => '',       // opcional, confere SNMP sysName quando preenchido
    'pppoe_huawei_oid' => '.1.3.6.1.4.1.2011.5.2.1.14.1.2.0',
    'pppoe_mikrotik_oid' => '.1.3.6.1.4.1.9.9.150.1.1.1.0',
];