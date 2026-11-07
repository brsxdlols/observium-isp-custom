#!/bin/bash
set -Eeuo pipefail

# Observium Community - Debian 12 + menus CGNAT/PPPOE
if [[ $(id -u) -ne 0 ]]; then echo 'ERRO: execute como root.'; exit 1; fi
if [[ ! -r /etc/os-release ]]; then echo 'ERRO: sistema operacional nao identificado.'; exit 1; fi
. /etc/os-release
if [[ "$ID" != debian || "$VERSION_ID" != 12 ]]; then echo 'ERRO: requer Debian 12.'; exit 1; fi
if [[ -e /opt/observium ]]; then echo 'ERRO: /opt/observium ja existe. Instalacao nova apenas.'; exit 1; fi

read -r -p 'Usuario administrador do Observium [admin]: ' OBS_ADMIN
OBS_ADMIN=${OBS_ADMIN:-admin}
while :; do
  read -r -s -p 'Senha do administrador do Observium: ' OBS_ADMIN_PASS; echo
  [[ -n "$OBS_ADMIN_PASS" ]] && break
  echo 'A senha nao pode ficar vazia.'
done
read -r -p 'Nome ou dominio do servidor [observium.local]: ' OBS_DOMAIN
OBS_DOMAIN=${OBS_DOMAIN:-observium.local}
read -r -p 'Timezone [America/Sao_Paulo]: ' TIMEZONE
TIMEZONE=${TIMEZONE:-America/Sao_Paulo}
if [[ ! "$OBS_DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ ]]; then echo 'ERRO: dominio invalido.'; exit 1; fi
if [[ ! -f "/usr/share/zoneinfo/$TIMEZONE" || "$TIMEZONE" == *'..'* ]]; then echo 'ERRO: timezone invalida.'; exit 1; fi

DB_NAME=observium
DB_USER=observium
DB_PASS=$(openssl rand -hex 24)

printf '\n[1/11] Timezone e pacotes\n'
timedatectl set-timezone "$TIMEZONE"
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
 apache2 mariadb-server mariadb-client cron libapache2-mod-php8.2 \
 php8.2-cli php8.2-mysql php8.2-gd php8.2-bcmath php8.2-mbstring \
 php8.2-opcache php8.2-curl php8.2-xml php8.2-zip php-apcu php-pear \
 snmp fping rrdtool subversion whois mtr-tiny ipmitool graphviz \
 imagemagick python3 python3-mysqldb python3-pymysql python-is-python3 \
 wget curl unzip ca-certificates openssl
systemctl enable --now mariadb apache2 cron

printf '\n[2/11] Baixando Observium\n'
wget -O /opt/observium-community-latest.tar.gz https://www.observium.org/observium-community-latest.tar.gz
tar -xzf /opt/observium-community-latest.tar.gz -C /opt
[[ -f /opt/observium/discovery.php && -f /opt/observium/html/includes/navbar.inc.php ]] || { echo 'ERRO: arquivos Observium ausentes.'; exit 1; }

printf '\n[3/11] Banco de dados\n'
mysql <<MYSQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
MYSQL

printf '\n[4/11] Configuracao do Observium\n'
cd /opt/observium
cp config.php.default config.php
# Escreve credenciais ao final: substituem eventuais valores do modelo.
cat >> config.php <<PHP

// Configuracao gerada pelo instalador
\$config['db_host'] = 'localhost';
\$config['db_name'] = '$DB_NAME';
\$config['db_user'] = '$DB_USER';
\$config['db_pass'] = '$DB_PASS';
PHP
mkdir -p /opt/observium/rrd /opt/observium/logs
chown -R www-data:www-data /opt/observium/rrd /opt/observium/logs
chmod 755 /opt/observium
chown root:www-data /opt/observium/config.php
chmod 640 /opt/observium/config.php

printf '\n[5/11] Personalizando grupos e icones\n'
# Rotina independente: pode ser reexecutada apos upgrades do Observium.
cat > /usr/local/sbin/observium-menus-customizados <<'CUSTOM'
#!/bin/bash
set -Eeuo pipefail
CONFIG=/opt/observium/config.php
NAV=/opt/observium/html/includes/navbar.inc.php
[[ -f "$CONFIG" && -f "$NAV" ]] || { echo 'ERRO: arquivos do Observium ausentes.'; exit 1; }
python3 - "$CONFIG" "$NAV" <<'PY'
from pathlib import Path
import sys, shutil, datetime, re
config, nav = map(Path, sys.argv[1:])
s = config.read_text()
# Adiciona cada grupo apenas quando ainda nao estiver configurado.
for group in ('CGNAT', 'PPPOE'):
    pattern = r"\$config\s*\[\s*['\"]int_groups['\"]\s*\]\s*\[\s*\]\s*=\s*\(?\s*['\"]" + group + r"['\"]"
    if not re.search(pattern, s, re.I):
        s += f"\n$config['int_groups'][] = '{group}';\n"
# Mapeamento de icones, com identificadores ja testados no Observium CE.
for group, icon in (('CGNAT','network'), ('PPPOE','users')):
    pattern = r"\$config\s*\[\s*['\"]custom_port_icons['\"]\s*\]\s*\[\s*['\"]" + group + r"['\"]\s*\]\s*="
    if not re.search(pattern, s, re.I):
        s += f"$config['custom_port_icons']['{group}'] = '{icon}';\n"
nav_src = nav.read_text()
old = "'icon' => $config['icon']['port'], 'title' => str_replace(',', ' & ', $int_type)"
new = "'icon' => ($config['custom_port_icons'][strtoupper($int_type)] ?? $config['icon']['port']), 'title' => str_replace(',', ' & ', $int_type)"
if new not in nav_src:
    if nav_src.count(old) != 1:
        raise SystemExit('ERRO: menu do Observium mudou. Nenhum arquivo alterado. Revisar navbar.inc.php.')
    stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    shutil.copy2(nav, nav.with_name(nav.name + '.bak-' + stamp))
    nav.write_text(nav_src.replace(old, new, 1))
    print('Menu personalizado com backup do navbar.inc.php.')
else:
    print('Menu ja personalizado; sem duplicacao.')
if s != config.read_text():
    config.write_text(s)
    print('Grupos CGNAT e PPPOE configurados.')
PY
php -l "$CONFIG"
php -l "$NAV"
CUSTOM
chmod 750 /usr/local/sbin/observium-menus-customizados
/usr/local/sbin/observium-menus-customizados

printf '\n[6/11] Criando estrutura do banco\n'
cd /opt/observium
./discovery.php -u

printf '\n[7/11] Administrador\n'
./adduser.php "$OBS_ADMIN" "$OBS_ADMIN_PASS" 10
unset OBS_ADMIN_PASS

printf '\n[8/11] Apache\n'
cat > /etc/apache2/sites-available/observium.conf <<APACHE
<VirtualHost *:80>
    ServerName ${OBS_DOMAIN}
    ServerAdmin webmaster@localhost
    DocumentRoot /opt/observium/html
    <Directory /opt/observium/html>
        Options FollowSymLinks MultiViews
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/observium_error.log
    CustomLog \${APACHE_LOG_DIR}/observium_access.log combined
</VirtualHost>
APACHE
a2dismod mpm_event >/dev/null 2>&1 || true
a2enmod mpm_prefork php8.2 rewrite
a2dissite 000-default.conf >/dev/null 2>&1 || true
a2ensite observium.conf
apache2ctl configtest
systemctl restart apache2

printf '\n[9/11] Cron de descoberta, coleta e manutencao\n'
cat > /etc/cron.d/observium <<'CRON'
33 */6 * * * root /opt/observium/observium-wrapper discovery >> /dev/null 2>&1
*/5 * * * * root /opt/observium/observium-wrapper discovery --host new >> /dev/null 2>&1
*/5 * * * * root /opt/observium/observium-wrapper poller >> /var/log/observium-poller-cron.log 2>&1
13 5 * * * root /opt/observium/housekeeping.php -ysel >> /dev/null 2>&1
47 4 * * * root /opt/observium/housekeeping.php -yrptb >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/observium
chown root:root /etc/cron.d/observium
systemctl restart cron

printf '\n[10/11] MIBs e testes iniciais\n'
if [[ -f /etc/snmp/snmp.conf ]]; then
    sed -i '/^[[:space:]]*mibs[[:space:]]*:/d' /etc/snmp/snmp.conf
    echo 'mibs : /opt/observium/mibs/rfc:/opt/observium/mibs/net-snmp' >> /etc/snmp/snmp.conf
fi
cd /opt/observium
./discovery.php -h localhost || true
./poller.php -h localhost || true

printf '\n[11/11] Credenciais e limpeza\n'
cat > /root/observium-credenciais.txt <<CREDS
OBSERVIUM INSTALADO EM: $(date)
Painel: http://${OBS_DOMAIN}
IP: http://$(hostname -I | awk '{print $1}')
Administrador: ${OBS_ADMIN}
Senha: definida durante a instalacao
Banco: ${DB_NAME}
Usuario DB: ${DB_USER}
Senha DB: ${DB_PASS}
Menus: CGNAT (network) e PPPOE (users)
Reaplicar icones apos atualizacoes: /usr/local/sbin/observium-menus-customizados
CREDS
chmod 600 /root/observium-credenciais.txt
rm -f /opt/observium-community-latest.tar.gz
printf '\n===========================================\n OBSERVIUM INSTALADO COM SUCESSO\n===========================================\n'
echo "Acesso: http://${OBS_DOMAIN}"
echo "Acesso IP: http://$(hostname -I | awk '{print $1}')"
echo "Admin: $OBS_ADMIN"
echo 'Credenciais: /root/observium-credenciais.txt'
echo 'Cron: /etc/cron.d/observium'
echo 'Log de polling: /var/log/observium-poller-cron.log (apos primeira execucao)'
echo 'Menus personalizados: CGNAT e PPPOE'
echo 'Reaplicar apos upgrade: /usr/local/sbin/observium-menus-customizados'
echo 'Cadastrar equipamento: cd /opt/observium && ./add_device.php IP_OU_HOST COMMUNITY v2c'
