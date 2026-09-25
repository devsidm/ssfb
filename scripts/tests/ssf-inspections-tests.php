<?php
/** Targeted contract tests; deliberately no WordPress/DEV mutation. */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('SSF_INSPECTIONS_VERSION', '0.1.0');
final class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = array()) {}
}
final class SSF_Access_Control {
    public static bool $active = true;
    public static function is_active(int $user_id): bool { return self::$active; }
}
final class Test_WPDB {
    public string $prefix = 'wp_';
    public array $inspections = array();
    public array $snapshots = array();
    public array $answers = array();
    public array $photos = array();
    public array $versions = array();
    public int $insert_id = 0;
    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $sql = preg_replace('/%[ds]/', is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'", $sql, 1);
        }
        return $sql;
    }
    public function get_row(string $sql, $mode = null): ?array {
        if (str_contains($sql, 'ssf_inspection_inspections')) {
            preg_match('/WHERE id=(\d+)/', $sql, $matches);
            return $this->inspections[(int) ($matches[1] ?? 0)] ?? null;
        }
        return null;
    }
    public function get_results(string $sql, $mode = null): array {
        if (! str_contains($sql, 'ssf_inspection_snapshots')) return array();
        preg_match('/s\.inspection_id=(\d+)/', $sql, $matches);
        $id = (int) ($matches[1] ?? 0);
        return array_values(array_map(function (array $row): array {
            return array_merge($row, $this->answers[(int) $row['id']] ?? array('assessment' => null, 'comment' => null, 'proposed_action' => null, 'priority' => null, 'follow_up_status' => null));
        }, array_filter($this->snapshots, fn ($row) => (int) $row['inspection_id'] === $id)));
    }
    public function get_var(string $sql) {
        if (str_contains($sql, 'ssf_inspection_photos')) {
            preg_match('/inspection_id=(\d+) AND snapshot_id=(\d+)/', $sql, $matches);
            return count(array_filter($this->photos, fn ($row) => (int) $row['inspection_id'] === (int) ($matches[1] ?? 0) && (int) $row['snapshot_id'] === (int) ($matches[2] ?? 0) && ! $row['deleted_at']));
        }
        if (str_contains($sql, 'ssf_inspection_versions')) {
            preg_match('/WHERE id=(\d+) AND template_id=(\d+) AND status=\'([^\']+)\'/', $sql, $matches);
            $row = $this->versions[(int) ($matches[1] ?? 0)] ?? null;
            return $row && (int) $row['template_id'] === (int) ($matches[2] ?? 0) && $row['status'] === ($matches[3] ?? '') ? $row['id'] : null;
        }
        return null;
    }
}
$wpdb = new Test_WPDB();
$test_user = 10;
$test_caps = array(10 => array('ssf_inspect_v2'), 20 => array('ssf_inspect_v2'), 30 => array('ssf_manage_inspections'));
function get_current_user_id(): int { global $test_user; return $test_user; }
function user_can(int $id, string $cap): bool { global $test_caps; return in_array($cap, $test_caps[$id] ?? array(), true); }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $value)); }
function wp_is_uuid(string $value): bool { return (bool) preg_match('/^[a-f0-9-]{36}$/i', $value); }
function current_time(string $type, bool $gmt = false): string { return '2026-09-25 12:00:00'; }
function sanitize_textarea_field($value): string { return trim((string) $value); }
function wp_json_encode($value): string { return json_encode($value); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
require __DIR__ . '/../../wp-content/plugins/ssf-inspektioner/includes/class-ssf-inspections-db.php';
require __DIR__ . '/../../wp-content/plugins/ssf-inspektioner/includes/class-ssf-inspections-core.php';
require __DIR__ . '/../../wp-content/plugins/ssf-inspektioner/includes/class-ssf-inspections-templates.php';

$failures = array();
function check(string $name, bool $pass): void { global $failures; if (! $pass) $failures[] = $name; }
$wpdb->inspections[1] = array('id' => 1, 'ship_id' => 99, 'inspector_user_id' => 10, 'status' => 'in_progress', 'revision' => 1);
$wpdb->inspections[2] = array('id' => 2, 'ship_id' => 99, 'inspector_user_id' => 20, 'status' => 'in_progress', 'revision' => 1);
check('own inspection allowed', SSF_Inspections_Core::can_read(1));
check('IDOR read denied', ! SSF_Inspections_Core::can_read(2));
check('IDOR write denied', ! SSF_Inspections_Core::can_edit(2));
$test_user = 30;
check('inspection admin can read all', SSF_Inspections_Core::can_read(2));
$test_user = 10;
SSF_Access_Control::$active = false;
check('inactive user denied', ! SSF_Inspections_Core::can_read(1));
SSF_Access_Control::$active = true;
$wpdb->snapshots = array(
    array('id' => 101, 'inspection_id' => 1, 'title' => 'Skrov', 'is_required' => 1, 'photo_policy' => 'optional'),
    array('id' => 102, 'inspection_id' => 1, 'title' => 'Rigg', 'is_required' => 1, 'photo_policy' => 'required'),
);
check('required answers block completion', count(SSF_Inspections_Core::preflight(1)) === 2);
$wpdb->answers[101] = array('assessment' => 'remark', 'comment' => '', 'proposed_action' => '', 'priority' => 'high', 'follow_up_status' => '');
$wpdb->answers[102] = array('assessment' => 'approved', 'comment' => '', 'proposed_action' => '', 'priority' => '', 'follow_up_status' => '');
check('remark comment and required photo block completion', count(SSF_Inspections_Core::preflight(1)) === 2);
$wpdb->answers[101]['comment'] = 'Spricka';
$wpdb->photos[] = array('inspection_id' => 1, 'snapshot_id' => 102, 'deleted_at' => null);
check('complete answers/photos pass preflight', SSF_Inspections_Core::preflight(1) === array());
$wpdb->inspections[1]['status'] = 'completed';
check('completed inspection locked', ! SSF_Inspections_Core::can_edit(1));
check('completed answer rejected', is_wp_error(SSF_Inspections_Core::save_answer(1, 101, array('assessment' => 'approved'), 'df4901d5-105e-4fb2-a99f-a7c27381dbdd')));
$wpdb->versions[1] = array('id' => 1, 'template_id' => 7, 'status' => 'published');
$wpdb->versions[2] = array('id' => 2, 'template_id' => 7, 'status' => 'draft');
check('published template immutable', ! SSF_Inspections_Templates::draft(7, 1));
check('draft template mutable', SSF_Inspections_Templates::draft(7, 2));
check('published item addition rejected', is_wp_error(SSF_Inspections_Templates::add_item(7, 1, 9, array('title' => 'Test'))));
$plugin_dir = __DIR__ . '/../../wp-content/plugins/ssf-inspektioner/';
$core = file_get_contents($plugin_dir . 'includes/class-ssf-inspections-core.php');
$ui = file_get_contents($plugin_dir . 'includes/class-ssf-inspections-ui.php');
$js = file_get_contents($plugin_dir . 'assets/inspection.js');
check('ship references canonical CPT', str_contains($core, "'medlemsfartyg' !== \$ship->post_type"));
check('snapshot is copied at start', str_contains($core, "SSF_Inspections_DB::table('snapshots')"));
check('photo upload requires actual HTTP upload', str_contains($core, 'is_uploaded_file'));
check('photo has MIME allow-list', str_contains($core, "'image/webp'"));
check('photo is not WordPress media', ! str_contains($core, 'media_handle_upload') && ! str_contains($core, 'wp_insert_attachment'));
check('private photo read requires object authorization', str_contains($ui, 'SSF_Inspections_Core::can_read((int) $photo'));
check('private photo read requires nonce', str_contains($ui, "'ssf_inspection_photo_' . \$photo_id"));
check('REST write permission checks nonce', str_contains($ui, "wp_verify_nonce(\$_SERVER['HTTP_X_WP_NONCE']"));
check('offline queue uses IndexedDB', str_contains($js, 'indexedDB.open'));
check('photo queue retains Blob', str_contains($js, 'blob });'));
check('mobile camera capture', str_contains($ui, 'capture="environment"'));
check('client image re-encoding', str_contains($js, "canvas.toBlob"));
check('completion waits for pending queue', str_contains($js, 'if (pendingCount)'));
check('dynamic output escaped', str_contains($ui, 'esc_html(') && str_contains($ui, 'esc_url('));
if ($failures) {
    fwrite(STDERR, "FAIL: " . implode(', ', $failures) . "\n");
    exit(1);
}
echo "PASS: inspection permissions, IDOR, preflight, lock, template immutability, photo and offline contracts.\n";
