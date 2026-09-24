#!/usr/bin/env bash

DEV_LINK_STATUS="NOT RUN"
DEV_LINK_GUID_IGNORED="0"

ssf_dev_link_guid_count() {
  wp_prod eval '
    global $wpdb;
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE guid LIKE " . "\"%ssfb.se/dev%\"" . " OR guid LIKE " . "\"%/dev/%\"");
    echo (string) $count;
  '
}

ssf_dev_link_database_check() {
  DEV_LINK_GUID_IGNORED="$(ssf_dev_link_guid_count 2>/dev/null || echo 0)"
  wp_prod eval '
    global $wpdb;
    $pattern = "~(?:https?:)?//ssfb\\.se/dev(?:/|$)|(?<![A-Za-z0-9_.-])/dev/(?!urandom\\b|null\\b)~i";
    $failures = array();
    $safe_reference = function ($value) use ($pattern) {
      if (!is_scalar($value)) {
        return "";
      }
      $value = (string) $value;
      if (!preg_match($pattern, $value, $match, PREG_OFFSET_CAPTURE)) {
        return "";
      }
      $reference = $match[0][0];
      return substr($reference, 0, 180);
    };
    $scan_rows = function ($table, $column, $id_column, $extra_columns, $label) use ($wpdb, $safe_reference, &$failures) {
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
      if (!$exists) {
        return;
      }
      $columns = array_merge(array($id_column, $column), $extra_columns);
      $select = implode(", ", array_map(function ($column_name) { return "`" . str_replace("`", "``", $column_name) . "`"; }, $columns));
      $rows = $wpdb->get_results("SELECT {$select} FROM {$table} WHERE `{$column}` LIKE " . "\"%ssfb.se/dev%\"" . " OR `{$column}` LIKE " . "\"%/dev/%\"" . " LIMIT 25", ARRAY_A);
      foreach ((array) $rows as $row) {
        $reference = $safe_reference($row[$column] ?? "");
        if ($reference === "") {
          continue;
        }
        $parts = array(
          "Table: " . $table,
          "Column: " . $column,
          "ID: " . (string) ($row[$id_column] ?? ""),
        );
        foreach ($extra_columns as $extra) {
          if (isset($row[$extra]) && is_scalar($row[$extra])) {
            $parts[] = ucfirst(str_replace("_", " ", $extra)) . ": " . substr((string) $row[$extra], 0, 120);
          }
        }
        $parts[] = "Field: " . $label;
        $parts[] = "Reference: " . $reference;
        $failures[] = implode(PHP_EOL, $parts);
      }
    };
    $scan_rows($wpdb->posts, "post_content", "ID", array("post_type", "post_title"), "post_content");
    $scan_rows($wpdb->posts, "post_excerpt", "ID", array("post_type", "post_title"), "post_excerpt");
    $scan_rows($wpdb->postmeta, "meta_value", "meta_id", array("post_id", "meta_key"), "meta_value");
    $scan_rows($wpdb->options, "option_value", "option_id", array("option_name"), "option_value");
    $scan_rows($wpdb->comments, "comment_content", "comment_ID", array("comment_post_ID"), "comment_content");
    $scan_rows($wpdb->comments, "comment_author_url", "comment_ID", array("comment_post_ID"), "comment_author_url");
    $termmeta = $wpdb->prefix . "termmeta";
    $scan_rows($termmeta, "meta_value", "meta_id", array("term_id", "meta_key"), "meta_value");
    if ($failures) {
      echo "PROD DEV-LINK CHECK: FAIL", PHP_EOL, PHP_EOL;
      echo count($failures), " prohibited DEV reference(s) found.", PHP_EOL, PHP_EOL;
      echo implode(PHP_EOL . PHP_EOL, $failures), PHP_EOL;
      exit(4);
    }
  ' || return 1
}

ssf_dev_link_source_check() {
  local runtime_root="$1"
  php -r '
    $config = json_decode(file_get_contents($argv[1]), true);
    $root = rtrim($argv[2], "/\\");
    $pattern = "~(?:https?:)?//ssfb\\.se/dev(?:/|$)|(?<![A-Za-z0-9_.-])/dev/(?!urandom\\b|null\\b)~i";
    $allowed_extensions = array("php", "js", "css", "json", "html", "htm", "txt");
    $paths = array();
    foreach ((array) ($config["production"]["plugins"] ?? array()) as $plugin) {
      $paths[] = $root . "/plugins/" . $plugin;
    }
    foreach ((array) ($config["production"]["themes"] ?? array()) as $theme) {
      $paths[] = $root . "/themes/" . $theme;
    }
    foreach ((array) ($config["production"]["mu_files"] ?? array()) as $file) {
      $paths[] = $root . "/mu-plugins/" . $file;
    }
    $failures = array();
    $scan_file = function ($file) use ($pattern, $root, &$failures) {
      $normalized = str_replace("\\", "/", $file);
      // CLI fixtures are not runtime source; keep scanning all other plugin files.
      if (preg_match("~/(?:docs?|tests)/|\\.md$~i", $normalized)) {
        return;
      }
      $lines = @file($file, FILE_IGNORE_NEW_LINES);
      if (!is_array($lines)) {
        return;
      }
      foreach ($lines as $index => $line) {
        if (preg_match($pattern, $line, $match)) {
          $relative = ltrim(str_replace("\\", "/", substr($file, strlen($root))), "/");
          $failures[] = "File: wp-content/" . $relative . PHP_EOL . "Line: " . ($index + 1) . PHP_EOL . "Reference: " . substr($match[0], 0, 180);
        }
      }
    };
    foreach ($paths as $path) {
      if (is_file($path)) {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, $allowed_extensions, true)) {
          $scan_file($path);
        }
        continue;
      }
      if (!is_dir($path)) {
        continue;
      }
      $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file_info) {
        if (!$file_info->isFile()) {
          continue;
        }
        $extension = strtolower(pathinfo($file_info->getPathname(), PATHINFO_EXTENSION));
        if (in_array($extension, $allowed_extensions, true)) {
          $scan_file($file_info->getPathname());
        }
      }
    }
    if ($failures) {
      echo "DEV LINK IN RUNTIME SOURCE", PHP_EOL, PHP_EOL;
      echo implode(PHP_EOL . PHP_EOL, $failures), PHP_EOL;
      exit(5);
    }
  ' "$CONFIG" "$runtime_root"
}

prod_dev_link_safety() {
  local runtime_root="$1"
  local phase="${2:-pre_deploy}"
  section "PROD DEV-LINK SAFETY"
  ssf_dev_link_database_check || {
    echo
    echo "Historical posts.guid DEV references ignored: $DEV_LINK_GUID_IGNORED"
    if [[ "$phase" == "pre_deploy" ]]; then
      echo "Deployment stopped before production mutation."
    else
      echo "Deployment verification failed after production mutation."
    fi
    fail "PROD DEV-link safety failed."
  }
  ssf_dev_link_source_check "$runtime_root" || {
    echo
    echo "Historical posts.guid DEV references ignored: $DEV_LINK_GUID_IGNORED"
    if [[ "$phase" == "pre_deploy" ]]; then
      echo "Deployment stopped before production mutation."
    else
      echo "Deployment verification failed after production mutation."
    fi
    fail "Runtime source DEV-link safety failed."
  }
  DEV_LINK_STATUS="PASS"
  echo "PROD DEV-link safety: PASS"
  echo "Historical posts.guid DEV references ignored: $DEV_LINK_GUID_IGNORED"
}
