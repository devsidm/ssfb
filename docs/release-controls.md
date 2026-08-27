# SSF Release Controls

`wp-content/mu-plugins/ssf-release-controls.php` is loaded automatically by WordPress. It is the one place that determines the SSF environment, release metadata, and public feature flags.

## Current assessment

- Neither PROD nor DEV currently has `WP_ENVIRONMENT_TYPE` or SSF release constants in `wp-config.php`.
- Motion records remain related to `annual_meeting_id`; their public entry point is independent of the annual-meeting frontend.
- The application flow has two legacy submit actions. Both are guarded when applications are disabled.
- Annual-meeting data and admin remain available when its public frontend is disabled.

## Configure each environment

Copy the applicable block from [wp-config-release.example.php](wp-config-release.example.php) to the matching server's `wp-config.php`, above the line that loads WordPress. Set `SSF_RELEASE_COMMIT` to the deployed short Git SHA.

Recommended production settings:

| Feature | Production |
| --- | --- |
| Applications | OFF |
| Motions | ON |
| Annual meetings | OFF |
| Annual-meeting registration | OFF |
| Calendar | ON |

Development defaults to all features on. Until the explicit configuration is installed, the current `/dev` installation is detected centrally from its WordPress installation path. Production defaults are deliberately conservative: applications, annual-meeting pages, and registration are off; motions and calendar are on.

## Production release sequence

1. Back up the production database and `wp-content/uploads`.
2. Deploy the same `wp-content` code as DEV, including `wp-content/mu-plugins/ssf-release-controls.php`.
3. Set the production constants in `wp-config.php` with the desired version, date, and commit.
4. Purge WordPress, host, and CDN/page cache so the footer release marker changes immediately.
5. Open **SSF > Systemstatus** and confirm environment, release, and feature flags.
6. Smoke test the items below before announcing the release.

## Smoke tests

- Home page renders and footer shows `Release <version> - PROD`.
- `/motioner/` opens, presents the active meeting or a closed-period message, and links to the form/status page.
- A safe test motion uploads to SharePoint and gets `Status = Inkommen`.
- The motion confirmation email and personal status URL work.
- `/ansokan/` shows the human-readable closed message and a direct POST to either application action creates no record.
- `/arsmote/`, `/arsmoten/`, and `/arsmote/anmalan/` are not publicly exposed while their flags are off.
- Calendar shows manual events but not annual-meeting items while annual meetings are off.
- Check the PHP error log after the test.

## Re-enabling a feature

Do not change code or branches to publish a completed module. Change the matching `SSF_FEATURE_*` value in production `wp-config.php`, purge cache, and rerun the affected smoke test.
