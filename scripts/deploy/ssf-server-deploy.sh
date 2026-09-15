#!/usr/bin/env bash
set -Eeuo pipefail

HOME_DIR="${SSF_HOME:-$HOME}"
REPO="$HOME_DIR/repos/ssfb"
DEV="$HOME_DIR/ssfb.se/public_html/dev"
PROD="$HOME_DIR/ssfb.se/public_html"
BACKUP_ROOT="$HOME_DIR/ssf-backups"
DEV_WP_CLI="$DEV/wp-cli.phar"
PROD_WP_CLI="$PROD/wp-cli.phar"
ERROR_LOG="$HOME_DIR/ssfb.se/logs/error_log"
CONFIG="$REPO/config/deploy-components.json"
EXPECTED_PROD_URL="https://ssfb.se"
MAINTENANCE_GRACE_SECONDS="${SSF_MAINTENANCE_GRACE_SECONDS:-10}"

TEST_STATUS="NOT RUN"
PHP_STATUS="NOT RUN"
DEV_SYNC_STATUS="NOT RUN"
DEV_SMOKE_STATUS="NOT RUN"
PROD_DB_STATUS="NOT RUN"
PROD_TARGET_STATUS="NOT RUN"
DRY_RUN_STATUS="NOT RUN"
DB_BACKUP_STATUS="NOT RUN"
FILE_BACKUP_STATUS="NOT RUN"
FILE_DEPLOY_STATUS="NOT RUN"
RELEASE_VERIFY_STATUS="NOT RUN"
PLUGIN_VERIFY_STATUS="NOT RUN"
THEME_VERIFY_STATUS="NOT RUN"
HTTP_SMOKE_STATUS="NOT RUN"
ERROR_LOG_STATUS="NOT RUN"
PLUGIN_PARITY_STATUS="NOT RUN"
MAINTENANCE_STATUS="NOT RUN"
BACKUP_DIR=""
BUILD=""
VERSION=""
MANIFEST_STATUS=""
GIT_HEAD=""
ERROR_LOG_LINES=0
MAINTENANCE_ACTIVE=0
MAINTENANCE_STARTED_AT=0
MAINTENANCE_ENDED_AT=0
PROD_MUTATED=0
DEPLOY_SUCCESS=0
DEPLOYMENT_STARTED=0
FILES_DEPLOYED=0
PLUGINS_ACTIVATED=0
VERIFICATION_FAILED=0
PRE_DEPLOY_BUILD=""
PRE_DEPLOY_VERSION=""
TURNSTILE_SITE_FINGERPRINT=""
TURNSTILE_SECRET_FINGERPRINT=""
PLUGIN_PLAN=""

fail() {
  echo "FAILURE: $*" >&2
  VERIFICATION_FAILED=1
  handle_failure_maintenance
  if [[ -n "$BACKUP_DIR" ]]; then
    echo "Backup directory: $BACKUP_DIR" >&2
    if [[ "$PROD_MUTATED" == "1" ]]; then
      echo "Public site: MAINTENANCE MODE" >&2
      echo "Recommended command: ssf-rollback" >&2
    fi
    echo "Rollback instructions: see docs/SERVER-DEPLOYMENT.md" >&2
  fi
  exit 1
}

section() {
  echo
  echo "============================================================"
  echo "$1"
  echo "============================================================"
}

handle_failure_maintenance() {
  if [[ "$MAINTENANCE_ACTIVE" != "1" ]]; then
    if [[ "$PROD_MUTATED" == "1" ]]; then
      activate_maintenance "failure after PROD mutation" >/dev/null 2>&1 || true
    fi
    return
  fi
  if [[ "$DEPLOY_SUCCESS" == "1" ]]; then
    deactivate_maintenance >/dev/null 2>&1 || true
  elif [[ "$PROD_MUTATED" == "1" ]]; then
    echo "Production maintenance remains ACTIVE because PROD was mutated." >&2
  else
    deactivate_maintenance >/dev/null 2>&1 || true
  fi
}

cleanup() {
  handle_failure_maintenance
  if [[ "$MAINTENANCE_ACTIVE" == "1" ]]; then
    echo "Final production maintenance state: ACTIVE" >&2
  else
    echo "Final production maintenance state: INACTIVE" >&2
  fi
}
trap cleanup EXIT INT TERM

json_array() {
  local path="$1"
  php -r '
    $file = $argv[1];
    $path = explode(".", $argv[2]);
    $data = json_decode(file_get_contents($file), true);
    foreach ($path as $part) {
      if (!is_array($data) || !array_key_exists($part, $data)) { exit(2); }
      $data = $data[$part];
    }
    if (!is_array($data)) { exit(3); }
    foreach ($data as $value) { echo $value, PHP_EOL; }
  ' "$CONFIG" "$path"
}

json_contains() {
  local path="$1"
  local needle="$2"
  php -r '
    $file = $argv[1];
    $path = explode(".", $argv[2]);
    $needle = $argv[3];
    $data = json_decode(file_get_contents($file), true);
    foreach ($path as $part) {
      if (!is_array($data) || !array_key_exists($part, $data)) { exit(1); }
      $data = $data[$part];
    }
    exit(in_array($needle, is_array($data) ? $data : array(), true) ? 0 : 1);
  ' "$CONFIG" "$path" "$needle"
}

manifest_field() {
  local manifest="$1"
  local field="$2"
  php -r '$m=json_decode(file_get_contents($argv[1]), true); echo (string)($m[$argv[2]] ?? "");' "$manifest" "$field"
}

wp_dev() {
  php "$DEV_WP_CLI" --path="$DEV" "$@"
}

wp_prod() {
  php "$PROD_WP_CLI" --path="$PROD" "$@"
}

wp_eval_dev() {
  wp_dev eval "$1"
}

wp_eval_prod() {
  wp_prod eval "$1"
}

sha256_file() {
  local file="$1"
  sha256sum "$file" | awk '{print $1}'
}

file_size() {
  local file="$1"
  stat -c '%s' "$file"
}

activate_maintenance() {
  local reason="${1:-deployment}"
  section "PRODUCTION MAINTENANCE"
  local timestamp marker
  timestamp="$(date +%s)"
  [[ "$timestamp" =~ ^[0-9]+$ ]] || fail "Maintenance timestamp is not numeric."
  printf '<?php $upgrading = %s; ?>\n' "$timestamp" > "$PROD/.maintenance"
  [[ -f "$PROD/.maintenance" ]] || fail "Production maintenance marker was not created."
  marker="$(cat "$PROD/.maintenance")"
  [[ "$marker" =~ ^\<\?php[[:space:]]+\$upgrading[[:space:]]*=[[:space:]]*[0-9]+[[:space:]]*\;[[:space:]]*\?\>$ ]] || fail "Production maintenance marker is malformed or non-numeric."
  MAINTENANCE_ACTIVE=1
  MAINTENANCE_STARTED_AT="$(date +%s)"
  verify_maintenance_active
  MAINTENANCE_STATUS="PASS"
  echo "Production maintenance: ACTIVE"
  echo "Reason: $reason"
}

deactivate_maintenance() {
  rm -f "$PROD/.maintenance"
  [[ ! -e "$PROD/.maintenance" ]] || fail "Production maintenance marker still exists after deactivation."
  MAINTENANCE_ACTIVE=0
  MAINTENANCE_ENDED_AT="$(date +%s)"
  verify_maintenance_inactive
  echo "Production maintenance: INACTIVE"
}

verify_maintenance_active() {
  [[ -f "$PROD/.maintenance" ]] || fail "Production maintenance marker missing."
  php -r '$c=trim(file_get_contents($argv[1])); exit(preg_match("/^<\?php\s+\$upgrading\s*=\s*[0-9]+\s*;\s*\?>$/", $c) ? 0 : 1);' "$PROD/.maintenance" || fail "Production maintenance marker timestamp is malformed."
  local status
  status="$(curl -sS -L -o /tmp/ssf-maintenance-check.html -w '%{http_code}' "$EXPECTED_PROD_URL/" || true)"
  if [[ "$status" != "503" ]] && ! rg -qi 'maintenance|underh.ll|briefly unavailable|upgrading' /tmp/ssf-maintenance-check.html; then
    rm -f /tmp/ssf-maintenance-check.html
    fail "Production maintenance mode did not block anonymous public HTTP. Status: $status"
  fi
  rm -f /tmp/ssf-maintenance-check.html
}

verify_maintenance_inactive() {
  [[ ! -e "$PROD/.maintenance" ]] || fail "Production maintenance marker still exists."
  local status
  status="$(curl -sS -L -o /tmp/ssf-maintenance-open.html -w '%{http_code}' "$EXPECTED_PROD_URL/" || true)"
  if [[ "$status" == "503" ]] || rg -qi 'briefly unavailable|maintenance|upgrading' /tmp/ssf-maintenance-open.html; then
    rm -f /tmp/ssf-maintenance-open.html
    fail "Production still appears to be in maintenance after deactivation."
  fi
  rm -f /tmp/ssf-maintenance-open.html
}

maintenance_grace_period() {
  echo "Waiting $MAINTENANCE_GRACE_SECONDS seconds for in-flight requests..."
  sleep "$MAINTENANCE_GRACE_SECONDS"
}

open_site_for_public_smoke() {
  deactivate_maintenance
}

public_smoke_failed() {
  if [[ "$PROD_MUTATED" == "1" ]]; then
    activate_maintenance "public smoke failure" >/dev/null 2>&1 || true
  fi
}

capture_pre_deploy_state() {
  PRE_DEPLOY_BUILD="$(wp_eval_prod 'if (class_exists("SSF_Release_Manager")) { $status = SSF_Release_Manager::status(); echo (string)($status["build"] ?? ""); }' 2>/dev/null || true)"
  PRE_DEPLOY_VERSION="$(wp_eval_prod 'if (class_exists("SSF_Release_Manager")) { $status = SSF_Release_Manager::status(); echo (string)($status["version"] ?? ""); }' 2>/dev/null || true)"
  wp_prod plugin list --format=json --fields=name,status,version > "$BACKUP_DIR/plugins-before.json"
  wp_prod theme list --format=json --fields=name,status,version > "$BACKUP_DIR/themes-before.json"
  {
    while IFS= read -r plugin; do printf 'wp-content/plugins/%s\n' "$plugin"; done < <(json_array "production.plugins")
    while IFS= read -r plugin; do printf 'wp-content/plugins/%s\n' "$plugin"; done < <(plugin_plan_array "touched_plugins")
    while IFS= read -r theme; do printf 'wp-content/themes/%s\n' "$theme"; done < <(json_array "production.themes")
    while IFS= read -r file; do printf 'wp-content/mu-plugins/%s\n' "$file"; done < <(json_array "production.mu_files")
  } | sort -u | while IFS= read -r path; do
    if [[ -e "$PROD/$path" ]]; then
      printf '%s\texists_before=true\n' "$path"
    else
      printf '%s\texists_before=false\n' "$path"
    fi
  done > "$BACKUP_DIR/runtime-paths-before.tsv"
}

require_tools() {
  section "TOOLS"
  local missing=0
  for tool in git php curl rsync tar gzip sha256sum awk pwsh rg; do
    if command -v "$tool" >/dev/null 2>&1; then
      printf "%-12s PASS\n" "$tool"
    else
      printf "%-12s FAIL\n" "$tool"
      missing=1
    fi
  done
  if [[ -f "$DEV_WP_CLI" ]]; then printf "%-12s PASS\n" "DEV WP-CLI"; else printf "%-12s FAIL\n" "DEV WP-CLI"; missing=1; fi
  if [[ -f "$PROD_WP_CLI" ]]; then printf "%-12s PASS\n" "PROD WP-CLI"; else printf "%-12s FAIL\n" "PROD WP-CLI"; missing=1; fi
  [[ "$missing" == "0" ]] || fail "Required server tools are missing."
}

update_repo() {
  section "GIT UPDATE"
  cd "$REPO"
  local branch previous origin_main new_head
  branch="$(git branch --show-current)"
  previous="$(git rev-parse HEAD)"
  [[ "$branch" == "main" ]] || fail "Repository branch must be main. Got $branch"
  [[ -z "$(git status --porcelain)" ]] || fail "Repository checkout is dirty. Stop before deploy."
  git fetch origin
  origin_main="$(git rev-parse origin/main)"
  git merge-base --is-ancestor HEAD origin/main || fail "HEAD cannot fast-forward to origin/main."
  git pull --ff-only origin main
  new_head="$(git rev-parse HEAD)"
  GIT_HEAD="$new_head"
  echo "Previous HEAD: $previous"
  echo "New HEAD:      $new_head"
  echo "origin/main:   $origin_main"
  echo "Branch:        $branch"
}

run_tests() {
  section "TEST SUITE"
  cd "$REPO"
  mapfile -t tests < <(find scripts/tests -maxdepth 1 -type f -name '*.ps1' | sort)
  [[ "${#tests[@]}" -gt 0 ]] || fail "No repository tests found."
  for test in "${tests[@]}"; do
    echo "RUN $test"
    pwsh -NoProfile -File "$test"
  done
  TEST_STATUS="PASS"
  echo "TEST SUITE: PASS"
}

collect_php_files() {
  local file
  while IFS= read -r plugin; do
    [[ -d "$REPO/wp-content/plugins/$plugin" ]] || fail "Missing source plugin: $plugin"
    find "$REPO/wp-content/plugins/$plugin" -type f -name '*.php'
  done < <(json_array "production.plugins")
  while IFS= read -r theme; do
    [[ -d "$REPO/wp-content/themes/$theme" ]] || fail "Missing source theme: $theme"
    find "$REPO/wp-content/themes/$theme" -type f -name '*.php'
  done < <(json_array "production.themes")
  while IFS= read -r file; do
    [[ -f "$REPO/wp-content/mu-plugins/$file" ]] || fail "Missing source MU file: $file"
    echo "$REPO/wp-content/mu-plugins/$file"
  done < <(json_array "production.mu_files")
  while IFS= read -r file; do
    [[ -f "$REPO/wp-content/mu-plugins/$file" ]] || fail "Missing source DEV-only MU file: $file"
    echo "$REPO/wp-content/mu-plugins/$file"
  done < <(json_array "dev_only.mu_files")
}

php_lint() {
  section "PHP LINT"
  local checked=0
  local failed=0
  while IFS= read -r file; do
    checked=$((checked + 1))
    if ! php -l "$file" >/tmp/ssf-php-lint.out 2>&1; then
      cat /tmp/ssf-php-lint.out >&2
      failed=1
    fi
  done < <(collect_php_files | sort -u)
  rm -f /tmp/ssf-php-lint.out
  [[ "$failed" == "0" ]] || fail "PHP lint failed."
  PHP_STATUS="PASS"
  echo "$checked files checked"
  echo "PASS"
}

rsync_component() {
  local source="$1"
  local destination="$2"
  local mode="$3"
  [[ -e "$source" ]] || fail "Missing source path: $source"
  mkdir -p "$(dirname "$destination")"
  if [[ "$mode" == "dry" ]]; then
    rsync -ani "$source" "$destination"
  else
    rsync -a "$source" "$destination"
  fi
}

sync_to_dev() {
  section "SOURCE TO DEV SYNC"
  local changes=0 output
  while IFS= read -r plugin; do
    output="$(rsync_component "$REPO/wp-content/plugins/$plugin/" "$DEV/wp-content/plugins/$plugin/" dry)"
    [[ -z "$output" ]] || changes=$((changes + $(printf '%s\n' "$output" | sed '/^$/d' | wc -l)))
  done < <(json_array "production.plugins")
  while IFS= read -r theme; do
    output="$(rsync_component "$REPO/wp-content/themes/$theme/" "$DEV/wp-content/themes/$theme/" dry)"
    [[ -z "$output" ]] || changes=$((changes + $(printf '%s\n' "$output" | sed '/^$/d' | wc -l)))
  done < <(json_array "production.themes")
  while IFS= read -r file; do
    output="$(rsync_component "$REPO/wp-content/mu-plugins/$file" "$DEV/wp-content/mu-plugins/$file" dry)"
    [[ -z "$output" ]] || changes=$((changes + $(printf '%s\n' "$output" | sed '/^$/d' | wc -l)))
  done < <(json_array "production.mu_files")
  while IFS= read -r file; do
    output="$(rsync_component "$REPO/wp-content/mu-plugins/$file" "$DEV/wp-content/mu-plugins/$file" dry)"
    [[ -z "$output" ]] || changes=$((changes + $(printf '%s\n' "$output" | sed '/^$/d' | wc -l)))
  done < <(json_array "dev_only.mu_files")
  echo "DEV dry-run changed/added lines: $changes"

  while IFS= read -r plugin; do rsync_component "$REPO/wp-content/plugins/$plugin/" "$DEV/wp-content/plugins/$plugin/" real >/dev/null; done < <(json_array "production.plugins")
  while IFS= read -r theme; do rsync_component "$REPO/wp-content/themes/$theme/" "$DEV/wp-content/themes/$theme/" real >/dev/null; done < <(json_array "production.themes")
  while IFS= read -r file; do rsync_component "$REPO/wp-content/mu-plugins/$file" "$DEV/wp-content/mu-plugins/$file" real >/dev/null; done < <(json_array "production.mu_files")
  while IFS= read -r file; do rsync_component "$REPO/wp-content/mu-plugins/$file" "$DEV/wp-content/mu-plugins/$file" real >/dev/null; done < <(json_array "dev_only.mu_files")
  DEV_SYNC_STATUS="PASS"
  echo "DEV sync: PASS"
}

verify_and_prepare_dev() {
  section "DEV RELEASE"
  local env manifest before_build before_version
  env="$(wp_eval_dev 'echo wp_get_environment_type();')"
  [[ "$env" == "development" ]] || fail "DEV environment must be development. Got $env"
  manifest="$DEV/wp-content/mu-plugins/ssf-release-manifest.json"
  [[ -f "$manifest" ]] || fail "DEV release manifest missing."
  VERSION="$(manifest_field "$manifest" version)"
  BUILD="$(manifest_field "$manifest" build)"
  MANIFEST_STATUS="$(manifest_field "$manifest" status)"
  local source_revision
  source_revision="$(manifest_field "$manifest" source_revision)"
  echo "Version:         $VERSION"
  echo "Build:           $BUILD"
  echo "Status:          $MANIFEST_STATUS"
  echo "Source revision: $source_revision"
  before_build="$BUILD"
  before_version="$VERSION"
  if [[ "$MANIFEST_STATUS" == "development" ]]; then
    wp_dev ssf release prepare --version="$VERSION"
  elif [[ "$MANIFEST_STATUS" == "prepared" ]]; then
    :
  else
    fail "Unexpected DEV manifest status: $MANIFEST_STATUS"
  fi
  VERSION="$(manifest_field "$manifest" version)"
  BUILD="$(manifest_field "$manifest" build)"
  MANIFEST_STATUS="$(manifest_field "$manifest" status)"
  [[ "$BUILD" == "$before_build" ]] || fail "Build changed during prepare."
  [[ "$VERSION" == "$before_version" ]] || fail "Version changed during prepare."
  [[ "$MANIFEST_STATUS" == "prepared" ]] || fail "DEV manifest was not prepared."
  local status
  status="$(wp_dev ssf release status --format=json 2>/dev/null || wp_dev ssf release status)"
  echo "$status" | rg -q "$BUILD" || fail "DEV release status does not mention expected build."
  echo "$status" | rg -qi "development" || fail "DEV release status does not resolve to development."
}

curl_effective() {
  local url="$1"
  local tmp
  tmp="$(mktemp)"
  local status
  status="$(curl -sS -L -o "$tmp" -w '%{http_code} %{url_effective}' "$url")"
  if rg -qi 'Fatal error|Parse error|critical error' "$tmp"; then
    rm -f "$tmp"
    fail "Fatal runtime output from $url"
  fi
  rm -f "$tmp"
  echo "$status"
}

dev_smoke() {
  section "DEV SMOKE"
  wp_dev core is-installed >/dev/null
  curl_effective "https://ssfb.se/dev/" >/dev/null
  curl_effective "https://ssfb.se/dev/ansokan-status/" >/dev/null
  curl_effective "https://ssfb.se/dev/motion-status/" >/dev/null
  DEV_SMOKE_STATUS="PASS"
  echo "DEV smoke: PASS"
}

prod_target_safety() {
  section "PROD TARGET SAFETY"
  local dev_real prod_real expected_prod
  dev_real="$(realpath "$DEV")"
  prod_real="$(realpath "$PROD")"
  expected_prod="$HOME_DIR/ssfb.se/public_html"
  [[ "$dev_real" != "$prod_real" ]] || fail "DEV and PROD paths are identical."
  [[ "$prod_real" == "$(realpath "$expected_prod")" ]] || fail "PROD path is not expected production root."
  [[ "$prod_real" != *"/dev"* ]] || fail "PROD path contains /dev."
  [[ -d "$PROD/wp-content" ]] || fail "PROD wp-content missing."
  [[ -f "$PROD/wp-config.php" ]] || fail "PROD wp-config.php missing."
  wp_prod core is-installed >/dev/null
  wp_prod db check >/dev/null
  PROD_TARGET_STATUS="PASS"
  PROD_DB_STATUS="PASS"
  echo "PROD target: PASS"
  echo "PROD db check: PASS"
}

plugin_list_json() {
  local environment="$1"
  if [[ "$environment" == "dev" ]]; then
    wp_dev plugin list --format=json --fields=name,status,version,update,update_version
  else
    wp_prod plugin list --format=json --fields=name,status,version,update,update_version
  fi
}

build_plugin_parity_plan() {
  section "PLUGIN PARITY"
  local dev_plugins prod_plugins
  dev_plugins="$(mktemp)"
  prod_plugins="$(mktemp)"
  PLUGIN_PLAN="$(mktemp)"
  plugin_list_json dev > "$dev_plugins"
  plugin_list_json prod > "$prod_plugins"

  php -r '
    $config = json_decode(file_get_contents($argv[1]), true);
    $dev = json_decode(file_get_contents($argv[2]), true);
    $prod = json_decode(file_get_contents($argv[3]), true);
    $policy = $config["plugin_policy"] ?? array();
    $devOnly = array_flip($policy["dev_only"] ?? array());
    $ignoreVersion = array_flip($policy["ignore_version"] ?? array());
    $excluded = array_flip($config["excluded"]["plugins"] ?? array());
    $configured = array_flip($config["production"]["plugins"] ?? array());
    $prodByName = array();
    foreach ($prod as $plugin) { $prodByName[$plugin["name"]] = $plugin; }
    $devByName = array();
    foreach ($dev as $plugin) { $devByName[$plugin["name"]] = $plugin; }

    $entries = array();
    $deploy = array();
    $activate = array();
    $touch = array();
    $missingBefore = array();
    $unresolved = array();
    $counts = array("matches" => 0, "actions" => 0, "warnings" => 0, "unresolved" => 0);

    foreach ($dev as $plugin) {
      $name = $plugin["name"];
      $devStatus = $plugin["status"];
      $devVersion = (string)($plugin["version"] ?? "");
      $prodPlugin = $prodByName[$name] ?? null;
      $prodStatus = $prodPlugin["status"] ?? "missing";
      $prodVersion = $prodPlugin ? (string)($prodPlugin["version"] ?? "") : "";
      $classification = "MATCH";
      $action = "none";
      $severity = "PASS";

      if (isset($devOnly[$name])) {
        $classification = "DEV_ONLY_ALLOWED";
        $severity = "PASS";
      } elseif ($devStatus === "active") {
        if (!$prodPlugin) {
          $classification = "DEV_ACTIVE_PROD_MISSING";
          $action = "copy_activate";
          $severity = "ACTION";
          $deploy[] = $name;
          $activate[] = $name;
          $missingBefore[] = $name;
        } elseif ($prodStatus !== "active") {
          $classification = "DEV_ACTIVE_PROD_INACTIVE";
          $action = "activate";
          $severity = "ACTION";
          $activate[] = $name;
          $touch[] = $name;
        } elseif ($devVersion !== $prodVersion && !isset($ignoreVersion[$name])) {
          $classification = "DEV_ACTIVE_VERSION_DIFFERS";
          $action = "copy_update";
          $severity = "ACTION";
          $deploy[] = $name;
          $touch[] = $name;
        }
      } elseif ($prodPlugin && $prodStatus === "active") {
        $classification = "DEV_INACTIVE_PROD_ACTIVE";
        $severity = "WARNING";
      } elseif ($prodPlugin && $devVersion !== $prodVersion && !isset($ignoreVersion[$name])) {
        $classification = "VERSION_DIFFERS_INACTIVE";
        $severity = "WARNING";
      }

      if ($classification === "MATCH") { $counts["matches"]++; }
      if ($severity === "ACTION") { $counts["actions"]++; }
      if ($severity === "WARNING") { $counts["warnings"]++; }
      if ($severity === "ERROR") { $counts["unresolved"]++; $unresolved[] = $name; }
      $entries[] = compact("name", "devStatus", "devVersion", "prodStatus", "prodVersion", "classification", "action", "severity");
    }

    foreach ($prod as $plugin) {
      $name = $plugin["name"];
      if (!isset($devByName[$name])) {
        $entries[] = array(
          "name" => $name,
          "devStatus" => "missing",
          "devVersion" => "",
          "prodStatus" => $plugin["status"],
          "prodVersion" => (string)($plugin["version"] ?? ""),
          "classification" => "PROD_ONLY",
          "action" => "none",
          "severity" => "WARNING"
        );
        $counts["warnings"]++;
      }
    }

    $deploy = array_values(array_unique($deploy));
    $activate = array_values(array_unique($activate));
    $touch = array_values(array_unique(array_merge($touch, $deploy)));
    file_put_contents($argv[4], json_encode(array(
      "entries" => $entries,
      "deploy_plugins" => $deploy,
      "activate_plugins" => $activate,
      "touched_plugins" => $touch,
      "missing_before" => array_values(array_unique($missingBefore)),
      "unresolved" => $unresolved,
      "counts" => $counts
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    foreach ($entries as $entry) {
      printf("%s\n  DEV:  %s %s\n  PROD: %s %s\n  %s %s\n",
        $entry["name"],
        $entry["devStatus"],
        $entry["devVersion"],
        $entry["prodStatus"],
        $entry["prodVersion"],
        $entry["severity"],
        $entry["classification"]
      );
    }
  ' "$CONFIG" "$dev_plugins" "$prod_plugins" "$PLUGIN_PLAN"

  rm -f "$dev_plugins" "$prod_plugins"

  while IFS= read -r plugin; do
    [[ -z "$plugin" ]] && continue
    [[ -d "$DEV/wp-content/plugins/$plugin" ]] || fail "Active DEV plugin cannot be deployed safely because files are missing in DEV: $plugin"
    json_contains "excluded.plugins" "$plugin" && fail "Active DEV plugin is excluded from production deployment: $plugin"
  done < <(plugin_plan_array "deploy_plugins")

  if [[ "$(plugin_plan_count "unresolved")" != "0" ]]; then
    fail "Unresolved plugin parity differences found."
  fi
  PLUGIN_PARITY_STATUS="PASS"
}

plugin_plan_array() {
  local path="$1"
  [[ -n "$PLUGIN_PLAN" && -f "$PLUGIN_PLAN" ]] || return 0
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    foreach (($data[$argv[2]] ?? array()) as $value) { echo $value, PHP_EOL; }
  ' "$PLUGIN_PLAN" "$path"
}

plugin_plan_count() {
  local path="$1"
  [[ -n "$PLUGIN_PLAN" && -f "$PLUGIN_PLAN" ]] || { echo 0; return; }
  php -r '$data=json_decode(file_get_contents($argv[1]), true); echo count($data[$argv[2]] ?? array());' "$PLUGIN_PLAN" "$path"
}

plugin_plan_summary() {
  [[ -n "$PLUGIN_PLAN" && -f "$PLUGIN_PLAN" ]] || return 0
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    $counts = $data["counts"] ?? array();
    printf("PLUGIN PARITY\n----------------------------------------\n");
    printf("%d active/inactive plugin matches       PASS\n", (int)($counts["matches"] ?? 0));
    printf("%d plugin actions planned              %s\n", count($data["deploy_plugins"] ?? array()) + count($data["activate_plugins"] ?? array()), (count($data["deploy_plugins"] ?? array()) + count($data["activate_plugins"] ?? array())) ? "ACTION" : "PASS");
    printf("%d plugin warnings                      %s\n", (int)($counts["warnings"] ?? 0), ((int)($counts["warnings"] ?? 0)) ? "WARNING" : "PASS");
    printf("%d unresolved plugin differences        %s\n\n", count($data["unresolved"] ?? array()), count($data["unresolved"] ?? array()) ? "FAIL" : "PASS");
    foreach ($data["entries"] as $entry) {
      if ($entry["action"] !== "none") {
        printf("%s\n  DEV:  %s %s\n  PROD: %s %s\n  ACTION: %s\n\n", $entry["name"], $entry["devStatus"], $entry["devVersion"], $entry["prodStatus"], $entry["prodVersion"], $entry["action"]);
      }
    }
    $prodOnly = array_values(array_filter($data["entries"], function ($entry) {
      return $entry["classification"] === "PROD_ONLY" || $entry["classification"] === "DEV_INACTIVE_PROD_ACTIVE";
    }));
    if ($prodOnly) {
      echo "Production-only or production-active differences:\n";
      foreach ($prodOnly as $entry) { echo "- {$entry["name"]}: {$entry["classification"]}. No automatic deactivation.\n"; }
      echo "\n";
    }
  ' "$PLUGIN_PLAN"
}

validate_turnstile_prod_config() {
  section "TURNSTILE CONFIGURATION"
  if ! wp_prod plugin is-active simple-cloudflare-turnstile >/dev/null 2>&1; then
    echo "simple-cloudflare-turnstile inactive in PROD; Turnstile configuration check skipped."
    return
  fi
  local phase="${1:-check}"
  local status_file
  status_file="$(mktemp)"
  wp_eval_prod '
    $site = trim((string) get_option("cfturnstile_key", ""));
    $secret = trim((string) get_option("cfturnstile_secret", ""));
    $test = class_exists("SSF_Antispam") ? SSF_Antispam::is_test_mode() : true;
    $configured = class_exists("SSF_Antispam") ? SSF_Antispam::is_configured() : false;
    echo wp_json_encode(array(
      "environment" => wp_get_environment_type(),
      "site_found" => $site !== "",
      "secret_found" => $secret !== "",
      "test_mode" => (bool) $test,
      "configured" => (bool) $configured,
      "site_fingerprint" => $site !== "" ? hash("sha256", $site) : "",
      "secret_fingerprint" => $secret !== "" ? hash("sha256", $secret) : ""
    ));
  ' > "$status_file"
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($data)) { exit(10); }
    echo "Environment: " . ($data["environment"] ?? "") . PHP_EOL;
    echo "Site key: " . (!empty($data["site_found"]) ? "FOUND" : "MISSING") . PHP_EOL;
    echo "Secret key: " . (!empty($data["secret_found"]) ? "FOUND" : "MISSING") . PHP_EOL;
    echo "Test mode: " . (!empty($data["test_mode"]) ? "YES" : "NO") . PHP_EOL;
    echo "Configured: " . (!empty($data["configured"]) ? "YES" : "NO") . PHP_EOL;
    if (($data["environment"] ?? "") !== "production") { exit(1); }
    if (empty($data["site_found"])) { exit(2); }
    if (empty($data["secret_found"])) { exit(3); }
    if (!empty($data["test_mode"])) { exit(4); }
    if (empty($data["configured"])) { exit(5); }
  ' "$status_file" || { rm -f "$status_file"; fail "Turnstile PROD runtime validation failed."; }
  local site_fingerprint secret_fingerprint
  site_fingerprint="$(php -r '$d=json_decode(file_get_contents($argv[1]), true); echo (string)($d["site_fingerprint"] ?? "");' "$status_file")"
  secret_fingerprint="$(php -r '$d=json_decode(file_get_contents($argv[1]), true); echo (string)($d["secret_fingerprint"] ?? "");' "$status_file")"
  [[ -n "$site_fingerprint" && -n "$secret_fingerprint" ]] || { rm -f "$status_file"; fail "Turnstile PROD fingerprints missing."; }
  if [[ "$phase" == "preflight" ]]; then
    TURNSTILE_SITE_FINGERPRINT="$site_fingerprint"
    TURNSTILE_SECRET_FINGERPRINT="$secret_fingerprint"
  elif [[ "$phase" == "post" ]]; then
    [[ "$site_fingerprint" == "$TURNSTILE_SITE_FINGERPRINT" && "$secret_fingerprint" == "$TURNSTILE_SECRET_FINGERPRINT" ]] || { rm -f "$status_file"; fail "Turnstile PROD configuration fingerprint changed during deployment."; }
    echo "Turnstile PROD configuration unchanged: PASS"
  fi
  rm -f "$status_file"
}

prod_dry_run() {
  section "PROD DRY RUN"
  DRY_RUN_STATUS="PASS"
  while IFS= read -r plugin; do
    local output count
    output="$(rsync_component "$DEV/wp-content/plugins/$plugin/" "$PROD/wp-content/plugins/$plugin/" dry)"
    count="$(printf '%s\n' "$output" | sed '/^$/d' | wc -l)"
    echo "PLUGIN $plugin $count files changed / added"
  done < <(json_array "production.plugins")
  while IFS= read -r plugin; do
    local output count
    output="$(rsync_component "$DEV/wp-content/plugins/$plugin/" "$PROD/wp-content/plugins/$plugin/" dry)"
    count="$(printf '%s\n' "$output" | sed '/^$/d' | wc -l)"
    echo "PLUGIN $plugin $count files changed / added (parity plan)"
  done < <(plugin_plan_array "deploy_plugins")
  while IFS= read -r theme; do
    local output count
    output="$(rsync_component "$DEV/wp-content/themes/$theme/" "$PROD/wp-content/themes/$theme/" dry)"
    count="$(printf '%s\n' "$output" | sed '/^$/d' | wc -l)"
    echo "THEME $theme $count files changed / added"
  done < <(json_array "production.themes")
  while IFS= read -r file; do
    if [[ ! -f "$PROD/wp-content/mu-plugins/$file" ]]; then
      echo "MU $file NEW"
    elif cmp -s "$DEV/wp-content/mu-plugins/$file" "$PROD/wp-content/mu-plugins/$file"; then
      echo "MU $file IDENTICAL"
    else
      echo "MU $file UPDATED"
    fi
  done < <(json_array "production.mu_files")
  echo "NOT DEPLOYED:"
  echo "ssf-promotions"
  echo "DEV-only MU files"
  echo "wp-config.php"
  echo "database"
  echo "uploads"
  echo "WordPress core"
  echo "third-party plugins"
}

record_error_log_baseline() {
  section "ERROR LOG BASELINE"
  if [[ -f "$ERROR_LOG" ]]; then
    ERROR_LOG_LINES="$(wc -l < "$ERROR_LOG")"
    stat "$ERROR_LOG" || true
    echo "line_count=$ERROR_LOG_LINES"
  else
    ERROR_LOG_LINES=0
    echo "error_log missing before deployment"
  fi
}

confirm_once() {
  section "SSF PRODUCTION DEPLOYMENT"
  plugin_plan_summary
  cat <<SUMMARY
Git HEAD:          $GIT_HEAD
Build:             $BUILD
Version:           $VERSION
DEV status:        $MANIFEST_STATUS

Repository tests:  $TEST_STATUS
PHP lint:          $PHP_STATUS
DEV sync:          $DEV_SYNC_STATUS
DEV smoke:         $DEV_SMOKE_STATUS
Plugin parity:     $PLUGIN_PARITY_STATUS
PROD DB check:     $PROD_DB_STATUS
PROD target:       $PROD_TARGET_STATUS
Dry run:           $DRY_RUN_STATUS

Will deploy:
7 SSF plugins
1 SSF theme
7 PROD MU files

Will NOT touch:
database contents
wp-config.php
uploads
WordPress core
unplanned plugins
ssf-promotions
DEV-only MU plugins
WordPress options

Before deployment the script WILL create:
- PROD SQL database backup
- PROD affected-file backup

Type exactly DEPLOY to continue:
SUMMARY
  local confirmation
  read -r confirmation
  [[ "$confirmation" == "DEPLOY" ]] || fail "Operator did not type DEPLOY. Aborting before PROD changes."
}

create_backup_dir() {
  local timestamp
  timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
  BACKUP_DIR="$BACKUP_ROOT/prod-before-$BUILD-$timestamp"
  [[ ! -e "$BACKUP_DIR" ]] || fail "Backup directory already exists: $BACKUP_DIR"
  mkdir -p "$BACKUP_DIR"
}

database_backup() {
  section "DATABASE BACKUP"
  wp_prod db export "$BACKUP_DIR/database.sql" --add-drop-table >/dev/null
  [[ -s "$BACKUP_DIR/database.sql" ]] || fail "Database SQL dump missing or empty."
  rg -q 'CREATE TABLE|INSERT INTO|DROP TABLE' "$BACKUP_DIR/database.sql" || fail "Database SQL dump failed sanity validation."
  gzip "$BACKUP_DIR/database.sql"
  [[ -s "$BACKUP_DIR/database.sql.gz" ]] || fail "Database backup missing or empty."
  gzip -t "$BACKUP_DIR/database.sql.gz"
  sha256_file "$BACKUP_DIR/database.sql.gz" > "$BACKUP_DIR/database.sql.gz.sha256"
  DB_BACKUP_STATUS="PASS"
  echo "Database backup: $BACKUP_DIR/database.sql.gz"
  du -h "$BACKUP_DIR/database.sql.gz"
}

file_backup() {
  section "FILE BACKUP"
  local paths=()
  paths+=("wp-content/mu-plugins")
  while IFS= read -r plugin; do paths+=("wp-content/plugins/$plugin"); done < <(json_array "production.plugins")
  while IFS= read -r plugin; do
    if [[ -d "$PROD/wp-content/plugins/$plugin" ]]; then
      paths+=("wp-content/plugins/$plugin")
    fi
  done < <(plugin_plan_array "touched_plugins")
  while IFS= read -r theme; do paths+=("wp-content/themes/$theme"); done < <(json_array "production.themes")
  mapfile -t paths < <(printf '%s\n' "${paths[@]}" | sort -u)
  local archive archive_list
  archive="$BACKUP_DIR/prod-wp-content-targets.tar.gz"
  archive_list="$BACKUP_DIR/.prod-wp-content-targets.list"
  tar -czf "$archive" -C "$PROD" "${paths[@]}"
  [[ -s "$archive" ]] || fail "File backup missing or empty."
  tar -tzf "$archive" >/dev/null
  tar -tzf "$archive" > "$archive_list"
  for path in "${paths[@]}"; do
    awk -v expected="$path" '$0 == expected || index($0, expected "/") == 1 { found = 1; exit } END { exit found ? 0 : 1 }' "$archive_list" || fail "File backup missing expected path: $path"
  done
  sha256_file "$archive" > "$archive.sha256"
  FILE_BACKUP_STATUS="PASS"
  echo "File backup: $archive"
}

backup_manifest() {
  cat > "$BACKUP_DIR/BACKUP-INFO.txt" <<INFO
timestamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)
build=$BUILD
version=$VERSION
git_head=$GIT_HEAD
dev_path=$DEV
prod_path=$PROD
database_archive=database.sql.gz
file_archive=prod-wp-content-targets.tar.gz
uploads_not_touched=yes
wp_config_not_touched=yes
wordpress_core_not_touched=yes
sharepoint_schema_not_touched=yes
wordpress_options_not_copied_from_dev=yes
maintenance_mode=active_before_backup
plugins_missing_before_deployment=$(plugin_plan_array "missing_before" | paste -sd "," -)
INFO
  local db_archive file_archive db_sha file_sha db_size file_size_bytes prod_env prod_home prod_siteurl db_prefix
  db_archive="$BACKUP_DIR/database.sql.gz"
  file_archive="$BACKUP_DIR/prod-wp-content-targets.tar.gz"
  db_sha="$(sha256_file "$db_archive")"
  file_sha="$(sha256_file "$file_archive")"
  db_size="$(file_size "$db_archive")"
  file_size_bytes="$(file_size "$file_archive")"
  prod_env="$(wp_eval_prod 'echo wp_get_environment_type();')"
  prod_home="$(wp_prod option get home)"
  prod_siteurl="$(wp_prod option get siteurl)"
  db_prefix="$(wp_eval_prod 'global $wpdb; echo $wpdb->prefix;')"
  php -r '
    $backupDir = $argv[1];
    $data = array(
      "schema_version" => 1,
      "backup_timestamp" => gmdate("c"),
      "git" => array(
        "deployment_source_revision" => $argv[2],
        "server_repo_head" => $argv[2]
      ),
      "release" => array(
        "target_build" => $argv[3],
        "target_version" => $argv[4],
        "pre_deploy_build" => $argv[5],
        "pre_deploy_version" => $argv[6]
      ),
      "production" => array(
        "path" => $argv[7],
        "expected_home_url" => $argv[8],
        "home_url" => $argv[9],
        "siteurl" => $argv[10],
        "environment" => $argv[11],
        "db_prefix" => $argv[12]
      ),
      "database_backup" => array(
        "filename" => "database.sql.gz",
        "size" => (int)$argv[13],
        "sha256" => $argv[14],
        "verified" => true
      ),
      "file_backup" => array(
        "filename" => "prod-wp-content-targets.tar.gz",
        "size" => (int)$argv[15],
        "sha256" => $argv[16],
        "verified" => true
      ),
      "plugins_before_deployment" => json_decode(file_get_contents($backupDir . "/plugins-before.json"), true),
      "theme_before_deployment" => json_decode(file_get_contents($backupDir . "/themes-before.json"), true),
      "runtime_paths_before_deployment" => file_exists($backupDir . "/runtime-paths-before.tsv") ? file($backupDir . "/runtime-paths-before.tsv", FILE_IGNORE_NEW_LINES) : array(),
      "deployment_state" => array(
        "backup_complete" => true,
        "deployment_started" => false,
        "files_deployed" => false,
        "plugins_activated" => false,
        "deployment_success" => false,
        "verification_failed" => false
      ),
      "external_systems" => array(
        "sharepoint_rolled_back" => false
      )
    );
    file_put_contents($backupDir . "/BACKUP-INFO.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
  ' "$BACKUP_DIR" "$GIT_HEAD" "$BUILD" "$VERSION" "$PRE_DEPLOY_BUILD" "$PRE_DEPLOY_VERSION" "$PROD" "$EXPECTED_PROD_URL" "$prod_home" "$prod_siteurl" "$prod_env" "$db_prefix" "$db_size" "$db_sha" "$file_size_bytes" "$file_sha"
  json_array "production.plugins" > "$BACKUP_DIR/components-plugins.txt"
  json_array "production.themes" > "$BACKUP_DIR/components-themes.txt"
  json_array "production.mu_files" > "$BACKUP_DIR/components-mu-files.txt"
  plugin_plan_array "touched_plugins" > "$BACKUP_DIR/components-plugin-parity-touched.txt"
}

update_backup_state() {
  local key="$1"
  local value="$2"
  [[ -f "$BACKUP_DIR/BACKUP-INFO.json" ]] || return 0
  php -r '
    $file = $argv[1];
    $key = $argv[2];
    $value = $argv[3] === "true";
    $data = json_decode(file_get_contents($file), true);
    $data["deployment_state"][$key] = $value;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
  ' "$BACKUP_DIR/BACKUP-INFO.json" "$key" "$value"
}

deploy_files_to_prod() {
  section "PROD FILE DEPLOY"
  DEPLOYMENT_STARTED=1
  PROD_MUTATED=1
  update_backup_state "deployment_started" "true"
  while IFS= read -r plugin; do rsync_component "$DEV/wp-content/plugins/$plugin/" "$PROD/wp-content/plugins/$plugin/" real >/dev/null; done < <(json_array "production.plugins")
  while IFS= read -r plugin; do rsync_component "$DEV/wp-content/plugins/$plugin/" "$PROD/wp-content/plugins/$plugin/" real >/dev/null; done < <(plugin_plan_array "deploy_plugins")
  while IFS= read -r theme; do rsync_component "$DEV/wp-content/themes/$theme/" "$PROD/wp-content/themes/$theme/" real >/dev/null; done < <(json_array "production.themes")
  while IFS= read -r file; do rsync_component "$DEV/wp-content/mu-plugins/$file" "$PROD/wp-content/mu-plugins/$file" real >/dev/null; done < <(json_array "production.mu_files")
  FILES_DEPLOYED=1
  update_backup_state "files_deployed" "true"
  FILE_DEPLOY_STATUS="PASS"
  echo "File deployment: PASS"
}

activate_planned_plugins() {
  section "PLUGIN ACTIVATION"
  while IFS= read -r plugin; do
    [[ -z "$plugin" ]] && continue
    wp_prod plugin activate "$plugin"
    echo "Activated: $plugin"
  done < <(plugin_plan_array "activate_plugins")
  PLUGINS_ACTIVATED=1
  update_backup_state "plugins_activated" "true"
}

release_registration() {
  section "RELEASE VERIFY"
  local env status
  env="$(wp_eval_prod 'echo wp_get_environment_type();')"
  [[ "$env" == "production" ]] || fail "PROD environment must be production. Got $env"
  wp_prod ssf release deploy --expected-build="$BUILD"
  wp_prod ssf release verify --expected-build="$BUILD"
  status="$(wp_prod ssf release status --format=json 2>/dev/null || wp_prod ssf release status)"
  echo "$status" | rg -q "$BUILD" || fail "PROD release status does not mention expected build."
  echo "$status" | rg -qi "production" || fail "PROD release status does not resolve to production."
  echo "$status" | rg -qi "success" || fail "PROD release deployment is not success."
  update_backup_state "deployment_started" "true"
  RELEASE_VERIFY_STATUS="PASS"
}

verify_prod_components() {
  section "PROD COMPONENTS"
  while IFS= read -r plugin; do
    [[ -d "$PROD/wp-content/plugins/$plugin" ]] || fail "Missing PROD plugin: $plugin"
    wp_prod plugin is-active "$plugin" >/dev/null || fail "PROD plugin is not active: $plugin"
    local dev_version prod_version
    dev_version="$(wp_dev plugin get "$plugin" --field=version 2>/dev/null || true)"
    prod_version="$(wp_prod plugin get "$plugin" --field=version 2>/dev/null || true)"
    [[ "$dev_version" == "$prod_version" ]] || fail "Plugin version mismatch for $plugin: DEV=$dev_version PROD=$prod_version"
  done < <(json_array "production.plugins")
  PLUGIN_VERIFY_STATUS="PASS"
  while IFS= read -r theme; do
    [[ -d "$PROD/wp-content/themes/$theme" ]] || fail "Missing PROD theme: $theme"
    wp_prod theme is-active "$theme" >/dev/null || fail "PROD theme is not active: $theme"
    local dev_theme_version prod_theme_version
    dev_theme_version="$(wp_dev theme get "$theme" --field=version 2>/dev/null || true)"
    prod_theme_version="$(wp_prod theme get "$theme" --field=version 2>/dev/null || true)"
    [[ "$dev_theme_version" == "$prod_theme_version" ]] || fail "Theme version mismatch for $theme: DEV=$dev_theme_version PROD=$prod_theme_version"
  done < <(json_array "production.themes")
  THEME_VERIFY_STATUS="PASS"
  while IFS= read -r file; do [[ -f "$PROD/wp-content/mu-plugins/$file" ]] || fail "Missing PROD MU file: $file"; done < <(json_array "production.mu_files")
  while IFS= read -r file; do [[ ! -e "$PROD/wp-content/mu-plugins/$file" ]] || fail "DEV-only MU file exists in PROD: $file"; done < <(json_array "dev_only.mu_files")
  [[ ! -e "$PROD/wp-content/plugins/ssf-promotions" ]] || fail "Excluded plugin exists in PROD: ssf-promotions"
  post_deploy_plugin_parity
  validate_turnstile_prod_config "post"
  echo "Plugin verification PASS"
  echo "Theme verification: PASS"
}

post_deploy_plugin_parity() {
  section "POST-DEPLOY PLUGIN PARITY"
  build_plugin_parity_plan
  if [[ "$(plugin_plan_count "deploy_plugins")" != "0" || "$(plugin_plan_count "activate_plugins")" != "0" ]]; then
    fail "Post-deploy plugin parity still has pending actions."
  fi
  echo "Post-deploy plugin parity: PASS"
}

http_prod_smoke() {
  section "HTTP PROD SMOKE"
  local path expected status effective headers
  for item in \
    "/ 200" \
    "/ansokan/ 200" \
    "/ansokan-status/ 200" \
    "/lamna-motion/ 200" \
    "/motion-status/ 200" \
    "/arsmoten/ 200" \
    "/medlemskap/ 200"; do
    path="${item% *}"
    expected="${item#* }"
    status="$(curl -sS -L -o /tmp/ssf-prod-smoke.html -w '%{http_code}' "https://ssfb.se$path")"
    if [[ "$status" != "$expected" ]]; then
      public_smoke_failed
      fail "HTTP smoke failed for $path. Expected $expected got $status"
    fi
    if rg -qi 'Fatal error|Parse error|critical error' /tmp/ssf-prod-smoke.html; then
      public_smoke_failed
      fail "Fatal output in $path"
    fi
  done
  effective="$(curl -sS -I -L -o /tmp/ssf-prod-admin.headers -w '%{url_effective}' "https://ssfb.se/wp-admin/")"
  if ! echo "$effective" | rg -q 'wp-login\.php'; then
    public_smoke_failed
    fail "Anonymous wp-admin did not redirect to login."
  fi
  for path in "/ansokan-status/" "/motion-status/"; do
    headers="$(curl -sS -I -L "https://ssfb.se$path")"
    if ! echo "$headers" | rg -qi 'X-Robots-Tag:.*noindex'; then
      public_smoke_failed
      fail "Status page missing X-Robots-Tag noindex: $path"
    fi
    if ! echo "$headers" | rg -qi 'Cache-Control:.*(no-store|no-cache)'; then
      public_smoke_failed
      fail "Status page missing no-cache/no-store: $path"
    fi
  done
  rm -f /tmp/ssf-prod-smoke.html /tmp/ssf-prod-admin.headers
  HTTP_SMOKE_STATUS="PASS"
  echo "HTTP smoke: PASS"
}

error_log_post_check() {
  section "ERROR LOG POST-CHECK"
  if [[ ! -f "$ERROR_LOG" ]]; then
    ERROR_LOG_STATUS="PASS"
    echo "ERROR LOG: PASS"
    return
  fi
  local new_lines
  new_lines="$(tail -n +"$((ERROR_LOG_LINES + 1))" "$ERROR_LOG" || true)"
  if printf '%s\n' "$new_lines" | rg -i 'PHP Fatal error|PHP Parse error|Uncaught Error|Allowed memory size exhausted' | rg -v 'gitlab\.ssfb\.se'; then
    fail "New relevant production PHP errors found."
  fi
  ERROR_LOG_STATUS="PASS"
  echo "ERROR LOG: PASS"
}

success_report() {
  section "SSF DEPLOYMENT SUCCESS"
  local maintenance_duration
  maintenance_duration=$((MAINTENANCE_ENDED_AT - MAINTENANCE_STARTED_AT))
  update_backup_state "deployment_success" "true"
  cat <<REPORT
Version:             $VERSION
Build:               $BUILD
Git HEAD:            $GIT_HEAD

Tests:               $TEST_STATUS
PHP lint:            $PHP_STATUS
DEV:                 $DEV_SMOKE_STATUS
Production maintenance: $MAINTENANCE_STATUS
Maintenance duration:   ${maintenance_duration}s
Database backup:     $DB_BACKUP_STATUS
File backup:         $FILE_BACKUP_STATUS
File deployment:     $FILE_DEPLOY_STATUS
Release verify:      $RELEASE_VERIFY_STATUS
Plugin verification: $PLUGIN_VERIFY_STATUS
Theme verification:  $THEME_VERIFY_STATUS
HTTP smoke:          $HTTP_SMOKE_STATUS
Error log:           $ERROR_LOG_STATUS

Database backup:
$BACKUP_DIR/database.sql.gz

File backup:
$BACKUP_DIR/prod-wp-content-targets.tar.gz

PROD deployment:
SUCCESS

Public site:         OPEN
REPORT
}

main() {
  require_tools
  update_repo
  run_tests
  php_lint
  sync_to_dev
  verify_and_prepare_dev
  dev_smoke
  prod_target_safety
  build_plugin_parity_plan
  prod_dry_run
  validate_turnstile_prod_config "preflight"
  record_error_log_baseline
  confirm_once
  create_backup_dir
  activate_maintenance "deployment"
  maintenance_grace_period
  capture_pre_deploy_state
  database_backup
  file_backup
  backup_manifest
  deploy_files_to_prod
  activate_planned_plugins
  release_registration
  verify_prod_components
  open_site_for_public_smoke
  http_prod_smoke
  error_log_post_check
  DEPLOY_SUCCESS=1
  success_report
}

main "$@"
