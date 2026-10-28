# Codex Cloud development

GitHub `devsidm/ssfb` is the source of truth; `main` is the stable base.
The existing development site remains **https://ssfb.se/dev**. Do not install local WordPress or a database.

The workflow was verified from a fresh Codex Cloud task.

## Audit and runtime requirements

- Custom theme: `wp-content/themes/ssf`; custom plugins: `wp-content/plugins`; MU plugins: `wp-content/mu-plugins`. WordPress core is absent.
- The theme, workspace and inspection plugins declare PHP 8.0; several other plugins declare 7.4. Component WordPress headers declare minimums up to 6.0. These declarations do not verify the live server version.
- Development/CI use **PHP 8.3 CLI**, `dom`, `libxml`, `mbstring`, Bash and Git. No web server is needed.
- No Composer/npm manifests, lockfiles, tracked `vendor` or `node_modules` trees were found. Custom JS/CSS and PDF assets remain intentionally tracked. No package install or dependency cache is needed.
- Optional: **PowerShell 7.4** for existing `.ps1` tests. **Node 22+** for `scripts/tests/ssf-edge-layout-audit.mjs`, which uses built-in fetch/WebSocket and needs a separately prepared browser debugging session. Neither is required for CI; no npm packages are needed.
- Existing checks include standalone PHP tests, PowerShell contract/harness tests, Bash release-file tests and a browser audit. PHP lint is the existing syntax check. No PHPCS, ESLint, package build or previous GitHub Actions workflow was found. The root `AGENTS.md` is the only agent instruction file.
- Existing development/release documentation is in `README.md`, `docs/wordpress-operations.md`, `docs/release-controls.md` and component docs. Read only what the task needs. Release/deployment instructions are a separate operational workflow, not required steps after a code task.
- `.gitignore` already excludes credentials/local environment files, dependencies, uploads, caches, logs and known harness artifacts. No ignore changes or dependency removal are needed.

## Cloud configuration

Select `devsidm/ssfb`, base branch `main`, and provision the required runtimes in the environment image. Use the existing isolated checkout, not a new worktree. Set its working directory to the checkout and run:

```bash
bash scripts/codex-setup.sh
```

This idempotent, credential-free command checks the toolchain. No dependencies need installation. Provision missing PHP/extensions in the environment image, rather than installing system packages every task. No startup service is needed.

The previously prepared cloud instance has tool wrappers at `/workspace/ssf-tools/bin`; activate them there with `export PATH=/workspace/ssf-tools/bin:$PATH`. This is instance-specific; CI uses native PHP. Configure future cloud setup/start fields to use this repository script and these instructions.

Do **not** add FTP credentials, WordPress administrator credentials, production database credentials, Microsoft 365 client secrets, private certificates or deployment keys. Local checks need none. Use platform GitHub authentication for checkout. CI needs no PAT or repository secrets and uses only `contents: read` without persisting checkout credentials.

## Normal task workflow

1. Read `AGENTS.md` and inspect `git status`; preserve existing changes.
2. Start from current `main` (fetch GitHub when available), then create a focused branch such as `codex/fix-news-title` or `codex/add-calendar-check`. Do not reset in-progress work to switch branches.
3. Run setup, inspect relevant code/docs, and make the smallest useful change. Do not touch core or release manifests for tooling work.
4. Run relevant tests and lint changed PHP files. Review `git diff --check` and the staged diff for scope and accidental private/generated files.
5. Create a focused commit with a concrete message. Push only when authorized and open a PR against `main` describing changes, validation and limitations. Do not deploy.

## Targeted validation

From the checkout root, choose checks relevant to the change:

```bash
php -l path/to/changed-file.php
php scripts/tests/ssf-access-control-runtime-test.php
php scripts/tests/ssf-workspace-tests.php
php wp-content/plugins/ssf-member-portal/tests/annual-meeting-registration-mode.php
php wp-content/plugins/ssf-member-portal/tests/annual-meeting-attendance.php
bash scripts/tests/ssf-release-files-tests.sh
# Optional, when relevant:
pwsh -NoProfile -File scripts/tests/ssf-news-status-tests.ps1
pwsh -NoProfile -File scripts/tests/ssf-release-tests.ps1
```

CI runs tracked PHP syntax checks and the five regression commands above on PRs to `main` and pushes to `main`. This is a small baseline, not the entire suite; report task-specific validation separately. No dependency cache is configured because there are no package dependencies.

`php scripts/tests/ssf-news-tests.php` mocks HTTP but needs public DNS resolution for `example.com`. Restricted/offline environments can fail its canonical URL assertion; this alone does not establish an application defect. It is outside the CI baseline.

Live DEV testing is separate and only runs when explicitly requested. Read [operational safeguards](operating-standard.md), require a green `scripts/ssf-dev-preflight.ps1`, use `curl.exe` for WordPress HTTP, and stop if the target leaves `/dev`. Production access and deployment are excluded from this workflow.

## Manual GitHub settings

Configure a ruleset for `main`: require a PR, one approval when another maintainer is available, dismissal of stale approvals, resolution of review conversations, and the required check **PHP lint and local tests** (select after its first run). Require branches to be up to date before merge. Block force pushes and branch deletion; apply rules to administrators with minimal bypass permissions.

Prefer **squash merging** and automatic deletion of merged feature branches. Disable other merge methods if single-commit PR history is desired. Allow `actions/checkout` and `shivammathur/setup-php` in Actions settings; use read-only default workflow permissions and leave **Allow GitHub Actions to create and approve pull requests** disabled. Retain approval requirements for fork workflows; do not execute untrusted code with privileged credentials using `pull_request_target`. CI needs no deployment environment or secrets.
