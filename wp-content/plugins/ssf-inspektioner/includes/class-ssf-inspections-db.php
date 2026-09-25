<?php
if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Inspections_DB
{
    private const TABLES = array('templates', 'versions', 'sections', 'items', 'inspections', 'snapshots', 'answers', 'photos', 'events', 'operations');

    public static function table(string $name): string
    {
        if (! in_array($name, self::TABLES, true)) {
            throw new InvalidArgumentException('Unknown inspection table.');
        }
        global $wpdb;
        return $wpdb->prefix . 'ssf_inspection_' . $name;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = array(
            'templates' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL, status varchar(20) NOT NULL DEFAULT \'active\', created_by bigint(20) unsigned NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id)',
            'versions' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, template_id bigint(20) unsigned NOT NULL, version int unsigned NOT NULL, status varchar(20) NOT NULL DEFAULT \'draft\', published_at datetime NULL, PRIMARY KEY  (id), UNIQUE KEY template_version (template_id,version), KEY status (status)',
            'sections' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, version_id bigint(20) unsigned NOT NULL, section_key varchar(100) NOT NULL, title varchar(190) NOT NULL, sort_order int NOT NULL DEFAULT 0, PRIMARY KEY  (id), KEY version_id (version_id)',
            'items' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, section_id bigint(20) unsigned NOT NULL, item_key varchar(100) NOT NULL, title varchar(190) NOT NULL, help_text text NULL, is_required tinyint(1) NOT NULL DEFAULT 1, photo_policy varchar(20) NOT NULL DEFAULT \'optional\', sort_order int NOT NULL DEFAULT 0, PRIMARY KEY  (id), KEY section_id (section_id)',
            'inspections' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, ship_id bigint(20) unsigned NOT NULL, template_version_id bigint(20) unsigned NOT NULL, inspector_user_id bigint(20) unsigned NOT NULL, inspection_type varchar(100) NOT NULL, inspection_date date NOT NULL, location varchar(190) NOT NULL DEFAULT \'\', representative varchar(190) NOT NULL DEFAULT \'\', status varchar(20) NOT NULL DEFAULT \'in_progress\', last_active_section bigint(20) unsigned NULL, last_active_item bigint(20) unsigned NULL, parent_inspection_id bigint(20) unsigned NULL, final_assessment varchar(40) NULL, summary text NULL, revision int unsigned NOT NULL DEFAULT 1, signed_by bigint(20) unsigned NULL, signed_name varchar(190) NULL, signed_at datetime NULL, signed_version_id bigint(20) unsigned NULL, signed_revision int unsigned NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, submitted_at datetime NULL, completed_at datetime NULL, PRIMARY KEY  (id), KEY inspector_status (inspector_user_id,status), KEY ship_id (ship_id), KEY parent_inspection_id (parent_inspection_id)',
            'snapshots' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, inspection_id bigint(20) unsigned NOT NULL, source_item_id bigint(20) unsigned NOT NULL, section_key varchar(100) NOT NULL, section_title varchar(190) NOT NULL, section_order int NOT NULL, item_key varchar(100) NOT NULL, title varchar(190) NOT NULL, help_text text NULL, is_required tinyint(1) NOT NULL DEFAULT 1, photo_policy varchar(20) NOT NULL DEFAULT \'optional\', item_order int NOT NULL, PRIMARY KEY  (id), UNIQUE KEY inspection_item (inspection_id,source_item_id), KEY section_order (inspection_id,section_order,item_order)',
            'answers' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, inspection_id bigint(20) unsigned NOT NULL, snapshot_id bigint(20) unsigned NOT NULL, assessment varchar(30) NOT NULL, comment text NULL, proposed_action text NULL, priority varchar(12) NULL, follow_up_status varchar(20) NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY inspection_snapshot (inspection_id,snapshot_id)',
            'photos' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, inspection_id bigint(20) unsigned NOT NULL, snapshot_id bigint(20) unsigned NOT NULL, client_operation_id varchar(64) NOT NULL, storage_reference char(64) NOT NULL, body longblob NOT NULL, caption varchar(250) NOT NULL DEFAULT \'\', mime_type varchar(30) NOT NULL, width int unsigned NOT NULL, height int unsigned NOT NULL, upload_status varchar(20) NOT NULL DEFAULT \'uploaded\', created_at datetime NOT NULL, deleted_at datetime NULL, PRIMARY KEY  (id), UNIQUE KEY inspection_operation (inspection_id,client_operation_id), UNIQUE KEY storage_reference (storage_reference), KEY snapshot_id (snapshot_id)',
            'events' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, inspection_id bigint(20) unsigned NOT NULL, actor_user_id bigint(20) unsigned NOT NULL, event_type varchar(60) NOT NULL, details text NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY inspection_id (inspection_id)',
            'operations' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, inspection_id bigint(20) unsigned NOT NULL, client_operation_id varchar(64) NOT NULL, payload_hash char(64) NOT NULL, result_revision int unsigned NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY inspection_operation (inspection_id,client_operation_id)',
        );
        foreach ($sql as $name => $columns) {
            dbDelta('CREATE TABLE ' . self::table($name) . " ($columns) $charset;");
        }
        update_option('ssf_inspections_schema_version', SSF_INSPECTIONS_VERSION, false);
    }
}
