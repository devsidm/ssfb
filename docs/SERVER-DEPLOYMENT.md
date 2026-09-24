# SSF Server Deployment

This document describes the permanent server-native deployment workflow for SSF.

Normal operator command:

```bash
ssf-deploy
```

The command updates the production server checkout, runs the repository test
suite, validates PHP, syncs the approved runtime from Git to DEV, verifies DEV,
performs a production dry-run, asks once for the exact word `DEPLOY`, creates
full-site Production maintenance, creates real production backups, deploys the
approved DEV artifact to production, verifies production internally, reopens the
site and then runs public HTTP smoke tests.

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

## Production Maintenance

Production maintenance starts only after every preflight check has passed and
the operator has typed exactly:

```text
DEPLOY
```

The deploy script creates the standard WordPress root `.maintenance` marker in:

```text
$HOME/ssfb.se/public_html/.maintenance
```

The marker must contain a literal numeric Unix timestamp, never a dynamic
`time()` expression. The script verifies that the timestamp is numeric, that
anonymous public HTTP is blocked, waits the configured grace period
(`SSF_MAINTENANCE_GRACE_SECONDS`, default 10 seconds), and only then creates
the database backup. Public HTTP smoke tests run only after all internal checks
pass, maintenance has been deactivated, and the `.maintenance` file is verified
gone.

Failure behavior is deliberate:

- before production mutation: maintenance is removed again
- after production mutation: maintenance stays active or is reactivated
- after failed public smoke: maintenance is reactivated immediately

If the site remains in maintenance, inspect:

```bash
ls -l "$HOME/ssfb.se/public_html/.maintenance"
```

The normal recovery path is `ssf-rollback` or a controlled repair. Manual
removal is only an emergency action after confirming production is healthy:

```bash
rm -f "$HOME/ssfb.se/public_html/.maintenance"
```

Do not use manual removal as the ordinary workflow.

## What Is Deployed

The component source of truth is:

```text
config/deploy-components.json
```

Before the single production confirmation, the deploy script compares every normal
WordPress plugin in DEV and PROD using WP-CLI:

```bash
wp plugin list --format=json
```

For each missing plugin or newer DEV version, the operator chooses whether to
install or update it in PROD. The default answer is No; a skipped plugin keeps
its previous PROD version and activation status. A new selected plugin that is
active in DEV may be activated in PROD. Existing plugins keep their PROD
activation status. The final `DEPLOY` confirmation is still required.

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

- only plugins selected in the current interactive deployment plan
- configured SSF theme
- configured production MU plugin files

Production does not receive:

- `wp-config.php`
- uploads
- WordPress core
- DEV-only MU plugins
- DEV database

The deployment uses `rsync -a` and never uses `rsync --delete`.

PROD-only plugins and plugins with differing activation status are not
automatically deactivated. A newer PROD plugin is never downgraded. If files
differ despite equal version numbers, deployment warns and leaves that plugin
unchanged until its version is bumped.

The deployment never downloads plugins from wordpress.org. A selected plugin
is copied from the tested DEV directory; a selected new plugin active in DEV
may then be activated. Missing selected DEV files stop deployment before the
final confirmation.

Environment-specific plugin configuration is never copied from DEV. API keys,
secrets and WordPress options must remain per environment.

Concrete example: `simple-cloudflare-turnstile` may be active in both DEV and
PROD, but PROD must have its own Cloudflare Turnstile keys. DEV test keys must
not be copied to PROD. The deployment script checks the SSF antispam integration
before the `DEPLOY` confirmation and again after deployment while maintenance is
still active. It reads the production WordPress runtime options directly,
without printing keys; it reports only `FOUND`, `MISSING`, `Test mode` and
`Configured`. It also compares in-memory SHA256 fingerprints and requires:

```text
Turnstile PROD configuration unchanged: PASS
```

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
- `BACKUP-INFO.json`
- `BACKUP-INFO.txt`
- component inventory files

The database backup is created with PROD WP-CLI using credentials from PROD
`wp-config.php`. The deploy script verifies the gzip archive with `gzip -t`,
checks for a non-empty SQL dump, and records size and SHA256.

The file backup contains the currently deployed production components that the
deployment may overwrite, including any plugin directories in the generated
plugin parity plan. The deploy script verifies the tar archive with `tar -tzf`,
checks required paths from a complete archive listing, and records size and
SHA256. It does not use `tar | rg -q` membership checks.

`BACKUP-INFO.json` is authoritative for rollback. It records the selected build,
previous build, production path, expected home URL, DB prefix, checksums,
plugin/theme state, and which runtime paths existed before deployment. New
paths may be removed during rollback only when this JSON-backed metadata marks
them as absent before deployment.

Uploads are not included because this deployment does not touch uploads.

## Rollback

Rollback is manual and deliberate. The deployment script does not automatically
roll back production. Use:

```bash
ssf-rollback --list
ssf-rollback
ssf-rollback --backup prod-before-<BUILD>-<TIMESTAMP>
ssf-rollback --with-db
ssf-rollback --backup prod-before-<BUILD>-<TIMESTAMP> --with-db
```

The versioned rollback script lives at:

```text
$HOME/repos/ssfb/scripts/deploy/ssf-server-rollback.sh
```

Recommended one-time wrapper:

```bash
#!/usr/bin/env bash
set -euo pipefail

REPO="$HOME/repos/ssfb"

cd "$REPO"

if [[ -n "$(git status --porcelain)" ]]; then
    echo "ERROR: Git repository is dirty."
    exit 1
fi

git fetch origin main
git pull --ff-only origin main

exec "$REPO/scripts/deploy/ssf-server-rollback.sh" "$@"
```

Expose it:

```bash
chmod +x "$HOME/tools/ssf-rollback"
ln -sf "$HOME/tools/ssf-rollback" "$HOME/bin/ssf-rollback"
```

`ssf-rollback --list` shows valid rollback packages separately from legacy or
incomplete backup directories. Legacy directories without `BACKUP-INFO.json`
are not automatically eligible.

Rollback always enters full-site Production maintenance before changing files
or database state. After maintenance is active and the grace period has elapsed,
it creates a pre-rollback rescue backup under:

```text
$HOME/ssf-backups/pre-rollback-<TIMESTAMP>/
```

Files-only rollback restores deployment-managed runtime files, plugin files,
theme files and previous plugin activation state. It does not import a database.

Full rollback with `--with-db` also imports the exact `database.sql.gz` captured
immediately before the selected deployment. Before doing so, it saves the
current production database into the rescue backup so the rollback itself is
recoverable.

Database rollback can remove WordPress changes made after the selected backup,
including applications, registrations, motions, content edits, users, settings
and plugin options. External SharePoint data is not rolled back.

The rollback script validates before confirmation:

- production path is exactly `$HOME/ssfb.se/public_html`
- environment is production
- backup belongs to `https://ssfb.se`
- DB prefix matches current production
- database and file archive SHA256 values match `BACKUP-INFO.json`
- gzip and tar integrity checks pass
- current production can be inspected by WP-CLI

### Restore Files

Manual restore is not the normal workflow, but the file archive is a standard
tar archive if emergency inspection is needed:

```bash
BACKUP="$HOME/ssf-backups/prod-before-<BUILD>-<TIMESTAMP>"
PROD="$HOME/ssfb.se/public_html"

tar -xzf "$BACKUP/prod-wp-content-targets.tar.gz" -C "$PROD"
```

Prefer `ssf-rollback` so plugin state, maintenance, rescue backups and smoke
tests are handled consistently. The internal verification uses WP-CLI while the
site is still paused:

```bash
php "$PROD/wp-cli.phar" --path="$PROD" core is-installed
php "$PROD/wp-cli.phar" --path="$PROD" ssf release status
curl -sS -I https://ssfb.se/
```

### Restore Database

Only restore the database if the failure actually requires database rollback,
and prefer:

```bash
ssf-rollback --with-db
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
bash -n scripts/deploy/ssf-server-rollback.sh
```

## First Dress Rehearsal

After installing or updating the wrapper, run the command and stop before typing
`DEPLOY` to confirm that Git, tests, PHP lint, DEV sync, DEV smoke, PROD target
safety and dry-run reporting all complete cleanly.
