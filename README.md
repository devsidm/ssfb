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
4. Deploy through the hosting workflow once confirmed.

## Notes for Codex

- Keep project-specific notes in `docs/`.
- Keep theme logic small and readable.
- Avoid committing WordPress uploads, caches, secrets, or local configuration.
