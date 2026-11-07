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