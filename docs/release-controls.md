# SSF Release Controls

`wp-content/mu-plugins/ssf-release-controls.php` is loaded automatically by WordPress. It provides the shared environment, release information, and central feature manager for all SSF plugins.

## Normal operation

Manage public functionality in **SSF > Funktioner**. Every feature has three states:

| State | Meaning |
| --- | --- |
| `Av` | The public page, menu link, and form entry points are closed. Data and WordPress admin remain intact. |
| `Endast administratörer` | Users with `manage_ssf_features` can preview and test the feature. Public visitors see an SSF information message. |
| `Publik` | The feature is available to normal visitors. |

The registered features are:

- Ansökan
- Årsmöten
- Årsmötesanmälan
- Motioner
- Kalender
- Medlemsfartyg

Feature states are stored per WordPress installation in the `ssf_feature_settings` option. DEV and PROD therefore never share feature settings. Medlemsfartyg controls the public catalogue, individual ship pages, and ship shortcodes; internal ship administration and token-based collection links remain available.

Sensitive features require a confirmation before they can be made public: Ansökan, Årsmötesanmälan, and Motioner. The last 100 changes are retained in the Feature Manager audit log.

## Defaults and migration

New installations use defensive defaults:

| Feature | Default |
| --- | --- |
| Ansökan | Av |
| Årsmöten | Av |
| Årsmötesanmälan | Av |
| Motioner | Av |
| Kalender | Publik |
| Medlemsfartyg | Publik |

Older `SSF_FEATURE_*` constants are still read if there is no setting saved in WordPress. `true` maps to `Publik` and `false` maps to `Av`. They are shown as `Äldre wp-config.php` in **SSF > Funktioner** and should be removed once each environment has been configured through the admin interface.

## Environment and emergency overrides

Set only the environment and release details in `wp-config.php`. See [wp-config-release.example.php](wp-config-release.example.php).

For an emergency shutdown, add an override before WordPress is loaded:

```php
define('SSF_FEATURE_MOTIONS_OVERRIDE', 'off');
```

Every feature supports an override using the format `SSF_FEATURE_<FEATURE>_OVERRIDE`, for example:

```php
define('SSF_FEATURE_APPLICATIONS_OVERRIDE', 'admin');
define('SSF_FEATURE_MEMBER_VESSELS_OVERRIDE', 'off');
```

Valid values are `off`, `admin`, and `public`. An override always wins over the WordPress setting, locks its controls in **SSF > Funktioner**, and is shown in **SSF > Systemstatus**. Remove the override to resume normal WordPress-admin control.

## Production release sequence

1. Back up the production database and `wp-content/uploads`.
2. Deploy the same custom `wp-content` code as DEV, including the `ssf-release-controls.php` MU-plugin.
3. Set the environment and release metadata in `wp-config.php`.
4. In **SSF > Funktioner**, set the desired PROD states. A safe initial setup is: Ansökan `Av`, Årsmöten `Av`, Årsmötesanmälan `Av`, Motioner `Endast administratörer`, Kalender `Publik`, and Medlemsfartyg `Publik`.
5. Purge WordPress, host, and CDN/page cache.
6. Open **SSF > Systemstatus** and confirm the environment, release, feature state, and source for every feature.
7. Smoke test the pages and forms that were changed before announcing the release.

## Smoke tests

- A feature set to `Av` is absent from the public menu, shows a human-readable direct-page message, and rejects its form submission.
- A feature set to `Endast administratörer` is hidden from visitors and visible to a permitted administrator with a `TESTLÄGE` banner.
- A feature set to `Publik` is visible and works according to its ordinary date and form rules.
- An override in `wp-config.php` wins over a WordPress-admin value and unlocks again when removed.
- Turning a feature off never deletes applications, motions, registrations, ships, SharePoint files, or plugin tables.
