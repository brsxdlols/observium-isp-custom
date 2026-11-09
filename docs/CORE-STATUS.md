# Validação do núcleo Observium — dashboard ISP

## Situação

O ambiente operacional possui as personalizações de dashboard `ISP_DASHBOARD_STABILITY_V1`, `ISP_STATUS_MANAGER_V2`, `ISP_DEVICE_MODAL_V3`, `ISP_PORT_HIDDEN_FILTER_V5` no `html/js/dashboard.js`, integrações de alertas e inventário em `html/ajax/widget.php`, menus e seletor V3 em `html/includes/navbar.inc.php`. Os arquivos modificados do núcleo não são publicados integralmente para evitar distribuir o Observium Community modificado e expor dados de produção.

O instalador público **ainda não aplica todos esses patches** em uma instalação nova. O dashboard inicial de cinco widgets é criado em um painel separado, mas sem os patches do núcleo os recursos de modais, alinhamento e ocultação de portas podem não funcionar. O script de auditoria distingue isso com erro de saída `4`, em vez de imprimir sucesso completo.

## Executar sem alterar nada

```bash
bash install.sh --core-check
```

O comando verifica arquivos, marcadores e sintaxe; não modifica banco, cron ou dados de dispositivos. A execução `--check` também exibe essa auditoria ao final. O modo `--new` executa a checagem após a aplicação para indicar explicitamente se a instalação ficou incompleta.

## Para concluir a automação

É necessário produzir **patches incrementais verificados contra a versão exata do Observium CE** (principalmente `html/ajax/widget.php`, `html/js/dashboard.js`, `html/includes/navbar.inc.php`). Uma versão nova do Observium pode reorganizar esses arquivos; a aplicação deve falhar de forma segura em caso de pré-imagem diferente, com backup e rollback. Não copie os arquivos inteiros do servidor atual para cima de outra versão.

A auditoria confirma apenas presença de marcadores e sintaxe, não funcionamento no navegador ou autenticidade do binário. Testes futuros precisam cobrir respostas AJAX, sessão autenticada, persistência do modal e execução real dos três cron nativos RIB/PPPoE.
## Preparação dos patches por revisão exata — etapa de exportação

O modo `--core-export` foi acrescentado ao instalador. Ele extrai **somente os cinco arquivos nativos relacionados ao painel** e grava um arquivo TAR privado com manifesto SHA-256, no diretório `/root/observium-isp-exports/`. Não copia `config.php`, bases SQL/SQLite, RRD, cron nem logs; não altera o Observium. O TAR contém código-fonte nativo da instalação e **não deve ser publicado diretamente em um GitHub público**.

```bash
curl -fsSL https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main/install.sh -o /root/observium-isp-install.sh
bash /root/observium-isp-install.sh --core-export
```

Os cinco arquivos são `html/includes/navbar.inc.php`, `html/ajax/widget.php`, `html/includes/dashboard-generate.inc.php`, `html/pages/dashboard.inc.php` e `html/js/dashboard.js`. É necessário comparar o snapshot privado do ambiente funcional com o núcleo *pristine* da **mesma revisão**, e publicar apenas patches específicos que não contenham segredos. Se a pré-imagem esperada não corresponder, não aplicar o patch. Até obter a pré-imagem exata e revisar os diffs, **nenhuma rotina automatizada de patch do núcleo está habilitada**.

A captura privada não indica que patches estejam prontos ou validados; executar em produção apenas `--core-check` ou `--core-export`. Arquivos exportados podem conter identificadores internos embutidos no código — revise antes de compartilhar.


## Gerar rascunhos privados dos cinco patches

Depois de executar `--core-export` e preservar o arquivo no servidor, rode:

```bash
bash install.sh --core-build
```

O processo usa `ISP_CORE_BASELINE` se fornecido; por padrão tenta o pacote oficial sem alterações em `/opt/observium-community-latest.tar.gz`. Se não existir, baixa o pacote Community do site oficial para diretório temporário, **sem extrair em /opt/observium**. `ISP_CORE_EVIDENCE` permite escolher um pacote privado de evidências; sem ele, será escolhido o mais recente em `/root/observium-isp-exports/`.

Um gerador Python confere os SHA-256 dos cinco arquivos personalizados contra `manifest.txt` e compara com os mesmos caminhos do pacote oficial; gera cinco diffs privados `.patch` e um `manifest.json` contendo hashes de origem e destino e estatística de alterações. Salva-os em `/root/observium-isp-exports/core-patch-review-*/` (permissão restrita) e não os aplica.

**Restrição:** o pacote oficial `latest` pode ser outra revisão em relação à instalação de origem. A comparação não comprova igualdade de versões; a versão-base precisa ser verificada antes de transformar esses rascunhos em patches instaláveis. Os diffs podem conter identificadores, endereços internos e trechos do código Observium; não publique o diretório de revisão no GitHub. O repositório contém somente o **gerador**, e não os patches confidenciais.

A publicação de patches finais depende de verificar a pré-imagem por SHA-256 e revisar manualmente cada diff. Aplicação automática e rollback de patches do núcleo **ainda não estão habilitados**.


## Validação privada do ZIP de patches — modo `--core-patch-check`

A partir desta atualização, o instalador aceita um ZIP privado contendo exatamente `manifest.json`, `html__includes__navbar.inc.php.patch`, `html__ajax__widget.php.patch` e `html__js__dashboard.js.patch`. O comando:

```bash
bash install.sh --core-patch-check
```

usa `/root/observium-isp-patches-revisao.zip` por padrão. Configure `ISP_CORE_PATCH_ZIP=/caminho/arquivo.zip` para outro caminho. Ele verifica nomes e estrutura de entradas, hashes SHA-256 do manifesto e cabeçalhos dos diffs. Para um arquivo ainda original, simula o `patch` em cópia temporária e confere o hash final; para um arquivo que já está personalizado, apenas informa que o SHA atual corresponde ao destino e **não** executa a simulação, por ausência da versão original naquele diretório.

O validador **não modifica o Observium**. Não faz upload, não publica os diffs, não considera os patches homologados e não executa rollback (pois não há aplicação). Para homologação efetiva, revisar código e dados privados, testar em instalação limpa da mesma revisão e verificar os efeitos dos AJAX no navegador. Aplicação real com backup/rollback ainda não está habilitada.
