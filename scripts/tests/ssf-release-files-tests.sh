#!/usr/bin/env bash
set -Eeuo pipefail
source "$(dirname "$0")/../deploy/ssf-release-files.sh"

fixture="$(mktemp -d "${TMPDIR:-/tmp}/ssf-release-files-test.XXXXXXXX")"
cleanup_fixture() {
  [[ "$fixture" == "${TMPDIR:-/tmp}"/ssf-release-files-test.* ]] && rm -rf -- "$fixture"
}
trap cleanup_fixture EXIT
mkdir -p "$fixture/release" "$fixture/dev" "$fixture/prod"
printf 'A\n' > "$fixture/release/A.php"
printf 'B\n' > "$fixture/release/B.php"
cp "$fixture/release/"*.php "$fixture/dev/"
release_verify_subset "$fixture/release" "$fixture/dev" 'matching DEV' >/dev/null

printf 'local config\n' > "$fixture/dev/local-dev-config.php"
printf 'runtime data\n' > "$fixture/dev/cache.json"
release_verify_subset "$fixture/release" "$fixture/dev" 'DEV with extra files' >/dev/null
[[ "$(release_count_additional "$fixture/release" "$fixture/dev")" == 2 ]]
cp "$fixture/release/"*.php "$fixture/prod/"
[[ "$(release_count_additional "$fixture/release" "$fixture/prod")" == 0 ]]
[[ ! -e "$fixture/prod/local-dev-config.php" ]]

printf 'unknown PROD data\n' > "$fixture/prod/local-prod-config.php"
release_verify_subset "$fixture/release" "$fixture/prod" 'PROD with unknown file' >/dev/null
[[ "$(release_count_additional "$fixture/release" "$fixture/prod")" == 1 ]]
[[ -f "$fixture/prod/local-prod-config.php" ]]

mv "$fixture/dev/B.php" "$fixture/B.missing"
if release_verify_subset "$fixture/release" "$fixture/dev" 'missing B' >/dev/null 2>&1; then exit 1; fi
mv "$fixture/B.missing" "$fixture/dev/B.php"
printf 'modified\n' > "$fixture/dev/B.php"
if release_verify_subset "$fixture/release" "$fixture/dev" 'modified B' >/dev/null 2>&1; then exit 1; fi

mkdir "$fixture/git"
git -C "$fixture/git" init -q
git -C "$fixture/git" config user.name 'SSF Fixture'
git -C "$fixture/git" config user.email 'fixture@example.invalid'
git -C "$fixture/git" config core.autocrlf false
printf 'release one\n' > "$fixture/git/code.php"
git -C "$fixture/git" add code.php
git -C "$fixture/git" commit -qm one
old_revision="$(git -C "$fixture/git" rev-parse HEAD)"
printf 'later main\n' > "$fixture/git/code.php"
git -C "$fixture/git" commit -qam two
mkdir "$fixture/frozen"
git -C "$fixture/git" archive "$old_revision" | tar -x -C "$fixture/frozen"
[[ "$(<"$fixture/frozen/code.php")" == 'release one' ]]
echo 'PASS: frozen Git source, DEV subset, extra files, missing and changed files.'
