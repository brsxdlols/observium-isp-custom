# Categorias DNS e HYPERVISOR + prioridade de FIB

- **DNS** e **HYPERVISOR** foram acrescentadas à integração pública das categorias de interfaces. `DNS:` e `HYPERVISOR:` podem ser usados como prefixos de `ifAlias` nas portas; os menus apontam para a lista de portas correspondente. O seletor oferece vistas **Gráficos** e **Lista de Portas**, começando pela lista para essas duas categorias.
- **HYPERVISOR** é uma categoria única para equipamentos Proxmox, VMware/ESXi e outros. O nome do fabricante não é inferido automaticamente: a classificação depende de cadastro/descrição.
- Se a intenção for agrupar *dispositivos* inteiros (e não portas), isso exige uma regra distinta de grupo de dispositivos; não confundir categoria de interface com sistema operacional. A alteração atual corresponde ao mesmo modelo de categorias de portas já implementado no projeto.
- No dashboard de rotas, a ordem dos cartões passa a ser **FIB IPv4, FIB IPv6, RIB IPv4, RIB IPv6 e PPPoE**. O valor da FIB exige uma coleta específica real; não é derivado automaticamente de IP Routing, AcceptedPrefixes nem da RIB.
- Correção adicional: o processamento dos campos `ipv6_entries`, `fib_ipv4_entries` e `fib_ipv6_entries` no widget agora preenche as variáveis de destino corretamente.

**Produção:** o Observium atual contém o seletor V3 integrado no núcleo e personalizações locais nos widgets. Os scripts públicos não sobrescrevem automaticamente esses arquivos. Antes de aplicar os novos menus ou trocar widgets em produção, comparar os arquivos locais, fazer backup, validar PHP/JS e preservar a navegação atual. Executar apenas `--categories-check` para diagnóstico; **não executar `--categories-apply` diretamente em uma instalação personalizada sem revisar conflitos**.
