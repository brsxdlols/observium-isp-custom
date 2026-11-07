# Estado de integração do instalador ISP

Última atualização: 2026-11-07.

## Já automatizado

- Instalação-base oficial Observium CE no Debian 12 por `install.sh --new`, utilizando `base/instalar-observium.sh`.
- Instalação e sintaxe dos widgets ISP próprios: `isp_bgp`, `isp_links`, `isp_rib`, `isp_legend`.
- Implantação da página própria `html/pages/pppoe.inc.php`, desde que não substitua arquivo local diferente.
- Registro idempotente de `CGNAT`, `PPPOE`, `TRUNK`, `PTP`, `MGMT` e `NAS` em `config.php`.
- Backup local, proteção contra substituições conflitantes, preflight `--check`.
- Dependência PHP SQLite instalada pela modalidade `--new`.

## Pendente — não é seguro aplicar automaticamente ainda

- Patches contextuais para `html/includes/navbar.inc.php` (menu e seletor V3).
- Patches contextuais para `html/ajax/widget.php` (alertas, filtros e inventário).
- Patches para `html/js/dashboard.js` e demais integrações nativas do dashboard.
- Criação/registro de tipos de widgets e disposição inicial (layout) no banco.
- Coletores PPPoE e RIB, jobs de coleta, formato de `dashboard.json`/`rib.json` e histórico `history-multi.sqlite`.
- Teste final numa VM limpa idêntica ao Debian 12 alvo.

Arquivos do Observium modificados na origem não foram republicados integralmente; é necessário produzir patches adequados à revisão compatível do núcleo.

## Limites

Não executa migrações do banco de produção, não toca nos dados do RRD e não altera o cron. Uma instalação nova do Observium não apresentará os painéis personalizados completos até a conclusão dos itens pendentes.