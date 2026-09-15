# SSF Codex Operating Standard

This repository is for Sveriges Segelfartygsförbund (SSF).

## WordPress Login / HTTP

- ALWAYS use `curl.exe` for WordPress login and HTTP testing.
- NEVER use `Invoke-WebRequest`.
- NEVER use `Invoke-RestMethod`.
- PowerShell scripts may orchestrate filesystem, JSON parsing, local checks and process control, but HTTP transport must still be `curl.exe`.
- Do not invent a new authentication architecture when the verified curl form login works.

## Before Live Testing

- ALWAYS run `.\scripts\ssf-dev-preflight.ps1`.
- If preflight is not green, STOP.
- Do not "try anyway".
- Do not mutate live DEV workflow data until the requested test is clear and preflight has passed.

## Environments

DEV:
`https://ssfb.se/dev`

PROD:
`https://ssfb.se`

PROD is forbidden unless the user explicitly authorizes a PROD operation. The DEV harness must fail closed if the target becomes `https://ssfb.se` without `/dev`.

## SharePoint

- Use `config/environments.json`.
- Do not guess site, drive, list, folder, DriveItem or ListItem IDs.
- Prefer verified DriveItem/ListItem IDs over folder path lookup.
- Runtime WordPress verifies SharePoint schema; it does not repair schema.
- Do not create columns, patch Choice values, delete legacy columns, rename folders or delete folders during test harness work.
- Membership canonical status field is `ApplicationStatus`, not legacy `Status`.
- Legacy membership fields may exist physically but must not be authoritative: `Ansokningsnummer`, `Status`, `Fartyg`, `InkommenDatum`, `Ansokningsvag`.

## Secrets

- Never print secrets.
- Never commit secrets.
- Never copy secrets into logs.
- Never search arbitrary files for secrets.
- Never dump the full process environment.
- Use process environment, `SSF_SECRETS_FILE`, or a private file outside the repo:
  - Windows: `%USERPROFILE%\.ssf\ssf-dev-test.env`
  - Linux/macOS: `$HOME/.ssf/ssf-dev-test.env`

## Test Cases

- Read `config/test-fixtures.json`.
- Check current live state before reuse.
- Do not silently choose a fixture only because its ID exists.
- Do not reuse terminal-state cases for incompatible transitions.

## Deployment

- Never push GitHub without explicit approval.
- Never deploy PROD without explicit approval.
- Do not copy DEV database or `wp-config.php` to PROD.
- Active DEV FTP root is `public_html/dev`.
- Known wrong FTP root: `/wp-content/...` at FTP account root. Uploading there does not affect active DEV.
- Production server deployment uses `scripts/deploy/ssf-server-deploy.sh`.
- The production server GitHub deploy key is read-only.
- Do not invent another production deploy path or deploy directly from random local files.
- Do not bypass repository tests, PHP lint, DEV verification or production backups.
- Do not use `rsync --delete`.
- PROD deployment requires one exact `DEPLOY` confirmation.
- DEV remains the staging/prepared artifact; Git remains the code source of truth.

## Coding

- Use existing working mechanisms first.
- Keep harness work outside runtime WordPress code unless the user explicitly asks for a runtime change.
- If only `AGENTS.md`, `docs/`, `config/`, `scripts/`, tests or `.gitignore` change, do not create a release build.

## Known Failure Modes

SYMPTOM: Membership metadata sync appears successful but status is wrong.
CAUSE: Legacy membership SharePoint fields were active.
CORRECT: Use canonical mapping, especially `ApplicationStatus`.

SYMPTOM: `MedlemsansÃƒÂ¶kningar` or `Ãƒâ€¦rsmÃƒÂ¶ten` appears.
CAUSE: Encoding/mojibake.
CORRECT: Keep UTF-8 and prefer DriveItem IDs.

SYMPTOM: SharePoint schema create fails unpredictably.
CAUSE: Parallel/batch schema operations.
CORRECT: Sequential manual provisioning.

SYMPTOM: Schema repair gets 403.
CAUSE: `Sites.Selected` write does not imply schema management.
CORRECT: Runtime verifies; admin provisions schema.

SYMPTOM: Public status routes redirect to `wp-login.php`.
CAUSE: DEV blanket login protection caught status routes.
CORRECT: Narrow status-route bypass with `/dev` normalization.

SYMPTOM: Live behavior differs from repo.
CAUSE: Live-only MU plugin.
CORRECT: All active MU plugins must be represented in repo.

SYMPTOM: Temporary harness does nothing.
CAUSE: Uploaded to FTP account root rather than active DEV tree.
CORRECT: Use `public_html/dev/wp-content/...`.

SYMPTOM: Workflow action unavailable.
CAUSE: Reused case already past the required state.
CORRECT: Check current state before selecting fixture.

SYMPTOM: Admin config page displays but save returns 403.
CAUSE: Not fully established.
CORRECT: Treat as known P1 issue; do not silently mutate configuration during normal tests.

## Standard Flow

1. Read this file.
2. Load private secrets outside the repo.
3. Run `.\scripts\ssf-dev-preflight.ps1`.
4. If green, run `.\scripts\ssf-test-fixture.ps1 list`.
5. Select fixtures only after verifying current live state.
6. Run the requested DEV test.
7. Report WP IDs, SharePoint IDs, statuses, technical mail results and whether mailbox delivery was human-confirmed.
