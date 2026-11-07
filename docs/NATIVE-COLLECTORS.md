# Coletores PHP nativos: RIB SNMP e sessões PPPoE (Huawei/MikroTik)

Os quatro scripts em `native/` foram portados do ambiente operacional do Observium, removendo `device_id`, hostname e sysName específicos. Não são substitutos genéricos de SNMP: os OIDs padrão foram mantidos do sistema validado, e alguns modelos podem não oferecer esses contadores.

- `isp-rib/collect.php`: obtém número de entradas RIB IPv4 via `snmp_get()` do Observium e grava `rib.json` atomicamente.
- `isp-pppoe/collect.php`: obtém sessões do BNG legado, valida hostname e, opcionalmente, sysName; grava `history.sqlite` e usa `export.php` para gerar `dashboard.json` com 12 horas de amostras.
- `isp-pppoe/collect-multi.php`: descobre candidatos cadastrados pelo fabricante, OID e nome de função BNG; mantém `history-multi.sqlite`, importa o histórico legado sem apagá-lo e não remove dados antigos.
- `isp-pppoe/export.php`: exporta a série legada e metadados para o widget.

## Instalação segura

`bash install.sh --native-check` apenas valida. `bash install.sh --native-apply` instala os PHP ausentes, com proteção se um coletor local diferente já existir. O modo `--new` da instalação unificada instala esses arquivos, mas **não habilita os cron até que os dispositivos estejam configurados**. Em servidores legados, manter os arquivos e cron atuais.

Configuração privada: `/etc/observium-isp/native-collectors.php`. Copiar `native-config.example.php`, preencher `rib_device_id`, `legacy_device_id`, `legacy_hostname` e `legacy_sysname` (se desejar conferir); permissões 0600. Todos os IDs se referem ao banco Observium da instalação. Não publique este arquivo preenchido no GitHub.

## Cron na instalação configurada

Após confirmar, manualmente, SNMP, IDs e saída dos scripts, pode-se instalar cron com estas três entradas (não substitua arquivos `cron` preexistentes sem conferir):

```cron
*/5 * * * * root /usr/bin/timeout 90 /usr/bin/php /opt/observium/isp-rib/collect.php >> /var/log/observium-isp-rib.log 2>&1
*/5 * * * * root /usr/bin/timeout 120 /usr/bin/php /opt/observium/isp-pppoe/collect.php >> /opt/observium/isp-pppoe/collect.log 2>&1
*/5 * * * * root /usr/bin/timeout 120 /usr/bin/php /opt/observium/isp-pppoe/collect-multi.php >> /opt/observium/isp-pppoe/collect-multi.log 2>&1
```

O `collect-multi.php` permite coleta mesmo sem `legacy_device_id`, desde que exista algum candidato Huawei/MikroTik com nome que identifique BNG; já o coletor legado exige `legacy_device_id` e `legacy_hostname`. Cada BNG deve ser cadastrado e SNMP deve estar operacional. Requisitos: PHP SQLite3, `sqlite3` extension, cron e funções do Observium.

### Limites

O modo automático ainda não seleciona equipamentos nem descobre as credenciais SNMP; as definições de SNMP são mantidas pelo Observium. Os testes de sintaxe PHP passaram, mas não foram realizados testes SNMP nos equipamentos do usuário. Não execute `--native-apply` em produção se os coletores já existem: o programa deve proteger qualquer diferença.