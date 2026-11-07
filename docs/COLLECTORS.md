# Coleta RIB e PPPoE — integração segura

Esta fase publica um **ingestor de snapshots JSON**, não um coletor SNMP de fabricante. O ingestor não contém credenciais, IPs de clientes nem OIDs presumidos. Um adaptador real de cada roteador/concentrador deve fornecer os arquivos de entrada com valores atualizados. Sem fontes configuradas, não há coleta; o painel mostrará SEM DADOS ou dados da integração preexistente.

## Instalação

```bash
bash install.sh --collectors-check
bash install.sh --collectors-apply
```

`--check` geral também verifica o ingestor. `--apply` geral instala o framework (mas não cria fontes). A execução protege arquivos divergentes e não substitui os coletores locais atuais.

## Configuração no servidor (não publicar)

Crie `/etc/observium-isp/sources.json` com permissão 600:

```json
{
  "rib_source": "/var/lib/observium-isp-input/rib.json",
  "pppoe_source": "/var/lib/observium-isp-input/pppoe.json",
  "legacy_device_id": 2
}
```

`legacy_device_id` é opcional. Use os IDs reais do Observium, não copie valores de exemplo sem verificar.

RIB: `{"timestamp": EPOCH_ATUAL, "ipv4_entries": 1048576}` (substitua EPOCH_ATUAL pelo timestamp numérico corrente).

PPPoE: `{"devices": [{"device_id": 2, "name":"BNG", "hostname":"bng.example.local", "source":"adaptador-local", "timestamp": EPOCH_ATUAL, "online":1234}]}`.

O ingestor requer inteiros não negativos, IDs únicos, strings válidas e timestamps entre 1 hora atrás e 2 minutos à frente. O ID deve ser compatível com o dispositivo no Observium; nesta fase ainda não existe verificação cruzada com MariaDB.

## Destinos e compatibilidade

- `/opt/observium/isp-rib/rib.json` recebe RIB atomicamente.
- `/opt/observium/isp-pppoe/history-multi.sqlite` recebe dispositivos e amostras nas tabelas usadas pelos widgets existentes; amostras com mais de 90 dias são removidas.
- O arquivo legado `dashboard.json` é opcional e contém apenas a amostra corrente do dispositivo configurado. **Isso não recria as séries históricas de 12 horas** coletadas pelo sistema anterior.
- O widget público também requer, para o JSON legado, `isp_pppoe_legacy_device_id` e `isp_pppoe_legacy_hostname` em `config.php` local.
- O cron `/etc/cron.d/observium-isp-collectors` só executa o ingestor quando existe a configuração privada. Usa `flock` e período de 5 minutos.

## Segurança operacional

Antes da ativação, salve backup dos coletores atuais, valide as fontes e execute `python3 /opt/observium/isp-collectors/collect.py --check`. O instalador protege o arquivo de ingestor com conteúdo diferente e cron não gerenciado. Não sobrescreve o núcleo Observium.

**Pendente para completar a reprodução do ambiente de origem:** identificar e portar as rotinas efetivas de obtenção dos dados RIB e PPPoE (SNMP/API/CLI), regras de agendamento existentes, autenticação e série histórica legada. Não é seguro inventar comandos, OIDs ou credenciais para esses equipamentos.
