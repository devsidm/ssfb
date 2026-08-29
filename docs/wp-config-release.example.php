<?php
/**
 * Copy the applicable block to wp-config.php during deployment.
 * Do not commit the real wp-config.php.
 *
 * Normal release metadata belongs in SSF > Release.
 * Normal feature settings belong in SSF > Funktioner.
 * The optional overrides below are technical emergency controls and always
 * take precedence.
 */

// DEV: ssfb.se/dev
define('WP_ENVIRONMENT_TYPE', 'development');

// PROD: ssfb.se
// define('WP_ENVIRONMENT_TYPE', 'production');

// Legacy fallback for non-production only. Prefer SSF > Release.
// define('SSF_RELEASE_VERSION', '1.0.0');
// define('SSF_RELEASE_DATE', '2026-08-29');
// define('SSF_RELEASE_COMMIT', 'set-at-deployment');

// Emergency overrides only. Allowed values: off, admin, public.
// define('SSF_FEATURE_APPLICATIONS_OVERRIDE', 'off');
// define('SSF_FEATURE_ANNUAL_MEETINGS_OVERRIDE', 'off');
// define('SSF_FEATURE_ANNUAL_MEETING_REGISTRATION_OVERRIDE', 'off');
// define('SSF_FEATURE_MOTIONS_OVERRIDE', 'off');
// define('SSF_FEATURE_CALENDAR_OVERRIDE', 'off');
// define('SSF_FEATURE_NEWSLETTERS_OVERRIDE', 'off');
// define('SSF_FEATURE_MEMBER_VESSELS_OVERRIDE', 'off');
