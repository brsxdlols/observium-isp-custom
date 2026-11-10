# Smoke de integração ISP — modo `--core-smoke`

Este modo **somente leitura** complementa os ensaios transacionais já aprovados. Ele verifica marcadores de integrações no `navbar.inc.php`, `widget.php` e `dashboard.js`; executa `php -l` em quatro arquivos PHP; executa `node --check` no dashboard se o Node.js estiver disponível; e informa somente a idade de arquivos de snapshot RIB/PPPoE. Não lê o conteúdo dos snapshots e não publica configurações/credenciais.

```bash
curl -fsSL https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main/install.sh -o /root/observium-isp-install.sh
bash -n /root/observium-isp-install.sh
bash /root/observium-isp-install.sh --core-smoke
```

Códigos de saída: `0` para ausência de falhas críticas, `4` para falhas de arquivos ou sintaxe, `2` para erro operacional. `WARN` indica cobertura incompleta (por exemplo, Node.js ausente, snapshot desatualizado ou marcador não encontrado); um resultado sem falhas não prova funcionamento real.

Este teste **não executa requisições autenticadas, ações AJAX, polling SNMP, consultas SQL nem interação com navegador**. Portanto, a homologação funcional ainda exige validar manualmente os menus, o painel, os modais, persistência das preferências, alertas e atualizações AJAX em uma instalação de teste, com usuário autenticado. Nenhum patch é aplicado em produção por este modo.
