#!/usr/bin/env bash
# ISP_ROUTING_PROD_V1: all preflight before writes; no navbar changes.
set -Eeuo pipefail
umask 077
[[ $(id -u) -eq 0 ]] || { echo 'ERRO: root obrigatorio'; exit 2; }
OBS="${OBSERVIUM_DIR:-/opt/observium}"
MODE="${1:---check}"
[[ "$MODE" == --check || "$MODE" == --apply ]] || { echo 'Use --check ou --apply'; exit 2; }
[[ "$OBS" == /opt/observium && -f "$OBS/config.php" && -f "$OBS/html/includes/navbar.inc.php" ]] || { echo 'ERRO: Observium esperado em /opt/observium'; exit 2; }
for cmd in curl python3 php; do command -v "$cmd" >/dev/null || { echo "ERRO: falta $cmd"; exit 2; }; done
OLD=1fc8c504b886989a6e38ccd72115db8f8692e15d
NEW=5c8bfd839f1c245c500da23ec765aebf1f436f96
RAW=https://raw.githubusercontent.com/brsxdlols/observium-isp-custom
TMP=$(mktemp -d /root/observium-routing-prod.XXXXXX)
trap 'rm -rf -- "$TMP"' EXIT
FILES=(
 'custom/html/includes/widgets/isp_bgp.inc.php:html/includes/widgets/isp_bgp.inc.php'
 'custom/html/includes/widgets/isp_rib.inc.php:html/includes/widgets/isp_rib.inc.php'
)
for pair in "${FILES[@]}"; do
  src=${pair%%:*}; rel=${pair#*:}
  mkdir -p "$TMP/old/$(dirname "$rel")" "$TMP/new/$(dirname "$rel")"
  curl -fsSL --retry 2 "$RAW/$OLD/$src" -o "$TMP/old/$rel"
  curl -fsSL --retry 2 "$RAW/$NEW/$src" -o "$TMP/new/$rel"
  php -l "$TMP/new/$rel" >/dev/null
 done
python3 - "$OBS" "$TMP" "$MODE" <<'PY'
import os,re,shutil,subprocess,sys,tempfile
from pathlib import Path
from datetime import datetime
obs=Path(sys.argv[1]); work=Path(sys.argv[2]); mode=sys.argv[3]
rels=['html/includes/widgets/isp_bgp.inc.php','html/includes/widgets/isp_rib.inc.php']
targets=[]
conflict=False
for rel in rels:
    dest=obs/rel
    if dest.is_symlink() or not dest.is_file():
        print('CONFLITO: arquivo ausente ou link:',rel); conflict=True; continue
    current=dest.read_bytes(); old=(work/'old'/rel).read_bytes(); new=(work/'new'/rel).read_bytes()
    if current==new: print('JA ATUALIZADO:',rel)
    elif current==old:
        print('PRONTO:',rel); targets.append((dest,new))
    else:
        print('CONFLITO: customizacao local diferente, nao sera substituida:',rel)
        conflict=True
config=obs/'config.php'
if config.is_symlink():
    print('CONFLITO: config.php e link'); conflict=True
else:
    original=config.read_bytes()
    try: source=original.decode('utf-8')
    except UnicodeDecodeError:
        print('CONFLITO: config.php nao esta em UTF-8'); conflict=True; source=''
    marker='ISP_DNS_HYPERVISOR_GROUPS_V1'
    if source and marker not in source:
        block="""\n// ISP_DNS_HYPERVISOR_GROUPS_V1\nforeach (['DNS', 'HYPERVISOR'] as $ispGroup) {\n    if (!in_array($ispGroup, (array)($config['int_groups'] ?? []), true)) {\n        $config['int_groups'][] = $ispGroup;\n    }\n}\n"""
        endings=list(re.finditer(r'\?>',source))
        if endings and not source[endings[-1].end():].strip():
            pos=endings[-1].start(); source=source[:pos]+block+source[pos:]
        else: source+=block
        prepared=source.encode('utf-8')
        stage=work/'config.php'; stage.write_bytes(prepared)
        if subprocess.run(['php','-l',str(stage)],capture_output=True).returncode:
            print('CONFLITO: config.php preparada nao passou no lint'); conflict=True
        else: targets.append((config,prepared));print('PRONTO: categoria DNS / HYPERVISOR')
    elif source: print('JA CONFIGURADO: DNS / HYPERVISOR')
if conflict:
    print('BLOQUEADO: nenhuma alteracao realizada; revisar conflitos locais.')
    sys.exit(4)
if mode=='--check':
    print('PREFLIGHT OK; arquivos a atualizar:',len(targets),'(nenhuma alteracao realizada)')
    sys.exit(0)
if not targets:
    print('SEM ALTERACOES: arquivos ja correspondem ao destino');sys.exit(0)
backup=Path('/root/observium-isp-backups')/('routing-'+datetime.now().strftime('%Y%m%d-%H%M%S')+'-'+str(os.getpid()))
backup.mkdir(parents=True,mode=0o700)
written=[]
try:
    for dest,_ in targets:
        copy=backup/dest.relative_to(obs)
        copy.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(dest,copy)
    print('BACKUP:',backup)
    for dest,content in targets:
        prior=backup/dest.relative_to(obs)
        if dest.read_bytes()!=prior.read_bytes(): raise RuntimeError('Destino mudou durante backup: '+str(dest))
        fd,name=tempfile.mkstemp(prefix='.isp-update-',dir=dest.parent)
        try:
            with os.fdopen(fd,'wb') as stream:stream.write(content);stream.flush();os.fsync(stream.fileno())
            st=dest.stat()
            os.chmod(name,st.st_mode&0o7777);os.chown(name,st.st_uid,st.st_gid)
            os.replace(name,dest)
            written.append(dest)
        finally:
            if os.path.exists(name):os.unlink(name)
        if dest.read_bytes()!=content or subprocess.run(['php','-l',str(dest)],capture_output=True).returncode:
            raise RuntimeError('Verificacao falhou: '+str(dest))
        print('APLICADO:',dest.relative_to(obs))
    print('SUCESSO: backup preservado em',backup)
except BaseException:
    print('ERRO: iniciando rollback dos arquivos escritos',file=sys.stderr)
    for dest in reversed(written):
        source=backup/dest.relative_to(obs)
        shutil.copy2(source,dest)
        if dest.read_bytes()!=source.read_bytes():
            print('ERRO CRITICO NO ROLLBACK:',dest,file=sys.stderr)
    raise
print('OBS: navbar V3 nao foi alterada; menus dependem da navegacao existente.')
print('OBS: FIB/IPv6 so tera numeros quando a coleta SNMP estiver configurada.')
PY