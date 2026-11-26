#!/usr/bin/env bash
# Instala o AutoConfiança (site + área do cliente + sistema) numa máquina Ubuntu nova.
# Rodar como root:   bash instalar-servidor.sh
#
# Faz tudo: PHP 8.3, Nginx, MySQL, Composer, Node, código do GitHub, banco,
# usuário do dono, HTTPS (se o domínio já aponta para cá), agendador e
# preparação do deploy automático. Pode rodar de novo: o que já existe é mantido.
set -euo pipefail

DOMINIO=confiancarev.com.br
APP=/var/www/autoconfianca
REPO=git@github.com:canalof/autoconfianca.git
BANCO=autoconfianca
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a COMPOSER_ALLOW_SUPERUSER=1

passo() { echo; echo "== $*"; }
[ "$(id -u)" = 0 ] || { echo "Rode como root (sudo bash instalar-servidor.sh)."; exit 1; }
. /etc/os-release
[ "${ID:-}" = ubuntu ] || { echo "Este script é para Ubuntu (achei: ${PRETTY_NAME:-?})."; exit 1; }

# ---------------------------------------------------------------------------
passo "Dados do dono do sistema"
if [ -f "$APP/.env" ] && [ -d "$APP/vendor" ]; then
  echo "Já existe instalação em $APP. Vou só atualizar e conferir."
  EMAIL=""; NOME=""; CNPJ=""
else
  read -rp "E-mail do dono (login no sistema): " EMAIL
  read -rp "Nome do dono: " NOME
  read -rp "CNPJ da oficina (só números, Enter para deixar em branco): " CNPJ
fi

# ---------------------------------------------------------------------------
passo "1/9 Pacotes do sistema"
# Em algumas máquinas o PackageKit vem desligado ("masked") e o gancho dele faz o apt-get update falhar.
if [ -f /etc/apt/apt.conf.d/20packagekit ] && [ "$(systemctl is-enabled packagekit 2>/dev/null || true)" = masked ]; then
  mv /etc/apt/apt.conf.d/20packagekit /root/20packagekit.desligado
fi
apt-get update -qq
apt-get install -y -qq software-properties-common curl git unzip acl rsync ca-certificates gnupg >/dev/null
if ! apt-cache show php8.3-fpm >/dev/null 2>&1; then
  add-apt-repository -y ppa:ondrej/php >/dev/null
  apt-get update -qq
fi
apt-get install -y -qq nginx mysql-server certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd php8.3-sqlite3 >/dev/null
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
fi
if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 22 ]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
  apt-get install -y -qq nodejs >/dev/null
fi
echo "PHP $(php -r 'echo PHP_VERSION;'), Node $(node -v), $(composer --version 2>/dev/null | cut -d' ' -f1-3)"

# ---------------------------------------------------------------------------
passo "2/9 Acesso de leitura ao GitHub (repositório privado)"
install -d -m 700 /root/.ssh
CHAVE_GIT=/root/.ssh/autoconfianca_github
if [ ! -f "$CHAVE_GIT" ]; then
  ssh-keygen -q -t ed25519 -N "" -C "servidor-autoconfianca (leitura)" -f "$CHAVE_GIT"
fi
grep -q "Host github-autoconfianca" /root/.ssh/config 2>/dev/null || cat >> /root/.ssh/config <<EOF
Host github-autoconfianca
  HostName github.com
  User git
  IdentityFile $CHAVE_GIT
  IdentitiesOnly yes
EOF
grep -q "^github.com " /root/.ssh/known_hosts 2>/dev/null || ssh-keyscan -t ed25519 github.com 2>/dev/null >> /root/.ssh/known_hosts
REPO_LOCAL=github-autoconfianca:canalof/autoconfianca.git
until git ls-remote "$REPO_LOCAL" >/dev/null 2>&1; do
  cat <<EOF

  Abra https://github.com/canalof/autoconfianca/settings/keys/new
  Title: servidor
  Key (copie a linha inteira abaixo), deixe "Allow write access" DESMARCADO
  e clique em "Add key":

$(cat "$CHAVE_GIT.pub")

EOF
  read -rp "  Depois de adicionar, aperte Enter... " _
done
echo "GitHub OK."

# ---------------------------------------------------------------------------
passo "3/9 Código"
mkdir -p /var/www
if [ -f /etc/sudoers.d/torque-deploy ]; then
  echo "O código agora chega pelo deploy automático; nada a baixar."
elif [ -d "$APP/.git" ]; then
  git -C "$APP" pull -q --ff-only || true
elif [ ! -f "$APP/artisan" ]; then
  git clone -q "$REPO_LOCAL" "$APP"
fi
cd "$APP"
git config --global --add safe.directory "$APP" || true

# ---------------------------------------------------------------------------
passo "4/9 Banco de dados e .env"
if [ ! -f .env ]; then
  SENHA_BANCO=$(openssl rand -hex 20)
  cp .env.example .env
  definir() { # definir CHAVE valor  (troca ou acrescenta no .env)
    if grep -qE "^#? ?$1=" .env; then sed -i -E "s|^#? ?$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
  }
  definir APP_ENV production
  definir APP_DEBUG false
  definir APP_URL "https://$DOMINIO"
  definir DB_CONNECTION mysql
  definir DB_HOST 127.0.0.1
  definir DB_PORT 3306
  definir DB_DATABASE "$BANCO"
  definir DB_USERNAME "$BANCO"
  definir DB_PASSWORD "$SENHA_BANCO"
  definir LOG_LEVEL warning
  mysql -e "CREATE DATABASE IF NOT EXISTS \`$BANCO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '$BANCO'@'localhost' IDENTIFIED BY '$SENHA_BANCO';
    ALTER USER '$BANCO'@'localhost' IDENTIFIED BY '$SENHA_BANCO';
    GRANT ALL ON \`$BANCO\`.* TO '$BANCO'@'localhost'; FLUSH PRIVILEGES;"
  echo "Banco criado (a senha ficou só no .env)."
else
  echo ".env já existe, mantido."
fi
chown root:www-data .env 2>/dev/null || true
chmod 640 .env

# ---------------------------------------------------------------------------
passo "5/9 Dependências, telas e tabelas"
export COMPOSER_ALLOW_SUPERUSER=1
composer install -q --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund --loglevel=error >/dev/null
npm run build >/tmp/autoconfianca-build.log 2>&1 || { tail -20 /tmp/autoconfianca-build.log; exit 1; }
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force -q
mkdir -p storage/app/public storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
php artisan migrate --force
[ -L public/storage ] || php artisan storage:link -q
chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache -q && php artisan route:cache -q && php artisan view:cache -q

if [ -n "${EMAIL:-}" ]; then
  echo; echo ">>> Usuário do dono (ANOTE A SENHA que aparece abaixo):"
  php artisan torque:empresa "AutoConfiança" "$EMAIL" --nome="${NOME:-Responsável}" ${CNPJ:+--cnpj="$CNPJ"} || true
fi

# ---------------------------------------------------------------------------
passo "6/9 Nginx"
cat > /etc/nginx/sites-available/autoconfianca <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMINIO www.$DOMINIO _;
    root $APP/public;
    index index.php;
    client_max_body_size 20m;
    server_tokens off;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
    location ~* \.(css|js|woff2|jpg|jpeg|png|webp|svg|ico)\$ { expires 30d; access_log off; }
}
EOF
ln -sf /etc/nginx/sites-available/autoconfianca /etc/nginx/sites-enabled/autoconfianca
rm -f /etc/nginx/sites-enabled/default
nginx -t -q && systemctl reload nginx
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
  ufw allow OpenSSH >/dev/null; ufw allow "Nginx Full" >/dev/null
fi

# ---------------------------------------------------------------------------
passo "7/9 HTTPS"
IP_MAQUINA=$(curl -fsS4 --max-time 10 https://api.ipify.org || hostname -I | awk '{print $1}')
IP_DOMINIO=$(getent ahostsv4 "$DOMINIO" | awk 'NR==1{print $1}' || true)
IP_WWW=$(getent ahostsv4 "www.$DOMINIO" | awk 'NR==1{print $1}' || true)
HTTPS=nao
if [ "$IP_DOMINIO" = "$IP_MAQUINA" ]; then
  DOMS=(-d "$DOMINIO"); [ "$IP_WWW" = "$IP_MAQUINA" ] && DOMS+=(-d "www.$DOMINIO")
  EMAIL_CERT=${EMAIL:-$(grep -m1 '^MAIL_FROM_ADDRESS=' .env | cut -d= -f2 | tr -d '"')}
  if certbot --nginx -n --agree-tos --redirect -m "${EMAIL_CERT:-admin@$DOMINIO}" "${DOMS[@]}" >/dev/null 2>&1; then
    HTTPS=sim; echo "Certificado instalado."
  else
    echo "O certificado falhou. Depois rode: certbot --nginx ${DOMS[*]}"
  fi
else
  echo "O domínio $DOMINIO ainda não aponta para esta máquina ($IP_MAQUINA; hoje aponta para: ${IP_DOMINIO:-nada})."
  echo "No registro do domínio, crie: A  @  $IP_MAQUINA   e   A  www  $IP_MAQUINA"
  echo "Depois rode este script de novo (ele só completa o que falta)."
fi

# ---------------------------------------------------------------------------
passo "8/9 Agendador (lembretes, notas, backup)"
LINHA="* * * * * cd $APP && php artisan schedule:run >> /dev/null 2>&1"
( crontab -u www-data -l 2>/dev/null | grep -vF "$APP && php artisan schedule:run"; echo "$LINHA" ) | crontab -u www-data -

# ---------------------------------------------------------------------------
passo "9/9 Deploy automático"
# O deploy pelo GitHub passa a cuidar do código; a cópia do git deixa de ser usada.
if [ ! -f /etc/sudoers.d/torque-deploy ]; then
  HOST_DEPLOY=$DOMINIO; [ "$HTTPS" = sim ] || HOST_DEPLOY=$IP_MAQUINA
  bash scripts/deploy/preparar-servidor.sh "$APP" "$HOST_DEPLOY"
else
  echo "Já preparado antes. Para gerar outra chave: bash scripts/deploy/preparar-servidor.sh"
fi

cat <<EOF

=====================================================================
Instalado.
  Site:              http://$IP_MAQUINA   (e https://$DOMINIO quando o domínio apontar)
  Área do cliente:   /area-do-cliente
  Sistema:           /entrar
Falta preencher no $APP/.env os dados da oficina (OFICINA_*) e do WhatsApp
(EVOLUTION_URL, EVOLUTION_KEY). Depois: cd $APP && php artisan config:cache
=====================================================================
EOF
