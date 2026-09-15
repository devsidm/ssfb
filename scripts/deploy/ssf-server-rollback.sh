#!/usr/bin/env bash
set -Eeuo pipefail

HOME_DIR="${SSF_HOME:-$HOME}"
REPO="$HOME_DIR/repos/ssfb"
PROD="$HOME_DIR/ssfb.se/public_html"
PROD_WP_CLI="$PROD/wp-cli.phar"
BACKUP_ROOT="$HOME_DIR/ssf-backups"
ERROR_LOG="$HOME_DIR/ssfb.se/logs/error_log"
EXPECTED_PROD_URL="https://ssfb.se"
MAINTENANCE_GRACE_SECONDS="${SSF_MAINTENANCE_GRACE_SECONDS:-10}"

MODE="FILES"
SELECTED_BACKUP=""
WITH_DB=0
LIST_ONLY=0
MAINTENANCE_ACTIVE=0
ROLLBACK_MUTATED=0
ROLLBACK_SUCCESS=0
RESCUE_BACKUP=""
ERROR_LOG_LINES=0

fail() {
  echo "FAILURE: $*" >&2
  if [[ "$ROLLBACK_MUTATED" == "1" ]]; then
    activate_maintenance "rollback failure after mutation" >/dev/null 2>&1 || true
    echo "Public site: MAINTENANCE MODE" >&2
    [[ -n "$RESCUE_BACKUP" ]] && echo "Rescue backup: $RESCUE_BACKUP" >&2
  elif [[ "$MAINTENANCE_ACTIVE" == "1" ]]; then
    deactivate_maintenance >/dev/null 2>&1 || true
  fi
  exit 1
}

section() {
  echo
  echo "============================================================"
  echo "$1"
  echo "============================================================"
}

cleanup() {
  if [[ "$ROLLBACK_SUCCESS" == "1" && "$MAINTENANCE_ACTIVE" == "1" ]]; then
    deactivate_maintenance >/dev/null 2>&1 || true
  elif [[ "$ROLLBACK_MUTATED" == "0" && "$MAINTENANCE_ACTIVE" == "1" ]]; then
    deactivate_maintenance >/dev/null 2>&1 || true
  fi
  if [[ "$MAINTENANCE_ACTIVE" == "1" ]]; then
    echo "Final production maintenance state: ACTIVE" >&2
  else
    echo "Final production maintenance state: INACTIVE" >&2
  fi
}
trap cleanup EXIT INT TERM

wp_prod() {
  php "$PROD_WP_CLI" --path="$PROD" "$@"
}

wp_eval_prod() {
  wp_prod eval "$1"
}

json_value() {
  local file="$1"
  local path="$2"
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    foreach (explode(".", $argv[2]) as $part) {
      if (!is_array($data) || !array_key_exists($part, $data)) { exit(2); }
      $data = $data[$part];
    }
    echo is_scalar($data) ? (string)$data : json_encode($data, JSON_UNESCAPED_SLASHES);
  ' "$file" "$path"
}

sha256_file() {
  sha256sum "$1" | awk '{print $1}'
}

file_size() {
  stat -c '%s' "$1"
}

usage() {
  cat <<USAGE
Usage:
  ssf-rollback --list
  ssf-rollback
  ssf-rollback --backup <backup-directory-name>
  ssf-rollback --with-db
  ssf-rollback --backup <backup-directory-name> --with-db
USAGE
}

parse_args() {
  while [[ "$#" -gt 0 ]]; do
    case "$1" in
      --list) LIST_ONLY=1 ;;
      --with-db) WITH_DB=1; MODE="FILES + DATABASE" ;;
      --backup)
        shift
        [[ "$#" -gt 0 ]] || fail "--backup requires a backup directory name."
        SELECTED_BACKUP="$1"
        ;;
      -h|--help) usage; exit 0 ;;
      *) fail "Unknown rollback argument: $1" ;;
    esac
    shift
  done
}

backup_info() {
  local backup="$1"
  echo "$BACKUP_ROOT/$backup/BACKUP-INFO.json"
}

is_valid_backup() {
  local backup="$1"
  local info
  info="$(backup_info "$backup")"
  [[ -f "$info" ]] || return 1
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    exit(is_array($data) && ($data["schema_version"] ?? 0) === 1 && ($data["database_backup"]["verified"] ?? false) && ($data["file_backup"]["verified"] ?? false) ? 0 : 1);
  ' "$info"
}

list_backups() {
  section "AVAILABLE SSF ROLLBACKS"
  local backup
  for path in "$BACKUP_ROOT"/prod-before-*; do
    [[ -d "$path" ]] || continue
    backup="$(basename "$path")"
    if is_valid_backup "$backup"; then
      local info
      info="$(backup_info "$backup")"
      echo "$backup"
      echo "  Deployment target: $(json_value "$info" release.target_build)"
      echo "  Previous build:     $(json_value "$info" release.pre_deploy_build)"
      echo "  Created:            $(json_value "$info" backup_timestamp)"
      echo "  Files:              VERIFIED"
      echo "  Database:           VERIFIED"
      echo "  Deployment result:  $(json_value "$info" deployment_state.deployment_success)"
    fi
  done
  section "LEGACY / NOT AUTOMATICALLY ELIGIBLE"
  for path in "$BACKUP_ROOT"/prod-before-*; do
    [[ -d "$path" ]] || continue
    backup="$(basename "$path")"
    is_valid_backup "$backup" || echo "$backup"
  done
}

select_backup() {
  if [[ -n "$SELECTED_BACKUP" ]]; then
    is_valid_backup "$SELECTED_BACKUP" || fail "Selected backup is not eligible: $SELECTED_BACKUP"
    return
  fi
  mapfile -t candidates < <(for path in "$BACKUP_ROOT"/prod-before-*; do [[ -d "$path" ]] && is_valid_backup "$(basename "$path")" && basename "$path"; done | sort)
  [[ "${#candidates[@]}" == "1" ]] || [[ "${#candidates[@]}" -gt 1 ]] || fail "No eligible rollback backups found."
  if [[ "${#candidates[@]}" -gt 1 ]]; then
    SELECTED_BACKUP="${candidates[-1]}"
  else
    SELECTED_BACKUP="${candidates[0]}"
  fi
}

validate_prod_target() {
  local prod_real expected_real env
  prod_real="$(realpath "$PROD")"
  expected_real="$(realpath "$HOME_DIR/ssfb.se/public_html")"
  [[ "$prod_real" == "$expected_real" ]] || fail "Exact PROD path required. Got $prod_real"
  [[ "$prod_real" != *"/dev"* ]] || fail "DEV cannot be targeted by rollback."
  [[ -f "$PROD/wp-config.php" ]] || fail "PROD wp-config.php missing."
  env="$(wp_eval_prod 'echo wp_get_environment_type();')"
  [[ "$env" == "production" ]] || fail "PROD environment must be production. Got $env"
}

validate_backup() {
  local info db_archive file_archive current_prefix expected_prefix home siteurl
  info="$(backup_info "$SELECTED_BACKUP")"
  [[ -f "$info" ]] || fail "BACKUP-INFO.json missing."
  php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$info" || fail "Invalid BACKUP-INFO.json."
  [[ "$(json_value "$info" production.expected_home_url)" == "$EXPECTED_PROD_URL" ]] || fail "Wrong site rejected."
  [[ "$(json_value "$info" production.path)" == "$PROD" ]] || fail "Production path in backup does not match current PROD."
  current_prefix="$(wp_eval_prod 'global $wpdb; echo $wpdb->prefix;')"
  expected_prefix="$(json_value "$info" production.db_prefix)"
  [[ "$current_prefix" == "$expected_prefix" ]] || fail "Wrong DB prefix rejected. Current=$current_prefix Expected=$expected_prefix"
  home="$(wp_prod option get home)"
  siteurl="$(wp_prod option get siteurl)"
  [[ "$home" == "$EXPECTED_PROD_URL" && "$siteurl" == "$EXPECTED_PROD_URL" ]] || fail "Wrong site rejected. home=$home siteurl=$siteurl"
  db_archive="$BACKUP_ROOT/$SELECTED_BACKUP/$(json_value "$info" database_backup.filename)"
  file_archive="$BACKUP_ROOT/$SELECTED_BACKUP/$(json_value "$info" file_backup.filename)"
  [[ -f "$db_archive" ]] || fail "Database archive missing."
  [[ -f "$file_archive" ]] || fail "File archive missing."
  [[ "$(sha256_file "$db_archive")" == "$(json_value "$info" database_backup.sha256)" ]] || fail "Database checksum mismatch rejected."
  [[ "$(sha256_file "$file_archive")" == "$(json_value "$info" file_backup.sha256)" ]] || fail "File archive checksum mismatch rejected."
  gzip -t "$db_archive" || fail "Corrupt gzip rejected."
  tar -tzf "$file_archive" >/dev/null || fail "Corrupt tar rejected."
}

show_plan() {
  local info current_build restore_build db_line
  info="$(backup_info "$SELECTED_BACKUP")"
  current_build="$(wp_eval_prod 'if (class_exists("SSF_Release_Manager")) { $status = SSF_Release_Manager::status(); echo (string)($status["build"] ?? ""); }' 2>/dev/null || true)"
  restore_build="$(json_value "$info" release.pre_deploy_build)"
  db_line="NO"
  [[ "$WITH_DB" == "1" ]] && db_line="YES"
  section "SSF ROLLBACK PLAN"
  cat <<PLAN
Selected backup:    $SELECTED_BACKUP
Backup timestamp:   $(json_value "$info" backup_timestamp)
Current build:      $current_build
Restore build:      $restore_build
File actions:       restore deployment-managed runtime paths only
Plugin actions:     restore recorded plugin activation state only
Restore database:   $db_line
PLAN
}

confirm_once() {
  local expected confirmation
  if [[ "$WITH_DB" == "1" ]]; then
    expected="ROLLBACK WITH DATABASE"
  else
    expected="ROLLBACK"
  fi
  echo "Type exactly $expected to continue:"
  read -r confirmation
  [[ "$confirmation" == "$expected" ]] || fail "Rollback confirmation did not match."
}

activate_maintenance() {
  local reason="${1:-rollback}"
  cat > "$PROD/.maintenance" <<'PHP'
<?php $upgrading = time(); ?>
PHP
  [[ -f "$PROD/.maintenance" ]] || fail "Production maintenance marker missing."
  MAINTENANCE_ACTIVE=1
  local status
  status="$(curl -sS -L -o /tmp/ssf-rollback-maintenance.html -w '%{http_code}' "$EXPECTED_PROD_URL/" || true)"
  if [[ "$status" != "503" ]] && ! rg -qi 'maintenance|underh.ll|briefly unavailable|upgrading' /tmp/ssf-rollback-maintenance.html; then
    rm -f /tmp/ssf-rollback-maintenance.html
    fail "Maintenance mode did not block public HTTP."
  fi
  rm -f /tmp/ssf-rollback-maintenance.html
  echo "Production maintenance: ACTIVE"
  echo "Reason: $reason"
}

deactivate_maintenance() {
  rm -f "$PROD/.maintenance"
  MAINTENANCE_ACTIVE=0
  echo "Production maintenance: INACTIVE"
}

maintenance_grace_period() {
  echo "Waiting $MAINTENANCE_GRACE_SECONDS seconds for in-flight requests..."
  sleep "$MAINTENANCE_GRACE_SECONDS"
}

record_error_log_baseline() {
  if [[ -f "$ERROR_LOG" ]]; then
    ERROR_LOG_LINES="$(wc -l < "$ERROR_LOG")"
  else
    ERROR_LOG_LINES=0
  fi
}

create_rescue_backup() {
  local timestamp archive
  timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
  RESCUE_BACKUP="$BACKUP_ROOT/pre-rollback-$timestamp"
  mkdir -p "$RESCUE_BACKUP"
  archive="$RESCUE_BACKUP/current-wp-content-targets.tar.gz"
  tar -czf "$archive" -C "$PROD" wp-content/mu-plugins wp-content/plugins wp-content/themes
  tar -tzf "$archive" >/dev/null
  sha256_file "$archive" > "$archive.sha256"
  wp_prod plugin list --format=json --fields=name,status,version > "$RESCUE_BACKUP/plugins-current.json"
  wp_prod theme list --format=json --fields=name,status,version > "$RESCUE_BACKUP/themes-current.json"
  if [[ "$WITH_DB" == "1" ]]; then
    wp_prod db export "$RESCUE_BACKUP/database.sql" --add-drop-table >/dev/null
    [[ -s "$RESCUE_BACKUP/database.sql" ]] || fail "Rescue DB SQL missing or empty."
    rg -q 'CREATE TABLE|INSERT INTO|DROP TABLE' "$RESCUE_BACKUP/database.sql" || fail "Rescue DB sanity failed."
    gzip "$RESCUE_BACKUP/database.sql"
    gzip -t "$RESCUE_BACKUP/database.sql.gz"
    sha256_file "$RESCUE_BACKUP/database.sql.gz" > "$RESCUE_BACKUP/database.sql.gz.sha256"
  fi
  cat > "$RESCUE_BACKUP/RESCUE-INFO.txt" <<INFO
created=$(date -u +%Y-%m-%dT%H:%M:%SZ)
selected_backup=$SELECTED_BACKUP
mode=$MODE
sharepoint_not_rolled_back=yes
INFO
  echo "Pre-rollback rescue backup: $RESCUE_BACKUP"
}

restore_files() {
  local info archive
  info="$(backup_info "$SELECTED_BACKUP")"
  archive="$BACKUP_ROOT/$SELECTED_BACKUP/$(json_value "$info" file_backup.filename)"
  tar -xzf "$archive" -C "$PROD"
  ROLLBACK_MUTATED=1
  while IFS=$'\t' read -r path state; do
    [[ "$state" == "exists_before=false" ]] || continue
    case "$path" in
      wp-content/plugins/*|wp-content/themes/*|wp-content/mu-plugins/*)
        if [[ -e "$PROD/$path" ]]; then
          rm -rf "$PROD/$path"
        fi
        ;;
      *) fail "Refusing to remove non-runtime rollback path: $path" ;;
    esac
  done < "$BACKUP_ROOT/$SELECTED_BACKUP/runtime-paths-before.tsv"
}

restore_plugin_state() {
  local info
  info="$(backup_info "$SELECTED_BACKUP")"
  php -r '
    $plugins = json_decode(file_get_contents($argv[1]), true);
    foreach ($plugins as $plugin) {
      if (!isset($plugin["name"], $plugin["status"])) { continue; }
      echo $plugin["name"], "\t", $plugin["status"], PHP_EOL;
    }
  ' "$BACKUP_ROOT/$SELECTED_BACKUP/plugins-before.json" | while IFS=$'\t' read -r plugin status; do
    [[ -d "$PROD/wp-content/plugins/$plugin" ]] || continue
    if [[ "$status" == "active" ]]; then
      wp_prod plugin activate "$plugin" >/dev/null || fail "Could not reactivate plugin: $plugin"
    else
      wp_prod plugin deactivate "$plugin" >/dev/null || true
    fi
  done
}

restore_database() {
  local info archive temp_sql home siteurl current_prefix expected_prefix
  [[ "$WITH_DB" == "1" ]] || return 0
  info="$(backup_info "$SELECTED_BACKUP")"
  archive="$BACKUP_ROOT/$SELECTED_BACKUP/$(json_value "$info" database_backup.filename)"
  [[ "$(sha256_file "$archive")" == "$(json_value "$info" database_backup.sha256)" ]] || fail "Database checksum mismatch rejected before import."
  gzip -t "$archive"
  temp_sql="$(mktemp)"
  gzip -dc "$archive" > "$temp_sql"
  [[ -s "$temp_sql" ]] || fail "Temporary SQL restore file is empty."
  wp_prod db import "$temp_sql"
  rm -f "$temp_sql"
  ROLLBACK_MUTATED=1
  wp_prod db check
  home="$(wp_prod option get home)"
  siteurl="$(wp_prod option get siteurl)"
  [[ "$home" == "$EXPECTED_PROD_URL" && "$siteurl" == "$EXPECTED_PROD_URL" ]] || fail "home/siteurl changed after DB restore."
  current_prefix="$(wp_eval_prod 'global $wpdb; echo $wpdb->prefix;')"
  expected_prefix="$(json_value "$info" production.db_prefix)"
  [[ "$current_prefix" == "$expected_prefix" ]] || fail "DB prefix changed after DB restore."
  echo "External SharePoint data: NOT ROLLED BACK"
}

internal_verify() {
  wp_prod core is-installed >/dev/null
  wp_prod db check >/dev/null
  wp_prod ssf release status >/dev/null || true
  wp_prod theme list --status=active >/dev/null
  wp_prod plugin list >/dev/null
  if wp_prod plugin is-active simple-cloudflare-turnstile >/dev/null 2>&1; then
    wp_eval_prod 'if (class_exists("SSF_Antispam") && ! SSF_Antispam::is_configured()) { exit(1); }'
  fi
}

public_smoke() {
  deactivate_maintenance
  local path expected status effective
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
    status="$(curl -sS -L -o /tmp/ssf-rollback-smoke.html -w '%{http_code}' "$EXPECTED_PROD_URL$path")"
    if [[ "$status" != "$expected" ]] || rg -qi 'Fatal error|Parse error|critical error' /tmp/ssf-rollback-smoke.html; then
      activate_maintenance "rollback public smoke failure" >/dev/null 2>&1 || true
      fail "Public smoke failed for $path"
    fi
  done
  effective="$(curl -sS -I -L -o /tmp/ssf-rollback-admin.headers -w '%{url_effective}' "$EXPECTED_PROD_URL/wp-admin/")"
  echo "$effective" | rg -q 'wp-login\.php' || { activate_maintenance "rollback public smoke failure" >/dev/null 2>&1 || true; fail "wp-admin smoke failed."; }
  rm -f /tmp/ssf-rollback-smoke.html /tmp/ssf-rollback-admin.headers
}

error_log_post_check() {
  [[ -f "$ERROR_LOG" ]] || return 0
  local new_lines
  new_lines="$(tail -n +"$((ERROR_LOG_LINES + 1))" "$ERROR_LOG" || true)"
  if printf '%s\n' "$new_lines" | rg -i 'PHP Fatal error|PHP Parse error|Uncaught Error|Allowed memory size exhausted'; then
    activate_maintenance "rollback error log failure" >/dev/null 2>&1 || true
    fail "New rollback PHP errors found."
  fi
}

success_report() {
  local info restored_build restored_db
  info="$(backup_info "$SELECTED_BACKUP")"
  restored_build="$(json_value "$info" release.pre_deploy_build)"
  restored_db="NOT REQUESTED"
  [[ "$WITH_DB" == "1" ]] && restored_db="PASS"
  if [[ "$WITH_DB" == "1" ]]; then
    section "SSF FULL ROLLBACK SUCCESS"
  else
    section "SSF ROLLBACK SUCCESS"
  fi
  cat <<REPORT
Mode:                    $MODE
Restored from:           $SELECTED_BACKUP
Restored build:          $restored_build
Rescue backup:           $RESCUE_BACKUP

Production maintenance: PASS
Files:                   PASS
Plugins:                 PASS
Theme:                   PASS
Database restore:        $restored_db
HTTP smoke:              PASS
Error log:               PASS
External SharePoint:     NOT ROLLED BACK

Public site:             OPEN
ROLLBACK:                SUCCESS
REPORT
}

main() {
  parse_args "$@"
  if [[ "$LIST_ONLY" == "1" ]]; then
    list_backups
    exit 0
  fi
  select_backup
  validate_prod_target
  validate_backup
  show_plan
  confirm_once
  activate_maintenance "rollback"
  maintenance_grace_period
  record_error_log_baseline
  create_rescue_backup
  restore_files
  if [[ "$WITH_DB" == "1" ]]; then
    restore_database
  else
    restore_plugin_state
  fi
  internal_verify
  public_smoke
  error_log_post_check
  ROLLBACK_SUCCESS=1
  success_report
}

main "$@"
