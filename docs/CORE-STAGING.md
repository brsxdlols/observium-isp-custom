# Validação privada dos três patches do núcleo

O utilitário `tools/deploy-staged-core.py` foi adicionado à branch de revisão para preparar a **futura implantação transacional** dos três componentes do núcleo: navbar, AJAX widget e dashboard.js. Não contém código original da edição Community nem os patches privados do FLYNET.

O código atual usa hashes rígidos somente da instância CE 26.1.14545 já verificada e da personalização específica na navbar do segundo Observium. Uma versão diferente deve ser recusada. Não é ainda um instalador genérico para qualquer versão/provedor.

## No novo Observium — preparação privada já concluída

Os arquivos foram preparados em `/root/observium-isp-v96-i_5qm6wk` com:
- `originais/html/{includes/navbar.inc.php,ajax/widget.php,js/dashboard.js}`
- `preparado/html/{includes/navbar.inc.php,ajax/widget.php,js/dashboard.js}`

Mantenha esses arquivos privados. O modo `--check` verifica os seis hashes esperados e o estado de produção, além da sintaxe PHP e JavaScript. **Node.js é requisito para autenticar a sintaxe JS**, não instale versões arbitrárias do runtime.

Na branch em revisão:

```bash
RAW=https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/feature/pppoe-chart-periods-and-alert-layout
curl -fsSL "$RAW/install.sh" -o /root/observium-isp-review.sh
bash -n /root/observium-isp-review.sh
ISP_REPO_RAW="$RAW" ISP_CORE_STAGE=/root/observium-isp-v96-i_5qm6wk bash /root/observium-isp-review.sh --core-stage-check
ISP_REPO_RAW="$RAW" ISP_CORE_STAGE=/root/observium-isp-v96-i_5qm6wk bash /root/observium-isp-review.sh --core-stage-rehearse
```

O `--core-stage-rehearse` injeta uma falha depois do segundo arquivo e comprova restauração dos originais em diretório temporário. **Não modifica** `/opt/observium`.

O utilitário interno possui implementação de aplicação explícita `--apply --confirm-production`, mas o instalador único `install.sh` **não a expõe nesta fase**. Não a execute: ainda requer testes independentes em ambiente descartável e revisão de licenciamento, segurança, recuperação de falhas abruptas e comportamento funcional da aplicação.

**Estado:** a ausência de conflitos de hash ou de erros de sintaxe não comprova que os menus, os widgets AJAX e o dashboard funcionarão com autenticação. Os coletores reais precisam de fontes explícitas. Nada foi enviado ao `main` e nenhum arquivo privado foi publicado.

