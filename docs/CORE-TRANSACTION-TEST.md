# Ensaio transacional do núcleo — `--core-tx-test`

O modo `--core-tx-test` exige o ZIP **privado** de revisão e utiliza os três arquivos originais da edição Observium Community correspondente. Todos os patches são validados por SHA-256 e preparados em área temporária. O script executa duas passagens:

1. **Commit simulado:** pré-valida os arquivos, aplica as três atualizações por substituição atômica exclusivamente numa árvore descartável e confirma os três hashes finais.
2. **Falha injetada:** repete a operação, provoca erro depois do segundo arquivo e reverte os arquivos já alterados a partir dos backups, confirmando que os hashes originais foram restaurados.

O teste retorna sucesso apenas se ambos os cenários forem concluídos. Nenhum arquivo real do Observium é modificado. Não reinicia serviços, não altera cron, não escreve no banco, não publica patches nem substitui o modo `--apply`. É um **teste dos mecanismos**, ainda não é uma instalação em produção nem homologação do código.

```bash
curl -fsSL https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main/install.sh -o /root/observium-isp-install.sh
bash -n /root/observium-isp-install.sh
bash /root/observium-isp-install.sh --core-tx-test
```

Requisitos: `python3`, `patch`, `curl`, ZIP privado em `/root/observium-isp-patches-revisao.zip` e pacote original disponível em `ISP_CORE_BASELINE` ou pelo download oficial temporário. Arquivos do cliente, configurações e credenciais **nunca** devem ser enviados a repositório público.

**Próximas condições de homologação:** revisão humana dos diffs privados para segredos/código licenciado, testes funcionais numa instalação limpa, política de manutenção e rollback completo da instalação real. O aplicador em produção permanece desabilitado.
