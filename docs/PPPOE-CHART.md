# Atualização incremental do gráfico PPPoE (FLYNET)

Esta migração cobre **somente** a página existente `/opt/observium/html/pages/pppoe.inc.php`, conforme a implementação do FLYNET validada em 21/11/2026. Não é uma instalação completa de PPPoE e não configura coletores, fontes SNMP, cron ou SQLite.

## Alterações

- Períodos adicionais: **30min** (bucket 60s), **1h** (60s), **3h** (120s), **6h** (180s).
- Preserva os períodos existentes: **12h** (300s), **24h** (300s), **7d** (1800s), **30d** (7200s).
- Continuidade visual: `Math.max(750, ispMultiBucket * 2.5)` para não quebrar artificialmente a linha nas coletas de 5 minutos. Não cria amostras, não altera o limiar de validade das leituras e não conecta lacunas maiores que o limite.
- Renomeia o alerta de subida `Alta` ou `Volta` para **Recuperação**, mantendo **Queda**.
- Largura do quadro **Recuperação**: 116px; mantém **Queda** 67px, **Retorno** 78px, **Sem coleta** 94px.

**Atenção semântica:** a rotina original de eventos identifica aumentos bruscos, mas não comprova que eles recuperam uma queda anterior. O novo rótulo é apenas uma alteração visual. Correlação de eventos está fora do escopo.

## Uso seguro

A branch de revisão contém a implementação; **não execute a versão da branch em produção antes de revisar e aprovar o PR**. Depois da integração ao `main`:

```bash
bash install.sh --pppoe-chart-check
bash install.sh --pppoe-chart-apply
```

`--check` não escreve nenhum arquivo. `--apply` exige root, verifica assinaturas e valores dos arrays, cria backup de arquivo e SHA-256, valida `php -l` antes e depois, substitui atomicamente e tenta restaurar o backup se a validação pós-instalação falhar. Uma instalação já atualizada é reconhecida e não é reescrita. Personalizações diferentes das estruturas conhecidas são bloqueadas, não sobrescritas.

**Não há implantação automática** por meio do `--apply` geral nem pelo `--new`. Isso protege Observiums que não possuam esta página ou usem uma versão distinta. O modo exige a página PPPoE customizada reconhecível; versões futuras do núcleo podem exigir revisão.

Para executar os testes unitários em checkout:

```bash
python3 -m unittest discover -s tests -p 'test_pppoe_chart.py' -v
```

## Limitações e próximos passos

- A validação automática verifica a transformação dos arquivos e sintaxe PHP; não substitui testes visuais no navegador, com sessão autenticada.
- O pacote privado dos patches do núcleo (`tar.gz`) **não faz parte** desta contribuição. A navbar, `widget.php` e `dashboard.js` permanecem em fluxo separado de revisão/licença, compatibilidade e rollback real.
- Banco SQLite PPPoE, RRD, inventário de dispositivos e tarefas cron não são tocados.
