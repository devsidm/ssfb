<?php
/**
 * Copy the applicable block to wp-config.php during deployment.
 * Do not commit the real wp-config.php.
 */

// DEV: ssfb.se/dev
define('WP_ENVIRONMENT_TYPE', 'development');
define('SSF_RELEASE_VERSION', '2026.08.27-dev');
define('SSF_RELEASE_DATE', '2026-08-27');
define('SSF_RELEASE_COMMIT', 'set-at-deployment');
define('SSF_FEATURE_APPLICATIONS', true);
define('SSF_FEATURE_MOTIONS', true);
define('SSF_FEATURE_ANNUAL_MEETINGS', true);
define('SSF_FEATURE_ANNUAL_MEETING_REGISTRATION', true);
define('SSF_FEATURE_CALENDAR', true);

// PROD: ssfb.se
// define('WP_ENVIRONMENT_TYPE', 'production');
// define('SSF_RELEASE_VERSION', '2026.08.27.1');
// define('SSF_RELEASE_DATE', '2026-08-27');
// define('SSF_RELEASE_COMMIT', 'set-at-deployment');
// define('SSF_FEATURE_APPLICATIONS', false);
// define('SSF_FEATURE_MOTIONS', true);
// define('SSF_FEATURE_ANNUAL_MEETINGS', false);
// define('SSF_FEATURE_ANNUAL_MEETING_REGISTRATION', false);
// define('SSF_FEATURE_CALENDAR', true);
