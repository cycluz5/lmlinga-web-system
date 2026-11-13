# Deploying LMLinga to AWS EC2 (Amazon Linux 2023)

This guide installs LMLinga directly on one EC2 instance. Everything runs as a normal system service.

```text
Browser ──HTTPS 443──> Caddy ──FastCGI socket──> PHP-FPM (Laravel, /var/www/lmlinga)
                                                   ├── 127.0.0.1:3306  ──> MariaDB
                                                   └── 127.0.0.1:11434 ──> Ollama (chatbot embeddings)
```

| Component | Why |
|---|---|
| Caddy | Serves the site and renews the HTTPS certificate automatically |
| PHP 8.3 + PHP-FPM | Runs Laravel |
| MariaDB 10.5 | Matches the format of the XAMPP database and `mysql-schema.sql` |
| Ollama | Creates the chatbot's embeddings locally. Chat answers use Ollama's cloud API |

The app has no queued jobs or scheduled tasks, so it needs no queue worker or Laravel cron entry.

## Read this first

- **HTTPS is required.** Offline mode depends on a service worker, and browsers only allow those on HTTPS.
- **Never run plain `php artisan migrate`.** A blanket migrate can create legacy tables and switch modules out of ERD mode. Run migrations one file at a time with `--path`.
- **Run migrations before caching the config.** Every migrate recreates the `maria.santos` admin if it is missing. With a cached config, Laravel ignores `.env` there and uses the default password written in the source code.
- **Back up `LMLINGA_AT_REST_KEY` outside the server.** Without it, encrypted health-record columns can't be read.
- **Keep ports 3306 and 11434 closed to the internet.** Both services listen on localhost only.
- **Run app commands as the `lmlinga` user**, never with `sudo php artisan`. Files created as root can make the site fail with permission errors.

## What you need

- An AWS account
- A domain name, or the free `sslip.io` option in step 2
- SMTP credentials for password-reset and OTP emails
- A Semaphore API key for SMS OTP
- An Ollama API key for the chatbot's cloud chat model, from <https://ollama.com/settings/keys>

## 1. Create the EC2 instance

In **EC2 > Launch instance**, use these settings. `ap-southeast-1` (Singapore) is the closest region to the Philippines.

| Setting | Value |
|---|---|
| AMI | Amazon Linux 2023, 64-bit (x86) |
| Instance type | `t3.medium` or larger (4 GB RAM minimum) |
| Storage | 30 GB gp3 (change this: the AMI's default is much smaller and Ollama alone needs several GB) |
| Key pair | Create one and keep the `.pem` file safe |

Security group inbound rules:

| Port | Source | Purpose |
|---|---|---|
| 22 TCP | My IP only | SSH |
| 80 TCP | 0.0.0.0/0 | HTTP redirect and certificate issuance |
| 443 TCP | 0.0.0.0/0 | HTTPS |

Then go to **Elastic IPs > Allocate**, and associate the address with the instance so it survives reboots.

## 2. Point a domain at the server

Create a DNS **A record** from your domain to the Elastic IP, for example `lmlinga.example.com`. Wait until it resolves before step 12.

**No domain?** Use the Elastic IP with `sslip.io`. For `13.229.10.20`, the hostname is `13-229-10-20.sslip.io`. It needs no DNS setup and is fine for demos and capstone defense, but use a real domain for production.

## 3. Prepare the server

Connect to the instance. Run everything as `ec2-user` unless a step says otherwise.

```bash
ssh -i your-key.pem ec2-user@YOUR_ELASTIC_IP
```

Update the system, set the timezone and add swap so builds don't run out of memory:

```bash
sudo dnf update -y
sudo timedatectl set-timezone Asia/Manila

sudo dd if=/dev/zero of=/swapfile bs=1M count=4096
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

sudo dnf install -y git unzip tar zstd cronie
sudo systemctl enable --now crond
```

Create the user that owns and runs the app:

```bash
sudo useradd -m -s /bin/bash lmlinga
sudo mkdir -p /var/www/lmlinga
sudo chown lmlinga:lmlinga /var/www/lmlinga
```

## 4. Install MariaDB

```bash
sudo dnf install -y mariadb105-server

sudo tee /etc/my.cnf.d/zz-lmlinga.cnf >/dev/null <<'EOF'
[mysqld]
bind-address = 127.0.0.1
innodb_buffer_pool_size = 512M
EOF

sudo systemctl enable --now mariadb
```

Create the database and its user. Save the printed password for step 8.

```bash
DBPASS=$(openssl rand -hex 24); echo "DB password: $DBPASS"

sudo mariadb <<SQL
CREATE DATABASE lmlinga CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lmlinga'@'127.0.0.1' IDENTIFIED BY '$DBPASS';
GRANT ALL PRIVILEGES ON lmlinga.* TO 'lmlinga'@'127.0.0.1';
DROP USER IF EXISTS ''@'localhost';
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
SQL
```

## 5. Install PHP, Composer and Node.js

Check which PHP versions your AMI offers:

```bash
dnf list available 'php8.*-fpm'
```

The guide uses PHP 8.3, the closest supported version to the 8.2 the app was built on (PHP 8.2 security support ends in December 2026). If 8.3 isn't listed, use 8.4 and change the `PHP=` line.

```bash
PHP=php8.3
sudo dnf install -y $PHP-cli $PHP-fpm $PHP-common $PHP-mysqlnd $PHP-pdo \
  $PHP-gd $PHP-mbstring $PHP-xml $PHP-intl $PHP-bcmath $PHP-opcache

sudo tee /etc/php.d/99-lmlinga.ini >/dev/null <<'EOF'
date.timezone = Asia/Manila
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 25M
expose_php = Off
display_errors = Off

opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
EOF

php -m | grep -Ei 'pdo_mysql|gd|intl|bcmath|mbstring|dom|xmlwriter|openssl'
```

Install Composer and Node.js. Node is only used to build the front-end assets.

```bash
sudo curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

sudo dnf install -y nodejs22
node -v && npm -v && composer --version
```

## 6. Install Ollama

```bash
curl -fsSL https://ollama.com/install.sh | sh
sudo systemctl enable --now ollama
ollama pull nomic-embed-text
```

Ollama listens on `127.0.0.1:11434` by default. Leave it that way.

## 7. Get the code

Switch to the app user:

```bash
sudo -iu lmlinga
git clone https://github.com/cycluz5/lmlinga-web-system.git /var/www/lmlinga
cd /var/www/lmlinga
```

If the repo is private, add a read-only deploy key first:

1. As `lmlinga`, run `ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519`.
2. Add the contents of `~/.ssh/id_ed25519.pub` under **GitHub > repo Settings > Deploy keys**.
3. Clone with `git clone git@github.com:cycluz5/lmlinga-web-system.git /var/www/lmlinga`.

## 8. Create the production `.env`

Still as `lmlinga`, generate the two keys. Run the command twice and use different output for each:

```bash
echo "base64:$(openssl rand -base64 32)"
```

If you are importing an existing database in step 9, **reuse the current `APP_KEY` and `LMLINGA_AT_REST_KEY`** from your local `.env`. New keys would make existing encrypted data unreadable.

Create the file with `nano /var/www/lmlinga/.env`. Wrap any value containing spaces or `#` in double quotes.

```dotenv
APP_NAME=LMLinga
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://lmlinga.example.com

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lmlinga
DB_USERNAME=lmlinga
DB_PASSWORD=

# File drivers: database/schema/mysql-schema.sql has no sessions, cache or jobs tables,
# and the app has no queued jobs
SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

# Port 25 is blocked on EC2 by default, so use 587. Gmail needs an App Password.
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="LMLinga"

SEMAPHORE_API_KEY=
SEMAPHORE_SENDER_NAME=LMLINGA

OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_API_KEY=

LMLINGA_AT_REST_KEY=
LMLINGA_AT_REST_KEY_ID=k1

# Used only if the maria.santos admin has to be created
SUPER_ADMIN_PASSWORD=
SUPER_ADMIN_MOBILE=

# Blank on purpose: disables the demo staff logins
DEMO_STAFF_ADMIN_PASSWORD=
DEMO_STAFF_WORKER_PASSWORD=
```

Lock the file down, then return to `ec2-user`:

```bash
chmod 600 /var/www/lmlinga/.env
exit
```

## 9. Load the database

Choose one option.

### Option A: import your existing database (recommended)

This keeps your real data and the table layout the app has been running on.

On your **Windows PC**, in PowerShell from the project folder, export the database and uploaded files. Use `--result-file` because PowerShell's `>` can corrupt the dump's encoding. Change the database name if yours differs.

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root -p --single-transaction --routines --triggers --result-file=lmlinga.sql lmlinga
tar -czf storage-app.tgz -C storage app
scp -i your-key.pem lmlinga.sql storage-app.tgz ec2-user@YOUR_ELASTIC_IP:~
```

On the **server**, as `ec2-user`:

```bash
sudo mariadb lmlinga < ~/lmlinga.sql
sudo tar -xzf ~/storage-app.tgz -C /var/www/lmlinga/storage
sudo chown -R lmlinga:lmlinga /var/www/lmlinga/storage
```

### Option B: start with an empty database

```bash
sudo mariadb lmlinga < /var/www/lmlinga/database/schema/mysql-schema.sql
```

This file holds structure only, with no data. It also defines the older `child_immunizations` table instead of the ERD `child_immunization` table, so the Child Immunization module runs in legacy mode. Use Option A if you need that module in ERD mode.

## 10. Build the app

Run this as `lmlinga`. The build takes a few minutes.

```bash
sudo -iu lmlinga
cd /var/www/lmlinga

mkdir -p storage/app/public storage/app/private \
  storage/framework/cache/data storage/framework/sessions storage/framework/views \
  storage/logs bootstrap/cache

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan storage:link
```

Apply the four migrations that aren't recorded in `mysql-schema.sql`. They are safe to run on either option because each one checks the current schema first:

```bash
php artisan config:clear

for m in \
  2026_09_29_160000_generate_timbang_bmi_and_require_resident \
  2026_09_29_170000_drop_risk_assessment_blood_pressure_status \
  2026_09_29_180000_drop_erd_resident_statuses \
  2026_10_01_090000_add_not_applicable_to_residents_educational_attainment
do
  php artisan migrate --path="database/migrations/$m.php" --force
done
```

If one stops with a message about orphaned rows or conflicting statuses, fix the rows it names and run it again.

Check the encrypted columns, then cache the configuration:

```bash
php artisan lmlinga:at-rest:verify

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

The verify command must end with "All registered health-record columns are encrypted and decryptable." If it doesn't, see `docs/AT_REST_ENCRYPTION.md`.

Build the chatbot index in the background. It takes a few minutes and skips itself if the index already has data.

```bash
nohup php artisan health:index --if-empty > ~/health-index.log 2>&1 &
exit
```

## 11. Configure PHP-FPM

Caddy's user must exist before PHP-FPM can hand it the socket, so create it now. Then replace the default pool, as `ec2-user`:

```bash
sudo groupadd --system caddy
sudo useradd --system --gid caddy --create-home --home-dir /var/lib/caddy --shell /usr/sbin/nologin caddy

sudo mv /etc/php-fpm.d/www.conf /etc/php-fpm.d/www.conf.disabled

sudo tee /etc/php-fpm.d/lmlinga.conf >/dev/null <<'EOF'
[lmlinga]
user = lmlinga
group = lmlinga
listen = /run/php-fpm/lmlinga.sock
listen.owner = caddy
listen.group = caddy
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 4
pm.max_requests = 500
EOF

sudo systemctl enable --now php-fpm
sudo systemctl status php-fpm --no-pager
```

## 12. Install Caddy and turn on HTTPS

Install the binary and service:

```bash
ARCH=$(uname -m | sed 's/x86_64/amd64/; s/aarch64/arm64/')
CADDY=$(curl -fsSL https://api.github.com/repos/caddyserver/caddy/releases/latest | sed -n 's/.*"tag_name": *"v\([^"]*\)".*/\1/p')
curl -fsSL "https://github.com/caddyserver/caddy/releases/download/v${CADDY}/caddy_${CADDY}_linux_${ARCH}.tar.gz" | sudo tar -xz -C /usr/local/bin caddy
caddy version

sudo tee /etc/systemd/system/caddy.service >/dev/null <<'EOF'
[Unit]
Description=Caddy
After=network.target network-online.target
Requires=network-online.target

[Service]
Type=notify
User=caddy
Group=caddy
ExecStart=/usr/local/bin/caddy run --environ --config /etc/caddy/Caddyfile
ExecReload=/usr/local/bin/caddy reload --config /etc/caddy/Caddyfile --force
TimeoutStopSec=5s
LimitNOFILE=1048576
PrivateTmp=true
ProtectSystem=full
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
EOF
```

Replace the hostname, then start it:

```bash
sudo mkdir -p /etc/caddy
sudo tee /etc/caddy/Caddyfile >/dev/null <<'EOF'
lmlinga.example.com {
    root * /var/www/lmlinga/public
    encode zstd gzip

    # The app trusts proxy headers, so clients must not be able to set these
    request_header -X-Forwarded-Port
    request_header -X-Forwarded-Prefix

    php_fastcgi unix//run/php-fpm/lmlinga.sock
    file_server
}
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now caddy
sudo journalctl -u caddy -f    # Ctrl+C once you see the certificate obtained
```

## 13. Verify the deployment

```bash
curl -I https://lmlinga.example.com/up
systemctl is-active caddy php-fpm mariadb ollama
sudo tail -n 5 /home/lmlinga/health-index.log
```

Then check these in a browser:

- [ ] The site loads over HTTPS with a valid certificate.
- [ ] Staff login works. The admin username is `maria.santos`.
- [ ] Worker and chatbot forgot-password emails arrive, and the links open the HTTPS site.
- [ ] The chatbot answers a health question in English and in Bikol-Iriga.
- [ ] Household verification SMS OTP arrives.
- [ ] An existing upload opens (Option A), and a new one saves.
- [ ] A generated PDF report downloads with its images.
- [ ] DevTools > Application > Service Workers shows `/sw.js` activated.
- [ ] Ports 3306 and 11434 are unreachable from outside.

Then secure the admin account. Sign in as `maria.santos`, set a strong password if needed, and replace the placeholder `maria.santos@lmlinga.test` email with a real address. Password reset can't reach the admin until you do.

## 14. Set up backups

A backup on the same disk won't survive losing the instance, so copy it off the server. Back up `.env` separately and keep it somewhere safe.

Create `/opt/lmlinga-backup.sh` with `sudo nano`:

```bash
#!/bin/bash
set -euo pipefail

BACKUP_DIR=/var/backups/lmlinga
STAMP=$(date +%F-%H%M)
mkdir -p "$BACKUP_DIR"

mysqldump --single-transaction --routines --triggers lmlinga | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz"
tar -czf "$BACKUP_DIR/storage-$STAMP.tgz" -C /var/www/lmlinga/storage app

# Optional off-server copy. Requires an S3 bucket and an instance IAM role with write access.
# aws s3 cp "$BACKUP_DIR/db-$STAMP.sql.gz" s3://YOUR_BUCKET/lmlinga/
# aws s3 cp "$BACKUP_DIR/storage-$STAMP.tgz" s3://YOUR_BUCKET/lmlinga/

find "$BACKUP_DIR" -type f -mtime +14 -delete
```

Schedule it daily at 2 AM, and test it once:

```bash
sudo chmod 700 /opt/lmlinga-backup.sh
echo '0 2 * * * root /opt/lmlinga-backup.sh >> /var/log/lmlinga-backup.log 2>&1' | sudo tee /etc/cron.d/lmlinga-backup
sudo /opt/lmlinga-backup.sh
sudo ls -lh /var/backups/lmlinga
```

To restore a database backup:

```bash
gunzip -c /var/backups/lmlinga/db-YYYY-MM-DD-HHMM.sql.gz | sudo mariadb lmlinga
```

## Deploying updates

Back up as `ec2-user`, then switch to the app user:

```bash
sudo /opt/lmlinga-backup.sh
sudo -iu lmlinga
cd /var/www/lmlinga

php artisan down
OLD=$(git rev-parse HEAD)
git pull
git diff --name-only "$OLD" HEAD -- database/migrations

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan config:clear
```

Run each migration listed by `git diff` one at a time:

```bash
php artisan migrate --path=database/migrations/FILE_NAME.php --force
```

Rebuild the caches and bring the site back. The PHP-FPM reload is required because OPcache doesn't check for changed files.

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
exit
sudo systemctl reload php-fpm
```

## Applying `.env` changes

```bash
sudo -iu lmlinga
cd /var/www/lmlinga
nano .env
php artisan config:cache
exit
sudo systemctl reload php-fpm
```

## Common commands

| Task | Command |
|---|---|
| Service status | `systemctl status caddy php-fpm mariadb ollama` |
| Laravel log | `sudo tail -n 100 /var/www/lmlinga/storage/logs/laravel-$(date +%F).log` |
| PHP-FPM log | `sudo tail -n 100 /var/log/php-fpm/error.log` |
| Caddy log | `sudo journalctl -u caddy -n 100` |
| Rebuild chatbot index | `sudo -iu lmlinga php /var/www/lmlinga/artisan health:index` |
| Maintenance on / off | `sudo -iu lmlinga php /var/www/lmlinga/artisan down` / `up` |

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `No match for argument` on a PHP package | That version isn't in your AMI's repo | Run `dnf list available 'php8.*'` and change `PHP=` |
| `npm: command not found` | npm is packaged separately on your AMI | `sudo dnf install -y nodejs22-npm` |
| Ollama install fails with `tar: Cannot open`, or `could not create directory` | Disk is full (check `df -h /`) | Enlarge the EBS volume, run `sudo growpart /dev/nvme0n1 1` and `sudo xfs_growfs -d /`, remove `/usr/local/lib/ollama`, then rerun the installer |
| Ollama install fails on extraction with `zstd` errors | `zstd` is missing | `sudo dnf install -y zstd`, then rerun the installer |
| Build stops or the server freezes | Out of memory | Confirm swap with `free -h`, or use a larger instance |
| 502 Bad Gateway | PHP-FPM is down, or Caddy can't use the socket | Check `systemctl status php-fpm` and `ls -l /run/php-fpm/` (owner must be `caddy`) |
| 500 errors mentioning permission denied | Files owned by root | `sudo chown -R lmlinga:lmlinga /var/www/lmlinga/storage /var/www/lmlinga/bootstrap/cache` |
| 500 error `Table 'sessions' doesn't exist` | `SESSION_DRIVER=database` | Use the file drivers from step 8, then apply `.env` changes |
| Code changes don't appear | OPcache is still serving old files | `sudo systemctl reload php-fpm` |
| `.env` changes don't apply | Config is cached | Follow "Applying `.env` changes" |
| 419 Page Expired, or login loops | HTTP access while secure cookies are on | Use the HTTPS URL and check that `APP_URL` matches it |
| `Access denied` for user `lmlinga` | `DB_PASSWORD` doesn't match step 4 | Correct `.env`, or reset the password in `sudo mariadb` |
| Import fails partway | Partial data is already loaded | Recreate the database before retrying (below) |
| Uploads fail on larger files | PHP limit | Raise the limits in `/etc/php.d/99-lmlinga.ini`, then reload PHP-FPM |
| No emails arrive | Wrong SMTP settings | Check `MAIL_*` and the Laravel log. Keep port 587 |
| Chatbot can't find answers | Index not finished or failed | Check `/home/lmlinga/health-index.log` and that `ollama list` shows `nomic-embed-text` |
| Chat answers fail | Missing or invalid `OLLAMA_API_KEY` | Set it, then apply `.env` changes |
| Caddy can't get a certificate | DNS or firewall | Confirm the A record and that ports 80 and 443 are open |
| Service worker doesn't register | Site isn't on HTTPS | Open it through the HTTPS domain |

To recreate an **empty** database during first setup (this permanently deletes all data in it):

```bash
sudo mariadb -e "DROP DATABASE lmlinga; CREATE DATABASE lmlinga CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```
