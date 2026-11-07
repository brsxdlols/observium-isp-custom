# Categorias e seletor de visualização ISP

Este módulo instala **NAS, MGMT, Lag e os grupos ISP**, mantendo a preferência individual entre **Gráficos** (`/iftype/type=...`) e **Lista de Portas** (`/ports/ifAlias=...`) no `localStorage` de cada navegador.

## Uso seguro

Em um Observium existente:

```bash
curl -fsSL https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main/install.sh -o /root/observium-isp-install.sh
bash /root/observium-isp-install.sh --categories-check
bash /root/observium-isp-install.sh --categories-apply
```

`--categories-check` valida sem alterar. `--categories-apply` cria backup e instala o módulo. Não altera o banco, a descoberta SNMP, o dashboard ou os coletadores.

Na instalação limpa, `install.sh --new` aplica a integração ao final do instalador-base. Para aplicar todos os componentes presentes no repositório a uma instalação existente, usar `--check` e `--apply`, **após revisão do resultado**.

## Estrutura

- `category-ui/navbar-custom.inc.php`: complementa o menu Ports pelo hook nativo do Observium (`navbar-custom.inc.php`) com NAS, Lag e MGMT. Usa ícone nativo, evita entradas duplicadas e oculta o menu Servers, substituído pelo NAS.
- `category-ui/category-view.js`: alterna visualizações e salva uma preferência distinta por categoria no navegador (mesma chave V1 já usada no ambiente original).
- `category-ui/category-view.css`: estilos do seletor.
- `install-category-ui.sh`: valida a compatibilidade, instala os arquivos e registra os grupos no `config.php` somente quando ainda ausentes.

## Compatibilidade e salvaguardas

- Se a navbar já contiver `ISP_CATEGORY_VIEW_FIX_V3`, o instalador **não injeta outro seletor**, preservando a personalização existente.
- Se um dos arquivos de destino tiver conteúdo diferente, a operação **para com conflito**, em vez de sobrescrever customizações do operador.
- Mudanças nos arquivos existentes (`config.php` e `navbar.inc.php`) são precedidas de backup em `/root/observium-isp-category-backup-*`.
- Uma atualização upstream pode alterar o ponto de ancoragem `</header>`; nesse caso, o script recusa aplicar.
- Os menus dependem da inclusão nativa de `html/includes/navbar-custom.inc.php` na versão do Observium instalada.
- A página agregada exige que a descoberta classifique `port_descr_type` corretamente. O exemplo `NAS: DELL` foi validado no ambiente original, mas outros equipamentos podem precisar de nova descoberta.

## Pendente no instalador completo

A reprodução integral do dashboard atual requer exportação do layout de `dash_widgets`, rotinas de coleta RIB/PPPoE e patches separados de `widget.php`, `dashboard.js` e outras integrações. Este módulo não afirma provisionar esses itens.