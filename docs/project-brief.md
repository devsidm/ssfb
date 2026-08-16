# SSF Website Project Brief

## Goal

Create and maintain a WordPress website for SSF using GitHub for version control and Codex for implementation support.

## Current status

- Repository: https://github.com/devsidm/ssfb.git
- Platform: WordPress
- Theme: custom starter theme in `wp-content/themes/ssf`
- Site plugin: `wp-content/plugins/ssf-site-customizations`
- Dev site: https://ssfb.se/dev

## Public inspection notes

- Public REST root is reachable at `https://ssfb.se/dev/wp-json/`.
- Site name currently reports as `ssfb-dev`.
- No published public pages or posts were found during the first public REST check.
- Authenticated inspection is scripted in `scripts/wp-rest-inspect.ps1` and requires `SSF_WP_APP_PASSWORD` in the shell environment.

## Decisions to confirm

- Final organization/name behind "SSF"
- Brand colors, logo, and typography
- Required pages and navigation
- Hosting provider and deployment workflow
- Required plugins or integrations
- Languages and accessibility requirements

## Suggested first pages

- Home
- About
- News
- Contact

## Content needed

- Logo
- Short description
- Primary contact information
- Hero image or visual direction
- Any existing copy, PDFs, or links to migrate
