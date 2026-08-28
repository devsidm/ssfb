<?php
/**
 * Copy the applicable block to wp-config.php during deployment.
 * Do not commit the real wp-config.php.
 *
 * Normal feature settings belong in SSF > Funktioner. The optional overrides
 * below are technical emergency controls and always take precedence.
 */

// DEV: ssfb.se/dev
define('WP_ENVIRONMENT_TYPE', 'development');
define('SSF_RELEASE_VERSION', '2026.08.28-dev');
define('SSF_RELEASE_DATE', '2026-08-28');
define('SSF_RELEASE_COMMIT', 'set-at-deployment');

// PROD: ssfb.se
// define('WP_ENVIRONMENT_TYPE', 'production');
// define('SSF_RELEASE_VERSION', '2026.08.28.1');
// define('SSF_RELEASE_DATE', '2026-08-28');
// define('SSF_RELEASE_COMMIT', 'set-at-deployment');

// Emergency overrides only. Allowed values: off, admin, public.
// define('SSF_FEATURE_APPLICATIONS_OVERRIDE', 'off');
// define('SSF_FEATURE_ANNUAL_MEETINGS_OVERRIDE', 'off');
// define('SSF_FEATURE_ANNUAL_MEETING_REGISTRATION_OVERRIDE', 'off');
// define('SSF_FEATURE_MOTIONS_OVERRIDE', 'off');
// define('SSF_FEATURE_CALENDAR_OVERRIDE', 'off');
// define('SSF_FEATURE_MEMBER_VESSELS_OVERRIDE', 'off');
