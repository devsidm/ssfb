<?php
if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Inspections_Core
{
    public const ASSESSMENTS = array('approved', 'remark', 'serious_remark', 'not_checked', 'not_applicable');
    public const FINALS = array('approved', 'approved_with_remarks', 'supplement_required', 'not_approved', 'special_review');
    public const FOLLOW_UP = array('fixed', 'remains', 'worsened', 'not_assessable');

    public static function manager(int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        return $user_id > 0 && (! class_exists('SSF_Access_Control') || SSF_Access_Control::is_active($user_id))
            && (user_can($user_id, 'ssf_manage_inspections') || user_can($user_id, 'manage_options'));
    }

    public static function inspector(int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        return $user_id > 0 && (! class_exists('SSF_Access_Control') || SSF_Access_Control::is_active($user_id))
            && (self::manager($user_id) || user_can($user_id, 'ssf_inspect_v2'));
    }

    public static function inspection(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('inspections') . ' WHERE id=%d', $id), ARRAY_A);
        return $row ?: null;
    }

    public static function can_read(int $id): bool
    {
        $row = self::inspection($id);
        return $row && self::inspector() && (self::manager() || (int) $row['inspector_user_id'] === get_current_user_id());
    }

    public static function can_edit(int $id): bool
    {
        $row = self::inspection($id);
        return $row && 'in_progress' === $row['status'] && self::can_read($id);
    }

    public static function event(int $id, string $type, array $details = array()): bool
    {
        global $wpdb;
        return (bool) $wpdb->insert(SSF_Inspections_DB::table('events'), array(
            'inspection_id' => $id,
            'actor_user_id' => get_current_user_id(),
            'event_type' => $type,
            'details' => wp_json_encode($details),
            'created_at' => current_time('mysql', true),
        ));
    }

    public static function create(int $ship_id, int $version_id, string $type, string $date, string $location, string $representative, int $parent_id = 0)
    {
        global $wpdb;
        if (! self::inspector()) {
            return new WP_Error('forbidden', 'Ingen inspektionsbehörighet.', array('status' => 403));
        }
        $ship = get_post($ship_id);
        if (! $ship || 'medlemsfartyg' !== $ship->post_type || 'publish' !== $ship->post_status) {
            return new WP_Error('ship', 'Välj ett publicerat medlemsfartyg.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            return new WP_Error('date', 'Ogiltigt datum.');
        }
        $version = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('versions') . ' WHERE id=%d AND status=%s', $version_id, 'published'), ARRAY_A);
        if (! $version) {
            return new WP_Error('template', 'Välj en publicerad mallversion.');
        }
        if ($parent_id) {
            $parent = self::inspection($parent_id);
            if (! $parent || ! self::can_read($parent_id) || 'completed' !== $parent['status'] || (int) $parent['ship_id'] !== $ship_id) {
                return new WP_Error('parent', 'Ogiltig uppföljning.', array('status' => 403));
            }
        }
        $items = $wpdb->get_results($wpdb->prepare(
            'SELECT i.*, s.section_key, s.title AS section_title, s.sort_order AS section_order FROM ' . SSF_Inspections_DB::table('items') . ' i INNER JOIN ' . SSF_Inspections_DB::table('sections') . ' s ON s.id=i.section_id WHERE s.version_id=%d ORDER BY s.sort_order,i.sort_order,i.id',
            $version_id
        ), ARRAY_A);
        if (! $items) {
            return new WP_Error('template_empty', 'Mallen saknar kontrollpunkter.');
        }
        $wpdb->query('START TRANSACTION');
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(SSF_Inspections_DB::table('inspections'), array(
            'ship_id' => $ship_id, 'template_version_id' => $version_id, 'inspector_user_id' => get_current_user_id(),
            'inspection_type' => sanitize_text_field($type), 'inspection_date' => $date,
            'location' => sanitize_text_field($location), 'representative' => sanitize_text_field($representative),
            'parent_inspection_id' => $parent_id ?: null, 'status' => 'in_progress', 'created_at' => $now, 'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        foreach ($items as $item) {
            $ok = $ok && $wpdb->insert(SSF_Inspections_DB::table('snapshots'), array(
                'inspection_id' => $id, 'source_item_id' => $item['id'], 'section_key' => $item['section_key'],
                'section_title' => $item['section_title'], 'section_order' => $item['section_order'],
                'item_key' => $item['item_key'], 'title' => $item['title'], 'help_text' => $item['help_text'],
                'is_required' => $item['is_required'], 'photo_policy' => $item['photo_policy'], 'item_order' => $item['sort_order'],
            ));
        }
        if (! $ok) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('database', 'Inspektionen kunde inte skapas.');
        }
        $wpdb->query('COMMIT');
        self::event($id, $parent_id ? 'follow_up_created' : 'created', $parent_id ? array('parent_inspection_id' => $parent_id) : array());
        return $id;
    }

    public static function snapshots(int $id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT s.*, a.assessment, a.comment, a.proposed_action, a.priority, a.follow_up_status FROM ' . SSF_Inspections_DB::table('snapshots') . ' s LEFT JOIN ' . SSF_Inspections_DB::table('answers') . ' a ON a.inspection_id=s.inspection_id AND a.snapshot_id=s.id WHERE s.inspection_id=%d ORDER BY s.section_order,s.item_order,s.id', $id
        ), ARRAY_A) ?: array();
    }

    public static function save_answer(int $id, int $snapshot_id, array $data, string $operation_id)
    {
        global $wpdb;
        if (! self::can_edit($id)) {
            return new WP_Error('forbidden', 'Inspektionen kan inte ändras.', array('status' => 403));
        }
        if (! wp_is_uuid($operation_id)) {
            return new WP_Error('operation', 'Ogiltigt operations-ID.');
        }
        $assessment = sanitize_key($data['assessment'] ?? '');
        $follow_up = sanitize_key($data['follow_up_status'] ?? '');
        if (! in_array($assessment, self::ASSESSMENTS, true) || ($follow_up && ! in_array($follow_up, self::FOLLOW_UP, true))) {
            return new WP_Error('assessment', 'Ogiltig bedömning.');
        }
        $snapshot = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('snapshots') . ' WHERE id=%d AND inspection_id=%d', $snapshot_id, $id), ARRAY_A);
        if (! $snapshot) {
            return new WP_Error('snapshot', 'Kontrollpunkten hör inte till inspektionen.', array('status' => 403));
        }
        $answer = array(
            'assessment' => $assessment,
            'comment' => sanitize_textarea_field($data['comment'] ?? ''),
            'proposed_action' => sanitize_textarea_field($data['proposed_action'] ?? ''),
            'priority' => in_array($data['priority'] ?? '', array('low', 'medium', 'high'), true) ? $data['priority'] : '',
            'follow_up_status' => $follow_up,
        );
        $hash = hash('sha256', wp_json_encode(array($snapshot_id, $answer)));
        $wpdb->query('START TRANSACTION');
        $op = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('operations') . ' WHERE inspection_id=%d AND client_operation_id=%s FOR UPDATE', $id, $operation_id), ARRAY_A);
        if ($op) {
            $wpdb->query('COMMIT');
            return hash_equals($op['payload_hash'], $hash) ? (int) $op['result_revision'] : new WP_Error('operation_conflict', 'Operations-ID har redan använts för andra data.');
        }
        $wpdb->query($wpdb->prepare('SELECT id FROM ' . SSF_Inspections_DB::table('inspections') . ' WHERE id=%d FOR UPDATE', $id));
        $locked = self::inspection($id);
        if (! $locked || 'in_progress' !== $locked['status']) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('conflict', 'Inspektionen har slutförts. Svaret sparades inte.', array('status' => 409));
        }
        $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('answers') . ' WHERE inspection_id=%d AND snapshot_id=%d', $id, $snapshot_id), ARRAY_A);
        $changed = ! $old;
        if ($old) {
            foreach ($answer as $key => $value) {
                if ((string) ($old[$key] ?? '') !== (string) $value) {
                    $changed = true;
                    break;
                }
            }
        }
        $now = current_time('mysql', true);
        $ok = true;
        if ($changed) {
            $answer['updated_at'] = $now;
            $ok = $old
                ? $wpdb->update(SSF_Inspections_DB::table('answers'), $answer, array('id' => $old['id'])) !== false
                : (bool) $wpdb->insert(SSF_Inspections_DB::table('answers'), array_merge($answer, array('inspection_id' => $id, 'snapshot_id' => $snapshot_id)));
        }
        $revision = (int) $locked['revision'] + ($changed ? 1 : 0);
        $ok = $ok && $wpdb->update(SSF_Inspections_DB::table('inspections'), array(
            'revision' => $revision, 'updated_at' => $now, 'last_active_section' => (int) $snapshot['section_order'], 'last_active_item' => $snapshot_id,
        ), array('id' => $id)) !== false;
        $ok = $ok && (bool) $wpdb->insert(SSF_Inspections_DB::table('operations'), array(
            'inspection_id' => $id, 'client_operation_id' => $operation_id, 'payload_hash' => $hash,
            'result_revision' => $revision, 'created_at' => $now,
        ));
        if (! $ok) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('database', 'Svaret kunde inte sparas.');
        }
        $wpdb->query('COMMIT');
        if ($changed) {
            self::event($id, 'answer_updated', array('snapshot_id' => $snapshot_id, 'revision' => $revision));
        }
        return $revision;
    }

    public static function photos(int $id, int $snapshot_id = 0): array
    {
        global $wpdb;
        $where = $snapshot_id ? $wpdb->prepare(' AND snapshot_id=%d', $snapshot_id) : '';
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id,inspection_id,snapshot_id,caption,mime_type,width,height,upload_status,created_at FROM ' . SSF_Inspections_DB::table('photos') . ' WHERE inspection_id=%d AND deleted_at IS NULL' . $where . ' ORDER BY id', $id
        ), ARRAY_A) ?: array();
    }

    public static function add_photo(int $id, int $snapshot_id, string $operation_id, array $file)
    {
        global $wpdb;
        if (! self::can_edit($id)) {
            return new WP_Error('forbidden', 'Inspektionen kan inte ändras.', array('status' => 403));
        }
        if (! wp_is_uuid($operation_id) || empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name']) || (int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
            return new WP_Error('photo', 'Ogiltig eller för stor bild.');
        }
        $snapshot = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SSF_Inspections_DB::table('snapshots') . ' WHERE id=%d AND inspection_id=%d', $snapshot_id, $id));
        if (! $snapshot) {
            return new WP_Error('snapshot', 'Bilden hör inte till inspektionen.', array('status' => 403));
        }
        $existing = $wpdb->get_row($wpdb->prepare('SELECT id,snapshot_id,deleted_at FROM ' . SSF_Inspections_DB::table('photos') . ' WHERE inspection_id=%d AND client_operation_id=%s', $id, $operation_id), ARRAY_A);
        if ($existing) {
            return (int) $existing['snapshot_id'] === $snapshot_id && ! $existing['deleted_at'] ? (int) $existing['id'] : new WP_Error('operation_conflict', 'Bildens operations-ID är redan använt.');
        }
        $info = @getimagesize($file['tmp_name']);
        if (! $info || ! in_array($info['mime'], array('image/jpeg', 'image/png', 'image/webp'), true)) {
            return new WP_Error('mime', 'Endast JPEG, PNG och WebP är tillåtna.');
        }
        $editor = wp_get_image_editor($file['tmp_name']);
        if (is_wp_error($editor)) {
            return new WP_Error('image', 'Bilden kunde inte bearbetas.');
        }
        $editor->resize(2000, 2000, false);
        $temp = wp_tempnam('ssf-inspection-photo.jpg');
        if (! $temp) {
            return new WP_Error('image', 'Bilden kunde inte bearbetas.');
        }
        $saved = $editor->save($temp, 'image/jpeg');
        if (is_wp_error($saved)) {
            @unlink($temp);
            return new WP_Error('image', 'Bilden kunde inte bearbetas.');
        }
        $bytes = file_get_contents($temp);
        @unlink($temp);
        if (! $bytes || strlen($bytes) > 8 * 1024 * 1024) {
            return new WP_Error('image', 'Den bearbetade bilden är för stor.');
        }
        $dimensions = @getimagesizefromstring($bytes);
        $ok = $wpdb->insert(SSF_Inspections_DB::table('photos'), array(
            'inspection_id' => $id, 'snapshot_id' => $snapshot_id, 'client_operation_id' => $operation_id,
            'storage_reference' => bin2hex(random_bytes(32)), 'body' => $bytes, 'caption' => '', 'mime_type' => 'image/jpeg',
            'width' => (int) ($dimensions[0] ?? 0), 'height' => (int) ($dimensions[1] ?? 0),
            'upload_status' => 'uploaded', 'created_at' => current_time('mysql', true),
        ));
        if (! $ok) {
            return new WP_Error('database', 'Bilden kunde inte sparas.');
        }
        self::event($id, 'photo_uploaded', array('photo_id' => (int) $wpdb->insert_id));
        return (int) $wpdb->insert_id;
    }

    public static function preflight(int $id): array
    {
        global $wpdb;
        $problems = array();
        foreach (self::snapshots($id) as $item) {
            if ($item['is_required'] && empty($item['assessment'])) {
                $problems[] = array('snapshot_id' => (int) $item['id'], 'message' => 'Bedömning saknas: ' . $item['title']);
            }
            if (in_array($item['assessment'], array('remark', 'serious_remark'), true) && ! trim((string) $item['comment'])) {
                $problems[] = array('snapshot_id' => (int) $item['id'], 'message' => 'Beskriv anmärkningen: ' . $item['title']);
            }
            if ('required' === $item['photo_policy'] && $item['assessment'] && ! in_array($item['assessment'], array('not_checked', 'not_applicable'), true)) {
                $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SSF_Inspections_DB::table('photos') . ' WHERE inspection_id=%d AND snapshot_id=%d AND deleted_at IS NULL AND upload_status=%s', $id, $item['id'], 'uploaded'));
                if (! $count) {
                    $problems[] = array('snapshot_id' => (int) $item['id'], 'message' => 'Bild saknas: ' . $item['title']);
                }
            }
        }
        return $problems;
    }

    public static function complete(int $id, string $assessment, string $summary, bool $confirmed)
    {
        global $wpdb;
        if (! self::can_edit($id) || ! $confirmed || ! in_array($assessment, self::FINALS, true)) {
            return new WP_Error('forbidden', 'Slutförande nekades.', array('status' => 403));
        }
        $problems = self::preflight($id);
        if ($problems) {
            return new WP_Error('preflight', 'Inspektionen är inte komplett.', array('status' => 409, 'problems' => $problems));
        }
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare('SELECT id FROM ' . SSF_Inspections_DB::table('inspections') . ' WHERE id=%d FOR UPDATE', $id));
        $row = self::inspection($id);
        if (! $row || 'in_progress' !== $row['status'] || self::preflight($id)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('conflict', 'Inspektionen har ändrats. Ladda om sammanfattningen.', array('status' => 409));
        }
        $user = wp_get_current_user();
        $now = current_time('mysql', true);
        $ok = $wpdb->update(SSF_Inspections_DB::table('inspections'), array(
            'status' => 'completed', 'final_assessment' => $assessment, 'summary' => sanitize_textarea_field($summary),
            'signed_by' => $user->ID, 'signed_name' => $user->display_name, 'signed_at' => $now,
            'signed_version_id' => $row['template_version_id'], 'signed_revision' => (int) $row['revision'] + 1,
            'revision' => (int) $row['revision'] + 1, 'submitted_at' => $now, 'completed_at' => $now, 'updated_at' => $now,
        ), array('id' => $id, 'status' => 'in_progress'));
        if (1 !== $ok) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('conflict', 'Inspektionen har ändrats. Ladda om sidan.', array('status' => 409));
        }
        if (! self::event($id, 'completed', array('revision' => (int) $row['revision'] + 1, 'assessment' => $assessment))) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('database', 'Signeringen kunde inte loggas. Inspektionen slutfördes inte.');
        }
        $wpdb->query('COMMIT');
        return true;
    }

    public static function reopen(int $id, string $reason)
    {
        global $wpdb;
        if (! self::manager() || 'completed' !== (self::inspection($id)['status'] ?? '') || '' === trim($reason)) {
            return new WP_Error('forbidden', 'Återöppning kräver administratör och anledning.', array('status' => 403));
        }
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare('SELECT id FROM ' . SSF_Inspections_DB::table('inspections') . ' WHERE id=%d FOR UPDATE', $id));
        $ok = $wpdb->query($wpdb->prepare('UPDATE ' . SSF_Inspections_DB::table('inspections') . ' SET status=%s,revision=revision+1,updated_at=%s WHERE id=%d AND status=%s', 'in_progress', current_time('mysql', true), $id, 'completed'));
        if (1 !== $ok) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('conflict', 'Inspektionen kunde inte återöppnas.', array('status' => 409));
        }
        if (! self::event($id, 'reopened', array('reason' => sanitize_textarea_field($reason)))) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('database', 'Återöppningen kunde inte loggas.');
        }
        $wpdb->query('COMMIT');
        return true;
    }
}
