# Automatic deployment to AWS EC2 (GitHub Actions)

Every merge (or push) to `main` updates the live server, **lmlinga.com**, automatically.

```text
merge to main ──> GitHub Actions "deploy" workflow
                    1. Build check: composer install, PHP syntax check, npm run build, JS tests
                    2. SSH to EC2 as "lmlinga" with a deploy-only key ──> /opt/lmlinga-deploy.sh <commit>
                         (backup if migrations) ─> maintenance on ─> code to <commit> ─> composer/npm build
                         ─> new migrations one at a time ─> caches ─> online ─> reload PHP-FPM ─> check /up
                    3. Health check of https://lmlinga.com/up from GitHub
```

| File | Purpose |
|---|---|
| `.github/workflows/deploy.yml` | The workflow: build check, then SSH deploy, then health check |
| `scripts/deploy/lmlinga-deploy.sh` | The deploy script. A copy runs on the server as `/opt/lmlinga-deploy.sh` |

## The server (current setup)

| Item | Value |
|---|---|
| Host | `16.176.39.226` (Amazon Linux 2023, ap-southeast-2) |
| Site | `https://lmlinga.com`, nginx + certbot, PHP-FPM 8.3, Node 22, MariaDB |
| App | `/var/www/lmlinga`, a git checkout of `main` owned by `lmlinga` |
| Deploy script | `/opt/lmlinga-deploy.sh` (root-owned, runs as `lmlinga`) |
| Backup script | `/opt/lmlinga-backup.sh` (backups in `/var/backups/lmlinga`) |
| Sudo for `lmlinga` | `/etc/sudoers.d/lmlinga-deploy`: `systemctl reload php-fpm` and `/opt/lmlinga-backup.sh` only |
| Deploy key | `/home/lmlinga/.ssh/authorized_keys`, locked to the deploy script (below) |

The general server setup is in [DEPLOYMENT_AWS_EC2.md](DEPLOYMENT_AWS_EC2.md). That guide uses Caddy; this server
uses nginx instead.

## What the deploy script does, and what happens on failure

1. Refuses anything that isn't a 40-character commit SHA, a commit not on `origin/main`, an older commit than the one
   deployed, or a checkout with local changes. Only one deploy runs at a time. If the commit is already deployed,
   it does nothing.
2. If the update adds migrations, runs `/opt/lmlinga-backup.sh` first.
3. `php artisan down`, moves the checkout to the commit, runs `composer install --no-dev`, `npm ci` and
   `npm run build` (Node is capped at 1.5 GB; the server has 1 GB RAM + 4 GB swap).
4. Runs **only the migration files added since the last deploy, one at a time** with `--path`, never a plain
   `php artisan migrate`, with the config cache cleared first.
5. Rebuilds the caches, `php artisan up`, reloads PHP-FPM (for OPcache), then checks `https://lmlinga.com/up`.

If step 3 fails, the code is restored to the previous commit and the site comes back online. If a migration fails,
the site is **left in maintenance mode** so the database can be checked (a backup was taken in step 2). The
workflow run fails and shows the reason.

## How GitHub is allowed in

GitHub holds a private SSH key (`EC2_SSH_KEY` secret) whose public half is in `/home/lmlinga/.ssh/authorized_keys`
as:

```text
command="/opt/lmlinga-deploy.sh \"$SSH_ORIGINAL_COMMAND\"",restrict ssh-ed25519 AAAA... github-actions-deploy-lmlinga
```

`command=` and `restrict` mean this key can't open a shell, forward ports or run anything else. Whatever is sent
becomes the script's argument, and the script rejects anything that isn't a commit SHA on `main`. The script file is
owned by root, so neither `lmlinga` nor a merged change can alter it.

**Updating the script:** after changing `scripts/deploy/lmlinga-deploy.sh` and merging, install the new copy by
hand as `ec2-user`:

```bash
sudo cp -p /opt/lmlinga-deploy.sh /opt/lmlinga-deploy.sh.bak-$(date +%Y%m%d)
sudo install -o root -g root -m 755 /var/www/lmlinga/scripts/deploy/lmlinga-deploy.sh /opt/lmlinga-deploy.sh
```

**Replacing the deploy key** (if it leaks or is lost): generate a new key with
`ssh-keygen -t ed25519 -N "" -C github-actions-deploy-lmlinga -f lmlinga_deploy`, replace the line in
`/home/lmlinga/.ssh/authorized_keys` (same `command=...,restrict` prefix), and update the `EC2_SSH_KEY` secret.

## GitHub settings

In the repo, go to **Settings > Secrets and variables > Actions**.

| Secret | Value |
|---|---|
| `EC2_HOST` | `16.176.39.226` |
| `EC2_DEPLOY_USER` | `lmlinga` |
| `EC2_SSH_KEY` | The deploy private key, including the BEGIN/END lines |
| `EC2_KNOWN_HOSTS` | `16.176.39.226 ssh-ed25519 <server host key>` (from `/etc/ssh/ssh_host_ed25519_key.pub` on the server) |

| Variable | Value |
|---|---|
| `PRODUCTION_URL` | `https://lmlinga.com/up` (health check after deploying) |

Optional: to require approval for each deploy, add **Required reviewers** under
**Settings > Environments > production**. The environment is created on the first run.

**Firewall:** GitHub-hosted runners connect from many changing IP addresses, so the EC2 security group must allow
inbound port **22** from `0.0.0.0/0`. Password login is off on this server (key-only), and the deploy key is locked as
described above.

## Everyday use

- Merge a PR into `main`. The deploy starts automatically. Follow it in the **Actions** tab.
- A second merge during a deploy waits for the first to finish.
- Re-run a deploy: **Actions > deploy > Run workflow** on `main`.

## Rolling back

The automatic deploy only moves forward. The simplest rollback is reverting the change in a new PR, which deploys
normally. For an emergency rollback on the server as `lmlinga`, follow "Deploying updates" in the EC2 guide with
`git reset --hard <old-commit>` instead of `git pull`, and restore the database from `/var/backups/lmlinga` if a
migration changed it.

## Old self-hosted runner

An earlier setup used a self-hosted GitHub Actions runner in `/opt/actions-runner`. Its service is no longer
installed and this workflow doesn't use it. If it still shows under **Settings > Actions > Runners**, remove it there,
then delete `/opt/actions-runner` on the server.

## Troubleshooting

| Symptom in the Actions log | Fix |
|---|---|
| `Set the EC2_SSH_KEY and EC2_KNOWN_HOSTS secrets` | Add the secrets above |
| `Host key verification failed` | `EC2_KNOWN_HOSTS` doesn't match the server's host key or `EC2_HOST` |
| `Permission denied (publickey)` | The key line in `/home/lmlinga/.ssh/authorized_keys` is missing or doesn't match `EC2_SSH_KEY` |
| `Connection timed out` | The security group doesn't allow port 22 from GitHub, or the instance is stopped |
| `is not on origin/main` | The workflow was started from another branch; run it on `main` |
| `has local changes to tracked files` | Someone edited files on the server; check `git status` in `/var/www/lmlinga` as `lmlinga` |
| `Another deployment is running` | Wait, or check for a stuck `/opt/lmlinga-deploy.sh` process |
| `A migration failed` | The site is in maintenance mode. Fix the database or restore the backup, then `php artisan up` |
| `did not pass its health check` | Check the Laravel log and `systemctl status php-fpm nginx` |
| `sudo: a password is required` | `/etc/sudoers.d/lmlinga-deploy` is missing or changed |
| Build check fails | The merged code doesn't install or build; nothing was deployed. Fix and merge again |
