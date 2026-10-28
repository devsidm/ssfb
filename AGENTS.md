# SSF development instructions

- GitHub (`devsidm/ssfb`) is the source of truth; `main` is the stable base.
- Start from current `main` in a focused branch such as `codex/fix-news-title`. Preserve existing work; never reset it to switch branches. Cloud tasks are isolated: use the existing checkout, not a new worktree.
- Keep changes scoped and commits suitable for pull requests. Do not push without explicit authorization. Do not create release builds or change manifests for tooling/documentation work.
- Do not modify WordPress core. This checkout contains custom code, not a complete WordPress installation.
- Do not access production, deploy, or modify production data. Deployment is outside the normal Codex workflow.
- Never add, print, or commit secrets, tokens, private environment files, FTP credentials, or WordPress admin credentials.
- The existing development site is `https://ssfb.se/dev`; do not install another WordPress environment. Local tests need no live site or secrets.
- Before any explicitly requested live DEV test, read [operational safeguards](docs/development/operating-standard.md), run `pwsh -NoProfile -File scripts/ssf-dev-preflight.ps1`, and stop unless green. Fail closed outside `/dev`. Use `curl.exe` for WordPress HTTP/login, never PowerShell HTTP cmdlets.
- For SharePoint work, read the operational safeguards and `config/environments.json`. Never guess IDs or repair schema during tests; canonical membership status is `ApplicationStatus`.
- Begin with task-named files and nearby code. Avoid broad repository scans; read only task-relevant documentation. See [Codex Cloud development](docs/development/codex-cloud.md) for tooling and test commands.
- Prefer existing targeted tests; do not run every suite or live workflow by default. PHP lint is the existing syntax check; no formatter or package build is required.
- Keep completion reports concise: changes, validation, and remaining limitations.
