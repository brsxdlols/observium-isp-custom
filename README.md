# Observium CE — Personalizações ISP

Base do instalador Observium Community para **Debian 12** e módulos ISP extraídos de um ambiente operacional (novembro de 2026).

## Situação do projeto

**Publicação inicial, não é um instalador completo de todas as personalizações.** O script `base/instalar-observium.sh` instala o Observium CE e configura inicialmente os grupos CGNAT/PPPOE. Os módulos próprios publicados nesta primeira versão incluem widgets de BGP, RIB IPv4, PPPoE, links e legendas, a página PPPoE e o JavaScript do dashboard. Esses arquivos **não são automaticamente registrados e ativados** apenas executando o instalador-base.

Os arquivos alterados do núcleo Observium (`navbar.inc.php`, `ajax/widget.php`, `pages/dashboard.inc.php`, `includes/dashboard-generate.inc.php`) não foram republicados integralmente. A [licença da edição Community](https://docs.observium.org/licenses/) prevê a distribuição de modificações separadas, como *patches*. Ainda precisamos construir patches compatíveis com a versão-base correspondente e validá-los antes de acrescentar a instalação automatizada das funções de dashboard e menus.

## Execução da instalação base

Em um **Debian 12 novo**:

```bash
sudo bash base/instalar-observium.sh
```

**Não rode no servidor em produção:** o instalador rejeita `/opt/observium` preexistente. Para aplicações futuras das personalizações, será necessário um instalador incremental com backup, validação e rollback.

## Personalizações existentes no ambiente de origem

- Dashboard ISP: BGP, RIB IPv4, PPPoE, monitoramento de links e uso
- Alertas de interface, filtros, ocultação e gerenciamento visual
- Categorias NAS, MGMT, LAG, TRANSIT, PEERING, CORE, CGNAT, PPPOE, PTP e TRUNK
- Alternância entre gráficos e lista de portas, com preferência individual no navegador
- Página de concentradores PPPoE e históricos quando coletores e banco estiverem presentes

**Ainda não incluídos:** código do coletor PPPoE multi, histórico SQLite, tarefas cron, coleta RIB e exportação do layout dos widgets no banco. Portanto, o projeto **não reproduz 100%** da instância atual.

## Segurança e portabilidade

- `config.php`, senhas, comunidades SNMP, tokens, bancos SQLite, logs e RRD não são publicados.
- IPs internos fixos do widget RIB/PPPoE foram removidos para a versão pública.
- Em RIB, o rótulo opcional pode ser configurado com `$config['isp_rib_router_label']`.
- Para uma instalação funcional dos módulos PPPoE, instale `php8.2-sqlite3` no Debian 12 e configure os coletores respectivos.
- Os arquivos JavaScript e PHP são de uma versão específica do Observium; **não sobrescreva o núcleo de uma versão diferente**.

## Licenças

Observium Community Edition tem licença própria baseada na QPL. O instalador baixa a versão Community diretamente do site oficial. Este repositório não redistribui o núcleo Observium modificado como um pacote independente.