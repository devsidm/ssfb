#!/usr/bin/env bash
# No package dependencies currently exist; validate the minimal toolchain.
set -euo pipefail
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"
for tool in git php; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        printf 'Missing %s. Provision Git and PHP 8.3 CLI in the environment.\n' "$tool" >&2
        exit 1
    fi
done
php -r '
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Use PHP 8.3 CLI to match CI.\n");
    exit(1);
}
foreach (["dom", "libxml", "mbstring"] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Missing PHP extension: " . $extension . "\n");
        exit(1);
    }
}
echo "SSF toolchain ready: PHP " . PHP_VERSION . "; no package installation required.\n";
'
# No Composer/npm manifests or lockfiles exist. Do not install global tools,
# WordPress, a database, or services. Revisit if dependency manifests are added.
