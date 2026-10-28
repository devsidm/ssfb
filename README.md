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

1. Start from current `main` in a focused feature branch.
2. Run `bash scripts/codex-setup.sh` and the relevant local checks.
3. Review the diff, commit the scoped change, and open a pull request against `main` when authorized to push.

See [Codex Cloud development](docs/development/codex-cloud.md) for runtimes, targeted tests and GitHub settings. The existing WordPress development site remains `https://ssfb.se/dev`; local WordPress is not required for Codex tasks. Live DEV testing requires the documented preflight. Release builds and deployment are a separate operational workflow and are not part of normal Codex tasks.

## Releases

The complete DEV and production workflow, environment configuration, build IDs, version preparation, feature flags, and recovery steps are documented in [docs/release-controls.md](docs/release-controls.md).

## Notes for Codex

- Keep project-specific notes in `docs/`.
- Keep theme logic small and readable.
- Avoid committing WordPress uploads, caches, secrets, or local configuration.
