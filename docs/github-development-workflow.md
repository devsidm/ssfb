# GitHub-first development workflow

## Flow

```text
Codex Cloud -> feature branch -> pull request -> CI -> merge main
            -> DEV deployment -> separate PROD approval
```

Git is the canonical code source. Codex starts from current `origin/main`,
works on its own short-lived branch, runs targeted checks, pushes that branch
and creates or updates a pull request. Codex does not push directly to `main`,
merge its own pull request, bypass CI or deploy production.

Codex Cloud should use a published Linux environment for `devsidm/ssfb` with
Git, Bash, PHP 8.3 or later, PowerShell 7, Node.js and ripgrep. Normal edit and
CI work needs no SSF live credentials. Official OpenAI guidance recommends
keeping repository instructions in `AGENTS.md` and publishing a tested cloud
environment before starting new tasks. Select **GPT-5.6 Sol** with reasoning
effort **High** for this workflow when those controls are available to the
workspace.

## Pull requests and CI

`.github/workflows/ci.yml` runs only for pull requests targeting `main` and for
manual diagnostics. It has read-only repository permission, receives no live
credentials and never uses `pull_request_target`. It checks changed lines,
lints changed PHP, PowerShell, Bash and JavaScript files, validates changed
JSON, and selects existing offline tests from the paths in the diff.

## GitHub Environments

Create two GitHub Environments manually:

- `development`: store DEV-only credentials here. Add a required reviewer if
  DEV deployment should pause for approval.
- `production`: require Johan as reviewer, prevent self-review where available,
  restrict deployment to `main`, and store only the production SSH material.

Keep these repository variables disabled until the corresponding environment
has been reviewed and tested:

- `SSF_ENABLE_DEV_DEPLOY=false`
- `SSF_ENABLE_PROD_DEPLOY=false`

The optional production environment variable `SSF_PROD_SSH_PORT` defaults to
`22` when omitted.

### Development secrets

- `SSF_FTP_HOST`
- `SSF_FTP_USER`
- `SSF_FTP_PASSWORD`
- `SSF_WP_USER`
- `SSF_WP_PASSWORD`

### Production secrets

- `SSF_PROD_SSH_HOST`
- `SSF_PROD_SSH_USER`
- `SSF_PROD_SSH_PRIVATE_KEY`
- `SSF_PROD_SSH_KNOWN_HOSTS`

Never add these values to repository files, pull-request workflows or Codex
Cloud. Add them manually as environment-scoped GitHub secrets.

## DEV deployment

`.github/workflows/deploy-dev.yml` is triggered by a push to `main` or a manual
run, but its job stays skipped until `SSF_ENABLE_DEV_DEPLOY` is exactly `true`.
It runs the mandatory DEV preflight and then reuses
`scripts/ssf-release-deploy.ps1`.

The workflow deliberately passes no `RemoteRoot`. FTP `/` must already contain
`wp-content` directly. The existing deploy script fails closed otherwise and
does not deploy `wp-config.php` or `wp-content/uploads`.

For the first activation:

1. Protect `main`, create the `development` environment and add only the five
   listed development secrets.
2. Leave `SSF_ENABLE_DEV_DEPLOY=false` and manually run CI on the reviewed
   `main` revision.
3. Set `SSF_ENABLE_DEV_DEPLOY=true`.
4. Manually run **Deploy DEV** from `main` and verify that preflight, FTP-root
   validation, smoke tests and the SSF release build all pass.
5. Confirm in WordPress **SSF > Release** that the expected build is marked as
   successfully deployed. Disable the variable again if automatic DEV deploys
   are not yet wanted.

## PROD deployment

`.github/workflows/deploy-prod.yml` is manual only. It runs only from `main`,
requires the dispatch input `DEPLOY`, requires the protected `production`
environment and remains disabled until `SSF_ENABLE_PROD_DEPLOY=true`.

The job connects to the production host and invokes the existing fixed command
`$HOME/bin/ssf-deploy`. The server script retains responsibility for its
read-only GitHub checkout, release and DEV verification, plugin decisions,
backups, maintenance, deployment and post-deploy checks. Enter one `y` or
`SKIP` line per prompted plugin in `plugin_choices`; the workflow appends the
final exact `DEPLOY` confirmation. An absent or incorrect choice fails before
production mutation.

The production SSH key should be a dedicated, restricted deployment key whose
server-side authorization permits only the approved wrapper. This credential
is separate from the production server's existing read-only GitHub deploy key.

## Rollback

There is intentionally no automatic rollback workflow. Use the existing
server-side `ssf-rollback` wrapper backed by
`scripts/deploy/ssf-server-rollback.sh`. The operator selects a verified backup
and types exactly `ROLLBACK`, or `ROLLBACK WITH DATABASE` when a database
restore is explicitly required. A future GitHub workflow must retain those
manual decisions, production environment approval and rescue-backup behavior.

## Manual GitHub configuration

1. Protect `main`: require a pull request, one approval, CODEOWNERS review,
   resolved conversations and the `targeted-checks` status check; block force
   pushes and deletion.
2. Create and protect the `development` and `production` environments as above.
3. Add the named secrets without exposing their values in issues or logs.
4. Keep both enable variables `false` until each deployment path is approved.
5. Connect `devsidm/ssfb` in Codex Cloud, publish the tested Linux environment,
   and enable Codex code review if desired. A review can also be requested with
   `@codex review`.
