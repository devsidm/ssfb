<?php

/*
 * Add these constants to the server's wp-config.php. Do not commit the real
 * client secret. Prefer an environment variable supplied by the hosting
 * platform; leave the fallback empty when no environment variable is set.
 */
define('SSF_GRAPH_TENANT_ID', 'YOUR-TENANT-ID');
define('SSF_GRAPH_CLIENT_ID', 'YOUR-CLIENT-ID');
define('SSF_GRAPH_CLIENT_SECRET', getenv('SSF_GRAPH_CLIENT_SECRET') ?: '');
define('SSF_GRAPH_SITE_ID', 'YOUR-SHAREPOINT-SITE-ID');
define('SSF_GRAPH_DRIVE_ID', 'YOUR-DOCUMENT-LIBRARY-DRIVE-ID');
define('SSF_GRAPH_ANNUAL_MEETING_FOLDER_ID', 'YOUR-ARSMOTEN-FOLDER-ID');
define('SSF_GRAPH_ANNUAL_MEETING_FOLDER_NAME', 'Årsmöten');

/* Optional, but enables verification by SharePoint host and path. */
define('SSF_GRAPH_SITE_HOSTNAME', 'YOUR-TENANT.sharepoint.com');
define('SSF_GRAPH_SITE_PATH', '/sites/YOUR-SITE');
