# Ensaio privado dos patches do núcleo — fase de rollback

Execute `bash install.sh --core-rehearse` para testar os três patches que já estão em `/root/observium-isp-patches-revisao.zip` sobre cópias temporárias dos três arquivos **originais** de uma versão oficial do Observium CE. O script exige a mesma versão informada em `/opt/observium/includes/definitions/version.inc.php` e valida os hashes SHA-256 originais do manifesto.

**O que acontece:** os arquivos originais são lidos do TAR oficial, copiados a um diretório temporário, alterados usando GNU `patch` e comparados aos hashes dos arquivos personalizados. Na sequência, a reversão é simulada restaurando cópias dos originais e verificando os hashes novamente.

**O que não acontece:** nenhuma escrita em `/opt/observium`, nenhum reinício de Apache, nenhum acesso ao banco, nenhuma alteração dos cron e nenhum envio dos patches para o GitHub. A restauração ainda não é aplicada na instalação real. O pacote original pode ser baixado temporariamente de Observium.org (ou usar `ISP_CORE_BASELINE=/caminho/original.tar.gz`).

```bash
curl -fsSL https://raw.githubusercontent.com/brsxdlols/observium-isp-custom/main/install.sh -o /root/observium-isp-install.sh
bash /root/observium-isp-install.sh --core-rehearse
```

Para apontar outro arquivo de revisão: `ISP_CORE_PATCH_ZIP=/root/meus-patches.zip bash install.sh --core-rehearse`. **Nunca publique o ZIP no repositório público.**

**Limites:** teste de algoritmo/compatibilidade, não é homologação de código, licença ou segurança. Um patch pode conter endereços internos ou trechos proprietários; é necessário revisar e sanear o conteúdo real. Aplicação automática em produção, backup e rollback transacional só serão habilitados após revisão e testes completos numa instalação limpa.
