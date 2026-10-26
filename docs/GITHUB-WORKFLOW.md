# GitHub, Codex and release workflow

This repository uses pull requests for code review and GitHub Actions for
offline verification. Live DEV and PROD credentials do not belong in GitHub
Actions or Codex Cloud.

## Daily development

1. Start from an updated `main` branch.
2. Create a short-lived branch such as `fix/annual-meeting-layout`.
3. Let Codex Cloud or a local Codex session make and test the change.
4. Push the branch and open a pull request into `main`.
5. Require the `offline-tests` status check and one approving review.
6. Ask for Codex review automatically or by commenting `@codex review`.
7. Merge only when CI, review and conversations are complete.

The CI workflow runs without repository secrets. Its allowlisted regression
suite deliberately excludes `ssf-news-dev-flow.ps1` and all other live entry
points.

## Release to DEV and PROD

1. Create a dedicated release branch from the exact reviewed commit.
2. Prepare the release manifest and plugin version bumps on that branch.
3. Open and review a release pull request. Do not rewrite its source revision.
4. Run the mandatory local preflight, deploy the frozen release to DEV and
   complete relevant live verification there.
5. Merge the release pull request after DEV is green.
6. On the production server, pull the reviewed commit and run the existing
   `ssf-deploy` path. The operator must still enter the exact `DEPLOY`
   confirmation. Never deploy PROD from a GitHub Actions runner or Codex Cloud.

## GitHub repository settings

Create a ruleset for the default branch `main` with these controls:

- Require a pull request before merging.
- Require one approval and dismiss stale approvals after new commits.
- Require review from Code Owners.
- Require the `offline-tests` status check and an up-to-date branch.
- Require all conversations to be resolved.
- Block force pushes and branch deletion.
- Allow only repository administrators to bypass the ruleset for emergencies.

Enable automatic Codex review for the repository in Codex settings. Keep the
repository `AGENTS.md` as the source for SSF-specific review rules. Mechanical
checks remain in GitHub Actions.

## Codex Cloud environment

In ChatGPT Work, open **Cloud**, create an environment, connect the
`devsidm/ssfb` repository and select the default branch. The environment needs
no SSF secrets for normal code work. Do not add FTP, WordPress, SharePoint,
Microsoft 365 or production credentials. Publish each task to a feature branch
and open a pull request instead of committing directly to `main`.
