# Instalação na máquina nova (Ubuntu 24.04)

Site, área do cliente e sistema rodam juntos em **confiancarev.com.br**:

| Endereço | O que é |
|---|---|
| `/` e `/servicos/...` | Site público |
| `/area-do-cliente` | Área do cliente (celular + código pelo WhatsApp) |
| `/entrar` e `/sistema` | Login e sistema da equipe |

## Como fica no servidor

- Site institucional estático em `/var/www/autocentro` (raiz de confiancarev.com.br), copiado da pasta `portal/` do repositório.
- Sistema em `/var/www/autoconfianca`, aberto em `https://confiancarev.com.br/sistema` (equipe em `/sistema/entrar`, cliente em `/sistema/area-do-cliente`).
- `APP_URL=https://confiancarev.com.br/sistema` no `.env`.

## Jeito rápido: um script só

Na máquina nova, como `root`, rode `bash instalar-servidor.sh` (o arquivo é `scripts/instalar-servidor.sh`). Ele faz todos os passos abaixo: pede o e-mail, o nome do dono e o CNPJ, mostra uma chave para colar em *Deploy keys* do GitHub (só leitura), instala tudo, cria o banco com senha aleatória, cria o usuário do dono (mostra a senha), liga o HTTPS se o domínio já aponta para a máquina e no fim mostra os 4 segredos do deploy automático. Pode rodar de novo: completa o que faltou (por exemplo o HTTPS depois que o DNS apontar).

Os passos manuais abaixo são o que o script faz, para conferência ou conserto.

Comandos como `root`. Troque `SENHA_DO_BANCO` por uma senha forte.

## 1. Pacotes

```bash
apt update && apt -y upgrade
apt -y install nginx mysql-server git unzip acl certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

Só para converter os relatórios antigos de OS (`.xls`) na importação: `apt -y install libreoffice-calc-nogui`.

## 2. Banco de dados

```bash
mysql -e "CREATE DATABASE autoconfianca CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'autoconfianca'@'localhost' IDENTIFIED BY 'SENHA_DO_BANCO';
GRANT ALL ON autoconfianca.* TO 'autoconfianca'@'localhost'; FLUSH PRIVILEGES;"
```

## 3. Código e `.env`

O primeiro envio pode ser pelo próprio deploy (passo 6) ou clonando:

```bash
mkdir -p /var/www && cd /var/www
git clone https://github.com/canalof/autoconfianca.git   # pede usuário e token do GitHub (repositório privado)
cd autoconfianca
cp .env.example .env
```

No `.env`, ajuste:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://confiancarev.com.br
DB_CONNECTION=mysql
DB_DATABASE=autoconfianca
DB_USERNAME=autoconfianca
DB_PASSWORD=SENHA_DO_BANCO
OFICINA_WHATSAPP=55DDNUMERO         # só números, com 55 e DDD
OFICINA_TELEFONE="(19) 0000-0000"
OFICINA_RUA="Rua ..., 123"
OFICINA_BAIRRO=...
OFICINA_CIDADE=...
OFICINA_CEP=00000-000
EVOLUTION_URL=...                   # WhatsApp automático e código da área do cliente
EVOLUTION_KEY=...
```

Enquanto os campos `OFICINA_*` não forem preenchidos, o site mostra o texto entre colchetes (ex.: `[Rua, número]`).

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan torque:empresa "AutoConfiança" dono@confiancarev.com.br --nome="Responsável" --cnpj=CNPJ_DA_OFICINA
```

O último comando cria a oficina e o usuário do dono e mostra a senha. Depois, no sistema, em **Empresa**, preencha o nome da instância da Evolution API (WhatsApp).

## 4. Nginx

`/etc/nginx/sites-available/autoconfianca`:

```nginx
server {
    listen 80;
    server_name confiancarev.com.br www.confiancarev.com.br;
    root /var/www/autoconfianca/public;
    index index.php;
    client_max_body_size 20m;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
ln -s /etc/nginx/sites-available/autoconfianca /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
certbot --nginx -d confiancarev.com.br -d www.confiancarev.com.br
```

O DNS de `confiancarev.com.br` e `www` precisa apontar para o IP da máquina antes do `certbot`.

## 5. Agendador

```bash
(crontab -u www-data -l 2>/dev/null; echo "* * * * * cd /var/www/autoconfianca && php artisan schedule:run >> /dev/null 2>&1") | crontab -u www-data -
```

## 6. Deploy automático

Siga `docs/deploy.md`: rode `scripts/deploy/preparar-servidor.sh` e cadastre os segredos no GitHub. Depois disso, todo push na `main` testa e publica.

## 7. Dados do Oficina Inteligente

Veja `docs/importacao-oficina-inteligente.md`.
