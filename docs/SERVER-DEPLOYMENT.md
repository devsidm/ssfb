# SSF Server Deployment

This document describes the permanent server-native deployment workflow for SSF.

Normal operator command:

```bash
ssf-deploy
```

The command updates the production server checkout, runs the repository test
suite, validates PHP, syncs the approved runtime from Git to DEV, verifies DEV,
performs a production dry-run, asks once for the exact word `DEPLOY`, creates
real production backups, deploys the approved DEV artifact to production, and
then verifies production.

## Flow

```text
GitHub -> $HOME/repos/ssfb -> DEV WordPress -> PROD WordPress
```

GitHub is the code source of truth. DEV is the staging and prepared artifact.
PROD receives only the configured SSF runtime components from DEV.

The production server GitHub key is read-only. The server can fetch and pull,
but it cannot push code back to GitHub.

## Paths

```text
Repository: $HOME/repos/ssfb
DEV:        $HOME/ssfb.se/public_html/dev
PROD:       $HOME/ssfb.se/public_html
Backups:    $HOME/ssf-backups
Tools:      $HOME/tools
User bin:   $HOME/bin
```

## One-Time Wrapper

The versioned deployment script lives in the repository:

```text
$HOME/repos/ssfb/scripts/deploy/ssf-server-deploy.sh
```

The stable operator command should be a small external wrapper at:

```text
$HOME/tools/ssf-deploy
```

Wrapper contents:

```bash
#!/usr/bin/env bash
set -Eeuo pipefail

REPO="$HOME/repos/ssfb"
cd "$REPO"

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Repository checkout is dirty. Stop." >&2
  exit 1
fi

git fetch origin
git pull --ff-only origin main

exec "$REPO/scripts/deploy/ssf-server-deploy.sh"
```

Then expose it:

```bash
chmod +x "$HOME/tools/ssf-deploy"
mkdir -p "$HOME/bin"
ln -sf "$HOME/tools/ssf-deploy" "$HOME/bin/ssf-deploy"
```

The wrapper updates the repository before executing the versioned deployment
script. This avoids having the running script update itself midway through a
deployment.

## What Is Deployed

The component source of truth is:

```text
config/deploy-components.json
```

All active DEV plugins are production dependencies by default. Before the
single production confirmation, the deploy script compares every normal
WordPress plugin in DEV and PROD using WP-CLI:

```bash
wp plugin list --format=json
```

If a normal plugin is active in DEV, it must exist and be active in PROD unless
it is explicitly listed as DEV-only in `config/deploy-components.json`.

Plugin policy exceptions live under:

```json
"plugin_policy": {
  "dev_only": [],
  "prod_only": [],
  "ignore_version": []
}
```

Do not infer DEV-only behavior from a plugin name. Third-party plugins are
checked with the same seriousness as SSF plugins.

Production receives:

- configured SSF plugins
- configured SSF theme
- configured production MU plugin files
- active DEV normal plugins that the generated deployment plan marks for copy,
  update or activation

Production does not receive:

- `wp-config.php`
- uploads
- WordPress core
- `ssf-promotions`
- DEV-only MU plugins
- DEV database

The deployment uses `rsync -a` and never uses `rsync --delete`.

PROD-only plugins, or plugins that are inactive in DEV but active in PROD, are
reported as warnings. They are not automatically deactivated, because removing a
production-specific plugin can be more dangerous than leaving it alone.

The deployment never downloads plugins from wordpress.org. If an active DEV
plugin is missing in PROD and its files exist in DEV, the plan may copy that
exact tested DEV plugin directory to PROD and activate it. If the plugin cannot
be safely copied from DEV, deployment stops before confirmation.

Environment-specific plugin configuration is never copied from DEV. API keys,
secrets and WordPress options must remain per environment.

Concrete example: `simple-cloudflare-turnstile` may be active in both DEV and
PROD, but PROD must have its own Cloudflare Turnstile keys. DEV test keys must
not be copied to PROD. The deployment script checks the SSF antispam integration
without printing keys; it reports only `FOUND`, `MISSING`, `Test mode` and
`Configured`.

## Backups

Backups are created only after the operator types exactly:

```text
DEPLOY
```

Each deployment creates:

```text
$HOME/ssf-backups/prod-before-<BUILD>-<TIMESTAMP>/
```

The directory contains:

- `database.sql.gz`
- `prod-wp-content-targets.tar.gz`
- `BACKUP-INFO.txt`
- component inventory files

The database backup is created with PROD WP-CLI using credentials from PROD
`wp-config.php`. The deploy script verifies the gzip archive with `gzip -t`.

The file backup contains the currently deployed production components that the
deployment may overwrite, including any plugin directories in the generated
plugin parity plan. The deploy script verifies the tar archive with `tar -tzf`.

Uploads are not included because this deployment does not touch uploads.

## Rollback

Rollback is manual and deliberate. The deployment script does not automatically
roll back production.

### Restore Files

Use the backup directory reported by the failed deployment:

```bash
BACKUP="$HOME/ssf-backups/prod-before-<BUILD>-<TIMESTAMP>"
PROD="$HOME/ssfb.se/public_html"

tar -xzf "$BACKUP/prod-wp-content-targets.tar.gz" -C "$PROD"
```

Then verify production:

```bash
php "$PROD/wp-cli.phar" --path="$PROD" core is-installed
php "$PROD/wp-cli.phar" --path="$PROD" ssf release status
curl -sS -I https://ssfb.se/
```

### Restore Database

Only restore the database if the failure actually requires database rollback:

```bash
BACKUP="$HOME/ssf-backups/prod-before-<BUILD>-<TIMESTAMP>"
PROD="$HOME/ssfb.se/public_html"

gzip -dc "$BACKUP/database.sql.gz" | php "$PROD/wp-cli.phar" --path="$PROD" db import -
```

WordPress database rollback does not roll back external SharePoint writes. If a
production workflow has written to SharePoint after go-live, review those
external records separately.

## Inspect Current Repo

```bash
cd "$HOME/repos/ssfb"
git status
git log -1 --oneline
```

## Updating Deploy Components

Change `config/deploy-components.json` in a normal code review. Do not edit the
server script to add or remove components unless the deployment behavior itself
needs to change.

After changing the component config, run:

```bash
pwsh -NoProfile -File scripts/tests/ssf-server-deploy-tests.ps1
bash -n scripts/deploy/ssf-server-deploy.sh
```

## First Dress Rehearsal

After installing or updating the wrapper, run the command and stop before typing
`DEPLOY` to confirm that Git, tests, PHP lint, DEV sync, DEV smoke, PROD target
safety and dry-run reporting all complete cleanly.
