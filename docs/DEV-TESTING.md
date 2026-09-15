# SSF DEV Testing

This guide documents the permanent SSF DEV test harness. It is based on methods already verified against `https://ssfb.se/dev`.

## What The Harness Does

The harness gives Codex and human operators a repeatable way to:

- load the DEV environment map
- load private credentials without committing secrets
- log in to WordPress using `curl.exe`
- run a mandatory DEV preflight
- list known test fixtures
- avoid accidental PROD targeting
- avoid legacy SharePoint metadata

It does not deploy, create release builds, repair SharePoint schema or reset workflow state.

## Source Files

- `AGENTS.md`: Codex operating rules.
- `config/environments.json`: static verified DEV/PROD environment identifiers.
- `config/test-fixtures.json`: known reusable or non-reusable DEV test entities.
- `config/ssf-dev-test.env.example`: example variable names only.
- `scripts/ssf-wp-login.ps1`: WordPress curl login helper.
- `scripts/ssf-dev-preflight.ps1`: mandatory DEV gate.
- `scripts/ssf-test-fixture.ps1`: fixture inventory helper.

## Secrets

Do not put real secrets in the repository.

Create a private file outside the repo:

Windows:

```text
%USERPROFILE%\.ssf\ssf-dev-test.env
```

Example contents:

```text
SSF_WP_USER=ssfhosting
SSF_WP_PASSWORD=<set locally>
```

The verified WordPress login path requires only `SSF_WP_USER` and `SSF_WP_PASSWORD`.

Secret loading priority:

1. already-set process environment variables
2. explicit `SSF_SECRETS_FILE`
3. user-private default file outside the repository

## Login

Run:

```powershell
.\scripts\ssf-wp-login.ps1
```

Expected success includes:

```text
curl login         PASS
wp-admin           PASS
Result             PASS
```

The script never prints the password or cookie contents.

## Why Curl Is Required

WordPress form login has been verified with `curl.exe`, cookie jar, redirects and `--data-urlencode`. PowerShell web sessions previously produced `reauth=1` and did not reliably keep the WordPress admin cookie.

Therefore:

- use `curl.exe` for all WordPress login and HTTP testing
- do not use PowerShell HTTP cmdlets for WordPress test traffic
- PowerShell is only orchestration

## Preflight

Run before any live DEV test:

```powershell
.\scripts\ssf-dev-preflight.ps1
```

Machine-readable output:

```powershell
.\scripts\ssf-dev-preflight.ps1 -Json
```

The script writes:

```text
artifacts/dev-preflight.json
```

This file is ignored by Git because it is runtime output.

Preflight checks:

- environment JSON parses
- fixture JSON parses
- UTF-8 names are intact
- release manifest exists
- Git HEAD and working tree are displayed
- target is exactly `https://ssfb.se/dev`
- WordPress login works via curl
- canonical membership fields are present in config
- known legacy fields are marked non-authoritative
- motion status choices are present
- public status routes bypass blanket DEV login
- protected routes redirect to login
- wrong FTP root warning is displayed

If preflight fails, stop.

## Fixtures

List fixtures:

```powershell
.\scripts\ssf-test-fixture.ps1 list
```

Show one fixture:

```powershell
.\scripts\ssf-test-fixture.ps1 show SSF-2026-0025
```

Known fixture state is inventory data. Verify live state before mutation.

Version 1 does not reset workflow state and does not create new test data.

## DEV Versus PROD

DEV is:

```text
https://ssfb.se/dev
```

PROD is:

```text
https://ssfb.se
```

Preflight refuses to treat PROD as DEV. PROD operations require explicit user authorization and are outside ordinary DEV testing.

## SharePoint

Static identifiers are in `config/environments.json`.

Live WordPress runtime config is authoritative for active SharePoint destinations. The currently verified option is:

```text
ssf_member_portal_sharepoint_destinations
```

Runtime WordPress must not repair schema. SharePoint schema provisioning is manual/admin work because `Sites.Selected` write access is enough for file and metadata work but not schema management.

Prefer DriveItem/ListItem IDs where possible. Paths with Swedish characters must stay UTF-8:

```text
General/Medlemsansökningar
General/Årsmöten
```

## Email

Central mail renderer:

```text
wp-content/mu-plugins/ssf-email-template.php
```

Mail router:

```text
wp-content/mu-plugins/ssf-email-router.php
```

Technical mail success means WordPress/template transport returned success or wrote a success event. It is not the same as actual mailbox delivery. Inbox delivery requires human confirmation.

## Existing Tests

Preserve and use existing tests:

```powershell
.\scripts\tests\ssf-dev-protection-tests.ps1
.\scripts\tests\ssf-membership-application-tests.ps1
.\scripts\tests\ssf-sharepoint-destination-tests.ps1
.\scripts\tests\ssf-release-tests.ps1
```

Release scripts:

```powershell
.\scripts\ssf-release-build.ps1
.\scripts\ssf-release-deploy.ps1
```

The new harness complements these tests.

## Updating Environment Values

When SharePoint or WordPress resources change:

1. Verify the new resource outside production-impacting flows.
2. Update `config/environments.json`.
3. Keep UTF-8 intact.
4. Run `.\scripts\tests\ssf-test-harness-tests.ps1`.
5. Run `.\scripts\ssf-dev-preflight.ps1`.

Do not update the JSON from conversational memory alone.

## Future Improvements

A dedicated WordPress automation account may be useful later:

```text
codex-test
```

Potential benefits:

- least privilege
- separate credential
- easy revocation
- clearer audit trail
- avoids using a personal/admin login

A WordPress Application Password named `SSF DEV automated tests` may also be useful later. Neither the account nor the application password is created by this harness task.
