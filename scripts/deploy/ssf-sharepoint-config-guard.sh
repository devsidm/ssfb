#!/usr/bin/env bash

SHAREPOINT_CONFIG_STATUS="${SHAREPOINT_CONFIG_STATUS:-NOT RUN}"
SHAREPOINT_CONFIG_FINGERPRINT="${SHAREPOINT_CONFIG_FINGERPRINT:-}"

sharepoint_config_report() {
  wp_eval_prod '
    $storedDestinations = get_option("ssf_member_portal_sharepoint_destinations", array());
    $destinations = is_array($storedDestinations["destinations"] ?? null) ? $storedDestinations["destinations"] : array();
    $graph = get_option("ssf_member_portal_graph_configuration", array());
    $missing = array();
    $requiredDestinations = array(
      "membership_applications" => array("site_id", "drive_id", "list_id", "folder_id"),
      "annual_meetings" => array("site_id", "drive_id", "list_id", "folder_id"),
    );

    foreach ($requiredDestinations as $destination => $fields) {
      if (!isset($destinations[$destination]) || !is_array($destinations[$destination])) {
        $missing[] = "destinations." . $destination;
        continue;
      }
      $production = $destinations[$destination]["production"] ?? null;
      if (!is_array($production)) {
        $missing[] = "destinations." . $destination . ".production";
        continue;
      }
      foreach ($fields as $field) {
        if (!isset($production[$field]) || trim((string) $production[$field]) === "") {
          $missing[] = "destinations." . $destination . ".production." . $field;
        }
      }
    }

    foreach (array("tenant_id", "client_id", "client_secret") as $field) {
      if (!isset($graph[$field]) || trim((string) $graph[$field]) === "") {
        $missing[] = "graph." . $field;
      }
    }

    $snapshot = array(
      "sharepoint_destinations" => array(
        "schema_version" => $storedDestinations["schema_version"] ?? null,
        "migrated_environment" => $storedDestinations["migrated_environment"] ?? null,
        "migrated_at" => $storedDestinations["migrated_at"] ?? null,
        "destinations" => array(),
      ),
      "graph" => array(
        "tenant_id" => (string) ($graph["tenant_id"] ?? ""),
        "client_id" => (string) ($graph["client_id"] ?? ""),
        "client_secret" => isset($graph["client_secret"]) && (string) $graph["client_secret"] !== ""
          ? "sha256:" . hash("sha256", (string) $graph["client_secret"])
          : "",
      ),
    );
    foreach (array_keys($requiredDestinations) as $destination) {
      $snapshot["sharepoint_destinations"]["destinations"][$destination] = array(
        "production" => $destinations[$destination]["production"] ?? array(),
      );
    }

    $fingerprintPayload = json_encode(array(
      "destinations" => $snapshot["sharepoint_destinations"]["destinations"],
      "graph" => $snapshot["graph"],
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo json_encode(array(
      "ok" => count($missing) === 0,
      "missing" => $missing,
      "fingerprint" => hash("sha256", $fingerprintPayload),
      "snapshot" => $snapshot,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
  '
}

validate_sharepoint_config() {
  local phase="${1:-validate}"
  section "PROD SHAREPOINT CONFIG PROTECTION"
  local status_file ok fingerprint
  status_file="$(mktemp)"
  sharepoint_config_report > "$status_file" || { rm -f "$status_file"; fail "SharePoint PROD configuration validation failed."; }
  ok="$(php -r '$d=json_decode(file_get_contents($argv[1]), true); echo !empty($d["ok"]) ? "1" : "0";' "$status_file")"
  if [[ "$ok" != "1" ]]; then
    php -r '$d=json_decode(file_get_contents($argv[1]), true); foreach (($d["missing"] ?? array()) as $m) { echo "Missing: ", $m, PHP_EOL; }' "$status_file" >&2
    rm -f "$status_file"
    fail "Required PROD SharePoint configuration is missing."
  fi
  fingerprint="$(php -r '$d=json_decode(file_get_contents($argv[1]), true); echo (string)($d["fingerprint"] ?? "");' "$status_file")"
  [[ -n "$fingerprint" ]] || { rm -f "$status_file"; fail "SharePoint PROD configuration fingerprint missing."; }
  if [[ "$phase" == "preflight" ]]; then
    SHAREPOINT_CONFIG_FINGERPRINT="$fingerprint"
  elif [[ "$phase" == "post" ]]; then
    [[ "$fingerprint" == "$SHAREPOINT_CONFIG_FINGERPRINT" ]] || { rm -f "$status_file"; fail "SharePoint PROD configuration fingerprint changed during deployment."; }
    echo "SharePoint PROD configuration unchanged: PASS"
  fi
  if [[ -n "${BACKUP_DIR:-}" && -d "$BACKUP_DIR" ]]; then
    php -r '
      $source = $argv[1];
      $target = $argv[2];
      $data = json_decode(file_get_contents($source), true);
      $out = array(
        "fingerprint" => $data["fingerprint"] ?? "",
        "snapshot" => $data["snapshot"] ?? array(),
      );
      file_put_contents($target, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    ' "$status_file" "$BACKUP_DIR/prod-sharepoint-config-snapshot.json"
  fi
  rm -f "$status_file"
  SHAREPOINT_CONFIG_STATUS="PASS"
  echo "SharePoint PROD configuration: PASS"
}
