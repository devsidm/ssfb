<?php
if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Inspections_Templates
{
    public static function draft(int $template_id, int $version_id): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SSF_Inspections_DB::table('versions') . ' WHERE id=%d AND template_id=%d AND status=%s', $version_id, $template_id, 'draft'));
    }

    public static function create(string $name)
    {
        global $wpdb;
        if (! SSF_Inspections_Core::manager() || '' === trim($name)) {
            return new WP_Error('forbidden', 'Mallen kunde inte skapas.', array('status' => 403));
        }
        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->insert(SSF_Inspections_DB::table('templates'), array('name' => sanitize_text_field($name), 'status' => 'active', 'created_by' => get_current_user_id(), 'created_at' => current_time('mysql', true)));
        $template_id = (int) $wpdb->insert_id;
        $ok = $ok && $wpdb->insert(SSF_Inspections_DB::table('versions'), array('template_id' => $template_id, 'version' => 1, 'status' => 'draft'));
        if (! $ok) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('database', 'Mallen kunde inte skapas.');
        }
        $version_id = (int) $wpdb->insert_id;
        $wpdb->query('COMMIT');
        return array('template_id' => $template_id, 'version_id' => $version_id);
    }

    public static function add_section(int $template_id, int $version_id, string $title)
    {
        global $wpdb;
        if (! SSF_Inspections_Core::manager() || ! self::draft($template_id, $version_id) || '' === trim($title)) {
            return new WP_Error('forbidden', 'Endast utkast får ändras.', array('status' => 403));
        }
        $order = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . SSF_Inspections_DB::table('sections') . ' WHERE version_id=%d', $version_id));
        $ok = $wpdb->insert(SSF_Inspections_DB::table('sections'), array('version_id' => $version_id, 'section_key' => wp_generate_uuid4(), 'title' => sanitize_text_field($title), 'sort_order' => $order));
        return $ok ? (int) $wpdb->insert_id : new WP_Error('database', 'Sektionen kunde inte sparas.');
    }

    public static function add_item(int $template_id, int $version_id, int $section_id, array $data)
    {
        global $wpdb;
        if (! SSF_Inspections_Core::manager() || ! self::draft($template_id, $version_id) || '' === trim((string) ($data['title'] ?? ''))) {
            return new WP_Error('forbidden', 'Endast utkast får ändras.', array('status' => 403));
        }
        $section = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SSF_Inspections_DB::table('sections') . ' WHERE id=%d AND version_id=%d', $section_id, $version_id));
        if (! $section) {
            return new WP_Error('section', 'Sektionen hör inte till utkastet.');
        }
        $order = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . SSF_Inspections_DB::table('items') . ' WHERE section_id=%d', $section_id));
        $photo_policy = in_array($data['photo_policy'] ?? '', array('optional', 'required', 'none'), true) ? $data['photo_policy'] : 'optional';
        $ok = $wpdb->insert(SSF_Inspections_DB::table('items'), array(
            'section_id' => $section_id, 'item_key' => wp_generate_uuid4(), 'title' => sanitize_text_field($data['title']),
            'help_text' => sanitize_textarea_field($data['help_text'] ?? ''), 'is_required' => ! empty($data['required']) ? 1 : 0,
            'photo_policy' => $photo_policy, 'sort_order' => $order,
        ));
        return $ok ? (int) $wpdb->insert_id : new WP_Error('database', 'Kontrollpunkten kunde inte sparas.');
    }

    public static function publish(int $template_id, int $version_id)
    {
        global $wpdb;
        if (! SSF_Inspections_Core::manager() || ! self::draft($template_id, $version_id)) {
            return new WP_Error('forbidden', 'Endast utkast får publiceras.', array('status' => 403));
        }
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SSF_Inspections_DB::table('items') . ' i INNER JOIN ' . SSF_Inspections_DB::table('sections') . ' s ON s.id=i.section_id WHERE s.version_id=%d', $version_id));
        if (! $count) {
            return new WP_Error('empty', 'Lägg till minst en kontrollpunkt.');
        }
        $ok = $wpdb->update(SSF_Inspections_DB::table('versions'), array('status' => 'published', 'published_at' => current_time('mysql', true)), array('id' => $version_id, 'template_id' => $template_id, 'status' => 'draft'));
        return 1 === $ok ? true : new WP_Error('conflict', 'Mallversionen kunde inte publiceras.');
    }

    public static function duplicate(int $template_id, int $source_version_id)
    {
        global $wpdb;
        if (! SSF_Inspections_Core::manager()) {
            return new WP_Error('forbidden', 'Ingen behörighet.', array('status' => 403));
        }
        $source = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('versions') . ' WHERE id=%d AND template_id=%d AND status=%s', $source_version_id, $template_id, 'published'), ARRAY_A);
        if (! $source) {
            return new WP_Error('source', 'Välj en publicerad version.');
        }
        $wpdb->query('START TRANSACTION');
        $next = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(version),0)+1 FROM ' . SSF_Inspections_DB::table('versions') . ' WHERE template_id=%d', $template_id));
        $ok = $wpdb->insert(SSF_Inspections_DB::table('versions'), array('template_id' => $template_id, 'version' => $next, 'status' => 'draft'));
        $new_id = (int) $wpdb->insert_id;
        $sections = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('sections') . ' WHERE version_id=%d ORDER BY sort_order,id', $source_version_id), ARRAY_A);
        foreach ($sections as $section) {
            $ok = $ok && $wpdb->insert(SSF_Inspections_DB::table('sections'), array('version_id' => $new_id, 'section_key' => $section['section_key'], 'title' => $section['title'], 'sort_order' => $section['sort_order']));
            $new_section_id = (int) $wpdb->insert_id;
            $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('items') . ' WHERE section_id=%d ORDER BY sort_order,id', $section['id']), ARRAY_A);
            foreach ($items as $item) {
                $ok = $ok && $wpdb->insert(SSF_Inspections_DB::table('items'), array(
                    'section_id' => $new_section_id, 'item_key' => $item['item_key'], 'title' => $item['title'],
                    'help_text' => $item['help_text'], 'is_required' => $item['is_required'],
                    'photo_policy' => $item['photo_policy'], 'sort_order' => $item['sort_order'],
                ));
            }
        }
        if (! $ok) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('database', 'Versionen kunde inte dupliceras.');
        }
        $wpdb->query('COMMIT');
        return $new_id;
    }

    public static function archive(int $template_id)
    {
        global $wpdb;
        if (! SSF_Inspections_Core::manager()) {
            return new WP_Error('forbidden', 'Ingen behörighet.', array('status' => 403));
        }
        return $wpdb->update(SSF_Inspections_DB::table('templates'), array('status' => 'archived'), array('id' => $template_id)) !== false;
    }
}
