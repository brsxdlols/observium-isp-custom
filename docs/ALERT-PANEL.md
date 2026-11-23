# ISP ALERT PANEL V1 — extensão opcional

Objetivo: botão de **Tela cheia** no widget existente *Status Warnings and Notifications*, abrindo uma sobreposição com tabela filtrável à esquerda e resumo lateral à direita. Fecha com Escape, clique fora ou botão Fechar. A estrutura continua responsiva.

## Limites deliberados desta versão

- **Não** altera o código nativo do widget, o banco, SNMP, histórico ou agendamento.
- Categoriza apenas as linhas que a tabela **já mostra**: Device Down, Port Down, degradadas, sem coleta ou outras. Não reivindica contagem total de alarmes do sistema e não mostra linhas ocultas ou não carregadas.
- **Não inventa sinal óptico**: RX/TX permanece com aviso explícito de que a fonte SNMP ainda não foi integrada. Próxima versão precisa mapear OIDs reais por tipo de equipamento, unidades e limiares verificados antes de emitir alertas ópticos.
- Tabela de expansão é um espelho textual somente leitura; as ações nativas (incluindo Ocultar e Gerenciar) continuam no widget original.
- O instalador contém preflight para um único `</header>` em `navbar.inc.php`; se não existir ou a estrutura for divergente, bloqueia em vez de forçar uma alteração.
- Está numa branch de revisão, não no `main`. Ainda precisa ser validado visualmente no FLYNET.

## Instalação gradual

```bash
RAW=https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/feature/pppoe-chart-periods-and-alert-layout
curl -fsSL "$RAW/install.sh" -o /root/observium-isp-review-install.sh
bash -n /root/observium-isp-review-install.sh
ISP_REPO_RAW="$RAW" bash /root/observium-isp-review-install.sh --alert-panel-check
# Só após aprovar o check e verificar o backup:
# ISP_REPO_RAW="$RAW" bash /root/observium-isp-review-install.sh --alert-panel-apply
```

O modo de aplicação instala apenas `html/js/isp-alert-panel.js`, `html/css/isp-alert-panel.css` e a inclusão externa na navbar, fazendo backup do arquivo existente. Há proteção contra divergência de arquivos, lint PHP e JS. Isso **não** significa testes completos da interface nem rollback após queda brusca de energia.

## Integração posterior

Identificar em FLYNET a implementação do widget de status e os dados reais de ópticos/erros: tipos de sensores existentes, unidades, frescor da coleta e limiares. Só então adicionar contadores e cartões de sinal/degradação. Para instalador único definitivo, integrar esta extensão após homologação, sem a habilitar automaticamente por enquanto.
