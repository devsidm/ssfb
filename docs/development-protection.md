# Development Protection

The development WordPress installation must define `WP_ENVIRONMENT_TYPE` as
`development`. `ssf-dev-protection.php` then enforces WordPress login for
frontend requests, sets `blog_public` to `0`, sends noindex headers, adds HTML
robots metadata, and disables the core WordPress sitemap.

REST requests are deliberately excluded from the login redirect so WordPress
Application Passwords and integrations continue to use normal WordPress
authorization. wp-admin, wp-login, cron, and admin-ajax are also excluded.

The protection has no effect unless `wp_get_environment_type()` returns
`development`; production is not changed by this plugin.

`robots.txt` is only a crawler instruction, not access control. The production
domain's robots file must contain `Disallow: /dev/` while preserving its other
rules. Direct static upload files may bypass WordPress, so their response
headers must be verified at the web-server layer.
