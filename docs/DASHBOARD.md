# Dashboard ISP — instalador automatizado (fase 3)

Este módulo cria um dashboard público **novo**, chamado **ISP - Network Operations**, com cinco widgets, somente quando ainda não existir um dashboard com esse nome. Não modifica nem substitui o dashboard padrão `dash_id=1`, layouts pessoais ou dashboards existentes.

## Conteúdo

- BGP: `isp_bgp`, meio superior esquerdo
- RIB e PPPoE: `isp_rib`, meio superior direito
- Alertas nativos: `old_status_table`, faixa horizontal
- Monitoramento de links: `isp_links`, largura total
- Legenda: `isp_legend`, rodapé

## Comandos

```bash
bash install.sh --dashboard-check
bash install.sh --dashboard-apply
```

O modo `--check` apenas analisa esquema e widgets e não altera o banco. `--apply` salva um export JSON das tabelas `dashboards` e `dash_widgets` em `/root/observium-isp-backups/dashboard-*` antes de tentar criar o novo layout. Esses JSON são backup para análise/recuperação controlada, não um script de restauração automatizada. Os widgets são adicionados numa transação SQL.

## Limitações IMPORTANTES

- O próprio Observium precisa já estar instalado e configurado com acesso ao MariaDB.
- O painel atual do servidor de produção possui personalizações específicas no JavaScript e no servidor AJAX de widgets que ainda **não** são provisionadas por este módulo. Logo o aspecto e recursos avançados (ocultação de alertas, modais e alinhamento especial) ainda podem ser diferentes numa instalação nova.
- O widget RIB/PPPoE depende de arquivos de coleta ainda **não provisionados**: `/opt/observium/isp-rib/rib.json` e `/opt/observium/isp-pppoe/history-multi.sqlite`.
- Este módulo não preenche dados coletados nem configura SNMP/BGP/PPPoE automaticamente.
- `--apply` permite criar um novo dashboard, mas nunca altera os dados de dashboards existentes.
- Não execute instalação nova em produção: `--new` somente para sistema limpo sem `/opt/observium`.
- Configuração dos usuários e as permissões do dashboard dependem do esquema da versão instalada. Os widgets de navegação do Observium requerem login.

## Publicação

Use o branch principal e verifique o commit e versões antes de implantar. Para produção, rode primeiro `--check` / `--dashboard-check`.

## Fonte PPPoE legada segura

O `isp_rib.inc.php` público exige que a fonte opcional `/opt/observium/isp-pppoe/dashboard.json` corresponda ao concentrador explicitamente definido no `config.php`. Não configure esses valores no repositório. No servidor, somente se utilizar essa fonte legada:

```php
// Exemplo ilustrativo; use os dados reais somente no config.php local.
$config['isp_pppoe_legacy_device_id'] = 123;
$config['isp_pppoe_legacy_hostname'] = 'exemplo.interno';
$config['isp_rib_router_label'] = 'Roteador BGP';
```

Sem as duas opções `isp_pppoe_legacy_*`, o arquivo legado é ignorado por segurança. O consolidado principal via `history-multi.sqlite` é independente e continua habilitado; o controle de atualização/parcialidade permanece igual. Em servidores com filtro legado embutido em código local, **não substitua diretamente** o widget antes de ajustar a configuração privada. A comparação local/remote deve ser revisada, e o instalador bloqueia substituição automática de arquivos diferentes.

