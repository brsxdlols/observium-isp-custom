# Contadores IPv4 e IPv6 — Observium ISP

## Definicoes importantes

- **BGP por sessao IPv4/IPv6:** `bgpPeers_cbgp.AcceptedPrefixes` por `device_id + bgpPeer_id + afi + safi`. O widget `isp_bgp` mostra os prefixos aceitos de IPv4 e IPv6 para cada sessao, quando o poller forneceu dados. **Nao somar sessoes**: prefixos repetidos entre peers nao equivalem ao numero de rotas unicas no BGP.
- **RIB IPv4:** contador existente `ipv4_entries`, vindo do OID configurado `rib_oid` (padrao legado `.1.3.6.1.2.1.4.24.3.0`, `ipCidrRouteNumber`). Reflete as entradas da tabela SNMP, nao o total unico de BGP nem a FIB de hardware.
- **RIB IPv6:** campo opcional `ipv6_entries`, consultado via `rib_ipv6_oid` configurado manualmente.
- **FIB IPv4 e IPv6:** campos opcionais `fib_ipv4_entries` e `fib_ipv6_entries`, consultados respectivamente por `fib_ipv4_oid` e `fib_ipv6_oid`, **somente** com OIDs comprovadamente associados ao encaminhamento efetivo no equipamento. Alguns dispositivos distinguem tabela de rotas e FIB; um contador de rotas generico nao comprova FIB.
- **BGP total unico (Loc-RIB):** ainda requer coleta propria por familia e equipamento; nao inferir a partir de AcceptedPrefixes de peers, IP Routing nem FIB.

## Configuracao da coleta

Arquivo privado local: `/etc/observium-isp/native-collectors.php`. Manter os valores existentes (`rib_device_id`, `rib_oid`) e **opcionalmente** adicionar:

```php
'rib_ipv6_oid' => 'OID_SNMP_VALIDADO_PARA_ROTAS_IPV6',
'fib_ipv4_oid' => 'OID_SNMP_VALIDADO_PARA_FIB_IPV4',
'fib_ipv6_oid' => 'OID_SNMP_VALIDADO_PARA_FIB_IPV6',
```

Os textos acima sao marcadores explicativos: **nao copie como OIDs reais**. Confirmar por modelo/VRP, manual MIB e SNMP walk antes de configurar. Sem OID validado, manter as chaves ausentes; cards apresentam indisponibilidade em vez de inventar valores.

A coleta mantem `ipv4_entries`, preservando o cache anterior `isp-rib/rib.json`. Campos opcionais so aparecem no JSON quando ha coleta numerica valida e nao negativa.

## Sem risco para producao

Os arquivos `custom/html/includes/widgets/isp_bgp.inc.php`, `custom/html/includes/widgets/isp_rib.inc.php` e `native/isp-rib/collect.php` do repositorio foram atualizados. **O repositorio nao atualiza automaticamente os arquivos locais preexistentes**: o instalador protege arquivos divergentes. Nao execute `--apply` no Observium em producao para sobrescreve-los.

Antes de aprovar uma migracao: execute `php -l` nas copias em teste, confirme schema real `bgpPeers_cbgp`, confira OIDs do Huawei NE8000, consistencia AFI/SAFI, SNMP IPv6 e dados do cache, e valide o widget autenticado. O proprio Observium pode usar contadores diferentes por dispositivo/versao.
