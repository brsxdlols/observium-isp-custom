# Instalação única — estado de homologação

A instalação desejada será executada por um único comando `curl`, com backup, verificação de compatibilidade, aplicação de personalizações próprias, patches do núcleo e teste final. **Ainda não está homologada para produção.**

## Modo novo: `--full-check`

O diagnóstico de prontidão já pode ser executado na branch de revisão, sem alterar o Observium:

```bash
curl -fsSL https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/feature/pppoe-chart-periods-and-alert-layout/install.sh -o /root/observium-isp-review-install.sh
ISP_REPO_RAW=https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/feature/pppoe-chart-periods-and-alert-layout bash /root/observium-isp-review-install.sh --full-check
```

O modo `--full-check` **sempre retorna código 4 enquanto a integração completa não estiver aprovada**. Isto é proposital: a existência de arquivos e de um pacote privado não certifica implantação segura nem funcionamento de recursos AJAX no navegador.

O comando consulta a versão e o inventário de módulos, compara hashes dos 3 arquivos do núcleo com o manifesto do pacote privado local (`/root/observium-isp-core-v80-PRIVADO.tar.gz`) e informa conflitos. **Não instala nem altera nada** e não envia o pacote privado ao GitHub.

## Etapas faltantes para `--full-apply`

1. Revisar licenciamento, segredos e segurança dos diffs nativos e preparar distribuição legalmente compatível.
2. Homologar merges de `navbar.inc.php`, `widget.php` e `dashboard.js` por versão exata do Observium, incluindo variações locais.
3. Implementar uma transação com backup prévio, falhas injetadas e rollback verificado no ambiente de ensaio, depois testes controlados de produção.
4. Garantir operação idempotente de grupos, dashboards e coletores; não inferir OIDs, credenciais SNMP nem fontes locais.
5. Testar menus, dashboards, AJAX, filtros, gráficos e histórico PPPoE com sessão autenticada no destino.
6. Liberar somente depois o modo único de instalação com as verificações de versão e consentimento de operação.

**Importante:** `bash install.sh --apply` na versão atual do repositório é um aplicador **parcial**, não `--full-apply`. Rodá-lo não conclui as integrações do núcleo.

## Preservação de dados

Os dados existentes do Observium (configurações, dispositivos, interfaces, RRD e bancos) devem permanecer intactos ou ter backup e restauração comprovados antes de qualquer mutação. Os coletores só podem ser ativados com configuração explícita e fontes verificadas; nenhuma amostra fictícia será gerada.
