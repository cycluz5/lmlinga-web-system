# Automatic deployment to AWS EC2 (GitHub Actions)

After this is set up, every merge (or push) to `main` updates the live server automatically.

```text
merge to main ──> GitHub Actions "deploy" workflow
                    1. Build check: composer install, PHP syntax check, npm run build, JS tests
                    2. SSH to EC2 as "deployer" ──> sudo /opt/lmlinga-deploy.sh <commit>
                         backup ─> maintenance on ─> git fast-forward ─> composer/npm build
                         ─> new migrations one at a time ─> caches ─> reload PHP-FPM ─> online
                    3. Health check of the site URL (optional)
```

This assumes the server was set up with [DEPLOYMENT_AWS_EC2.md](DEPLOYMENT_AWS_EC2.md): code at `/var/www/lmlinga`
owned by the `lmlinga` user, PHP-FPM, Caddy, MariaDB, and the backup script `/opt/lmlinga-backup.sh`.

Files:

| File | Purpose |
|---|---|
| `.github/workflows/deploy.yml` | The workflow: build check, then SSH deploy, then health check |
| `scripts/deploy/lmlinga-deploy.sh` | The deploy script. A copy runs on the server as `/opt/lmlinga-deploy.sh` |

## What the deploy script does, and what happens on failure

It follows "Deploying updates" in the EC2 guide:

1. Refuses to run if another deploy is running, if the commit isn't on `origin/main`, if it isn't ahead of the deployed
   commit, or if the server checkout has local changes.
2. Runs `/opt/lmlinga-backup.sh` (if present), then `php artisan down`.
3. Fast-forwards the checkout to the merged commit, runs `composer install --no-dev`, `npm ci` and `npm run build`.
4. Runs **only the migration files added since the last deploy, one at a time** with `--path` (never a plain
   `php artisan migrate`), with the config cache cleared first.
5. Rebuilds the config/route/view caches, reloads PHP-FPM (needed for OPcache) and runs `php artisan up`.

If step 3 fails, the code is rolled back to the previous commit and the site comes back online on the old version.
If a migration or step 5 fails, the site is **left in maintenance mode** so the database can be checked first. The
workflow run fails and shows the script's message with the commands to finish by hand.

## One-time server setup

Run these on the EC2 server as `ec2-user`.

### 1. Get the deploy script onto the server

The script must first be on `main` (merge the branch that adds it), then pull once by hand:

```bash
sudo -iu lmlinga bash -c 'cd /var/www/lmlinga && git pull --ff-only'
sudo install -o root -g root -m 700 /var/www/lmlinga/scripts/deploy/lmlinga-deploy.sh /opt/lmlinga-deploy.sh
```

The script runs as root, so the server uses this **copy** in `/opt`, not the file in the repo. A merge can't change
what root runs. If `scripts/deploy/lmlinga-deploy.sh` changes later, repeat the `install` command above.

### 2. Create the `deployer` user and allow it to run only the deploy script

```bash
sudo useradd -m -s /bin/bash deployer
echo 'deployer ALL=(root) NOPASSWD: /opt/lmlinga-deploy.sh' | sudo tee /etc/sudoers.d/lmlinga-deploy
sudo chmod 440 /etc/sudoers.d/lmlinga-deploy
sudo visudo -cf /etc/sudoers.d/lmlinga-deploy
```

The last command must print `parsed OK`.

### 3. Create the SSH key GitHub will use

On **your own computer** (not the server):

```bash
ssh-keygen -t ed25519 -N "" -C "github-deploy-lmlinga" -f lmlinga_deploy
```

This creates `lmlinga_deploy` (private key, goes into GitHub) and `lmlinga_deploy.pub` (public key, goes on the server).

On the server, add the public key, **locked to the deploy script**. Replace `ssh-ed25519 AAAA... github-deploy-lmlinga`
with the full contents of `lmlinga_deploy.pub`:

```bash
sudo install -o deployer -g deployer -m 700 -d /home/deployer/.ssh
echo 'command="sudo /opt/lmlinga-deploy.sh \"$SSH_ORIGINAL_COMMAND\"",restrict ssh-ed25519 AAAA... github-deploy-lmlinga' \
  | sudo tee /home/deployer/.ssh/authorized_keys
sudo chown deployer:deployer /home/deployer/.ssh/authorized_keys
sudo chmod 600 /home/deployer/.ssh/authorized_keys
```

With `command=` and `restrict`, this key can't open a shell, forward ports or run anything else. Whatever command is
sent is passed to the deploy script as the commit SHA, and the script rejects anything that isn't a 40-character SHA.

### 4. Make sure `lmlinga` can fetch from GitHub

The script runs `git fetch` as `lmlinga`. If the repo is private, the read-only deploy key from step 7 of the EC2 guide
must be set up. Check with:

```bash
sudo -iu lmlinga bash -c 'cd /var/www/lmlinga && git fetch --dry-run origin main && echo OK'
```

### 5. Open SSH to GitHub Actions

GitHub-hosted runners connect from many changing IP addresses, so the EC2 **security group** must allow inbound
port **22** from `0.0.0.0/0`. Keep it safe with key-only login (the Amazon Linux 2023 default). Check that
`sudo sshd -T | grep -i passwordauthentication` prints `passwordauthentication no`.

### 6. Get the server's host key (protects against a fake server)

On your own computer, replace `YOUR_SERVER` with the server's domain or Elastic IP (the same value as `EC2_HOST`
below):

```bash
ssh-keyscan -t ed25519 YOUR_SERVER
```

Compare its fingerprint with the server's own (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server, and
`ssh-keyscan -t ed25519 YOUR_SERVER | ssh-keygen -lf -` locally). They must match. Keep the `ssh-keyscan` output line
for the `EC2_KNOWN_HOSTS` secret.

## One-time GitHub setup

In the repo, go to **Settings > Secrets and variables > Actions**.

**Secrets** (New repository secret):

| Name | Value |
|---|---|
| `EC2_HOST` | Server domain or Elastic IP, exactly as used with `ssh-keyscan` |
| `EC2_DEPLOY_USER` | `deployer` |
| `EC2_SSH_KEY` | Full contents of the private key file `lmlinga_deploy`, including the BEGIN/END lines |
| `EC2_KNOWN_HOSTS` | The line printed by `ssh-keyscan -t ed25519 YOUR_SERVER` |

**Variables** tab (optional but recommended):

| Name | Value |
|---|---|
| `PRODUCTION_URL` | The site's HTTPS URL, e.g. `https://lmlinga.example.com`. Used for the health check after deploying |

**Environment** (optional): the workflow deploys through an environment named `production`, which GitHub creates on
the first run. To require someone to approve each deploy, open **Settings > Environments > production** and add
**Required reviewers**.

After that, delete the private key file `lmlinga_deploy` from your computer, or store it somewhere safe.

## Test it

1. Test the key from your own computer. Use the commit that is already deployed, which makes the script exit without
   changes:

   ```bash
   ssh -i lmlinga_deploy deployer@YOUR_SERVER "$(git rev-parse origin/main)"
   ```

   It should print `Already at ... Nothing to deploy.` (or deploy, if the server was behind).
2. In GitHub, open **Actions > deploy > Run workflow** on `main`, or merge a small PR, and watch the run.

## Everyday use

- Merge a PR into `main`. The deploy starts automatically. Follow it in the **Actions** tab.
- A second merge during a deploy waits for the first to finish.
- Only commits on `main` are deployed. Running the workflow by hand from another branch is refused by the server.

## Rolling back

The automatic deploy only moves forward. To go back to an older version, deploy it by hand on the server as
`lmlinga`, following "Deploying updates" in the EC2 guide but using `git checkout <old-commit>` instead of
`git pull`, and restore the database from `/var/backups/lmlinga` if a migration changed it. Or revert the change
with a new PR (preferred), which deploys normally.

## Troubleshooting

| Symptom in the Actions log | Fix |
|---|---|
| `Set the EC2_SSH_KEY and EC2_KNOWN_HOSTS secrets` | Add the secrets above |
| `Host key verification failed` | `EC2_KNOWN_HOSTS` doesn't match `EC2_HOST`; redo step 6 with the same name/IP |
| `Permission denied (publickey)` | Public key missing or wrong in `/home/deployer/.ssh/authorized_keys`, or wrong `EC2_DEPLOY_USER` |
| `Connection timed out` | Security group doesn't allow port 22, or the instance is stopped |
| `sudo: a password is required` | The sudoers file from step 2 is missing or the path differs from `/opt/lmlinga-deploy.sh` |
| `Expected a 40-character commit SHA` | The key was used for an interactive login; it can only deploy |
| `is not on origin/main` | The workflow was started from another branch; run it on `main` |
| `The server checkout has local changes` | Someone edited files on the server; check `git status` in `/var/www/lmlinga` as `lmlinga` |
| `Another deploy is already running` | Wait, or check for a stuck `/opt/lmlinga-deploy.sh` process |
| `LEFT IN MAINTENANCE MODE` | A migration or cache step failed; follow the printed commands after fixing the cause |
| Build check fails | The merged code doesn't install or build; nothing was deployed. Fix and merge again |
