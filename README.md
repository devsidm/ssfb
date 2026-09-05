# SSF WordPress Website

This repository contains the custom WordPress theme and project notes for the SSF website.

## Project structure

```text
docs/
  project-brief.md
wp-content/
  themes/
    ssf/
      assets/
      inc/
      parts/
      templates/
```

## Local WordPress setup

Use this repository inside a WordPress installation, or copy/symlink `wp-content/themes/ssf` into the `wp-content/themes` directory of an existing local WordPress site.

Then activate the theme in WordPress admin:

```text
Appearance > Themes > SSF
```

## Development workflow

1. Make changes in a feature branch.
2. Test locally in WordPress.
3. Commit and push changes to GitHub.
4. Register a unique build with `scripts/ssf-release-build.ps1`.
5. Commit the release manifest and deploy with `scripts/ssf-release-deploy.ps1`.
6. Confirm the verified build under **SSF > Release**.

## Releases

The complete DEV and production workflow, environment configuration, build IDs, version preparation, feature flags, and recovery steps are documented in [docs/release-controls.md](docs/release-controls.md).

## Notes for Codex

- Keep project-specific notes in `docs/`.
- Keep theme logic small and readable.
- Avoid committing WordPress uploads, caches, secrets, or local configuration.
