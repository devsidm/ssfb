<?php
/**
 * Controlled SharePoint archive migration for membership applications.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsprocess_Archive_Migration
{
    private const OPTION = 'ssf_medlemsprocess_archive_migration';
    private const READINESS_OPTION = 'ssf_medlemsprocess_archive_migration_readiness';
    private const WRITE_TEST_OPTION = 'ssf_medlemsprocess_archive_migration_write_test';
    private const PLAN_OPTION = 'ssf_medlemsprocess_archive_migration_plan';

    private $graph;

    public function __construct()
    {
        $this->ensure_graph();
        add_action('admin_post_ssf_application_archive_save_target', array($this, 'save_target'));
        add_action('admin_post_ssf_application_archive_readiness', array($this, 'run_readiness'));
        add_action('admin_post_ssf_application_archive_write_test', array($this, 'run_write_test'));
        add_action('admin_post_ssf_application_archive_plan', array($this, 'refresh_plan'));
        add_action('admin_post_ssf_application_archive_migrate_one', array($this, 'migrate_selected'));
        add_action('admin_post_ssf_application_archive_cutover', array($this, 'activate_target'));
        add_action('admin_post_ssf_application_archive_export', array($this, 'export_plan'));
        add_action('admin_post_ssf_application_archive_restore_refs', array($this, 'restore_old_refs'));
    }

    public static function unschedule(): void
    {
    }

    public function render_page(): void
    {
        $this->require_manage();
        $target = $this->target();
        $readiness = (array) get_option(self::READINESS_OPTION, array());
        $write = (array) get_option(self::WRITE_TEST_OPTION, array());
        $plan = $this->plan(false);
        ?>
        <div class="wrap ssf-archive-migration">
            <h1>Flytta SharePoint-kataloger</h1>
            <?php $this->notice(); ?>
            <div class="ssf-process-dashboard-cards">
                <div class="ssf-process-dashboard-card"><span>Vald katalog</span><strong>Medlemsansökningar</strong></div>
                <div class="ssf-process-dashboard-card"><span>Nuvarande plats</span><strong>Medlemsgruppens SharePoint</strong></div>
                <div class="ssf-process-dashboard-card"><span>Ny plats</span><strong><?php echo esc_html((string) ($target['site_url'] ?: 'Styrelsens SharePoint')); ?><br><?php echo esc_html((string) ($target['folder_path'] ?: 'Medlemskap / Ansökningar')); ?></strong></div>
                <div class="ssf-process-dashboard-card"><span>Miljö</span><strong><?php echo esc_html(strtoupper($this->environment())); ?></strong></div>
            </div>

            <h2>1. Välj katalog</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ssf_archive_catalog">Katalog som ska flyttas</label></th>
                    <td>
                        <select id="ssf_archive_catalog" disabled>
                            <option>Medlemsansökningar</option>
                        </select>
                        <p class="description">I den här versionen flyttas endast medlemsansökningarnas arkiv. Motioner och årsmöten lämnas oförändrade.</p>
                    </td>
                </tr>
            </table>

            <h2>2. Välj ny plats</h2>
            <p>Ange SharePoint-siten, dokumentbiblioteket och sökvägen till den nya mappen. Knappen <strong>Testa ny katalog</strong> hämtar tekniska ID:n automatiskt när Graph-behörigheten räcker.</p>
            <div class="notice notice-info inline"><p><strong>Viktigt:</strong> Verktyget skapar inte SharePoint-kolumner eller Choice-värden. Skapa först mappen och metadatafält i SharePoint, gärna genom att utgå från befintlig katalogs kolumnschema. Därefter kontrollerar <strong>Testa ny katalog</strong> att alla fält och val finns.</p></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_application_archive_save_target">
                <?php wp_nonce_field('ssf_application_archive_save_target'); ?>
                <table class="form-table">
                    <?php foreach (array('site_url', 'drive_name', 'folder_path') as $key) : ?>
                        <tr><th><label for="ssf_archive_<?php echo esc_attr($key); ?>"><?php echo esc_html($this->target_fields()[$key]); ?></label></th><td><input class="regular-text" id="ssf_archive_<?php echo esc_attr($key); ?>" name="target[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) ($target[$key] ?? '')); ?>"></td></tr>
                    <?php endforeach; ?>
                </table>
                <details>
                    <summary>Avancerade tekniska uppgifter</summary>
                    <table class="form-table">
                        <?php foreach (array('site_id', 'drive_id', 'list_id', 'folder_name', 'folder_id', 'folder_web_url') as $key) : ?>
                            <tr><th><label for="ssf_archive_<?php echo esc_attr($key); ?>"><?php echo esc_html($this->target_fields()[$key]); ?></label></th><td><input class="regular-text" id="ssf_archive_<?php echo esc_attr($key); ?>" name="target[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) ($target[$key] ?? '')); ?>"></td></tr>
                        <?php endforeach; ?>
                    </table>
                </details>
                <?php submit_button('Spara ny plats', 'secondary'); ?>
            </form>

            <h2>3. Testa och migrera</h2>
            <p>Kör stegen i ordning. Ingen ny katalog aktiveras och inga ärenden flyttas automatiskt.</p>
            <p>
                <?php $this->button('ssf_application_archive_readiness', 'Testa ny katalog'); ?>
                <?php $this->button('ssf_application_archive_write_test', 'Testa skrivning'); ?>
                <?php $this->button('ssf_application_archive_plan', 'Visa migreringsplan'); ?>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_application_archive_export'), 'ssf_application_archive_export')); ?>">Exportera rapport</a>
            </p>

            <h2>4. Status</h2>
            <table class="widefat striped"><tbody>
                <?php foreach ($this->status_rows($readiness, $write, $plan) as $row) : ?>
                    <tr><th><?php echo esc_html($row[0]); ?></th><td><?php echo esc_html($row[1]); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>

            <h2>5. Migrera testärende</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_application_archive_migrate_one">
                <?php wp_nonce_field('ssf_application_archive_migrate_one'); ?>
                <select name="application_id">
                    <?php foreach ($plan['rows'] as $row) : ?>
                        <option value="<?php echo esc_attr((string) $row['id']); ?>"><?php echo esc_html($row['number'] . ' - ' . $row['vessel'] . ' (' . $row['status'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button('Migrera testärende', 'primary', 'submit', false); ?>
            </form>

            <h2>6. Aktivera för nya ansökningar</h2>
            <p>Aktiverar styrelsens SharePoint för nya medlemsansökningar först efter PASS på readiness och skrivtest. Befintliga ärenden byter inte automatiskt.</p>
            <?php $this->button('ssf_application_archive_cutover', 'Aktivera ny katalog', 'primary'); ?>

            <h2>Migreringsplan</h2>
            <?php $this->render_plan($plan); ?>

            <details><summary>Tekniska detaljer</summary><pre><?php echo esc_html(wp_json_encode(array('target' => $target, 'readiness' => $readiness, 'write_test' => $write), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
        </div>
        <?php
    }

    public function render_application_box(int $application_id): void
    {
        $status = (string) get_post_meta($application_id, '_ssf_sp_migration_status', true);
        $labels = array('running' => 'Pågår', 'completed' => 'Slutförd', 'error' => 'Fel', 'restored_old_reference' => 'Gammal referens återställd');
        echo '<hr><p><strong>Arkivplats:</strong><br>' . esc_html($this->current_matches_target($application_id) ? 'Styrelsens SharePoint' : (get_post_meta($application_id, '_ssf_sp_application_folder_id', true) ? 'Medlemssiten' : 'Inte arkiverad')) . '</p>';
        echo '<p><strong>Migrering:</strong><br>' . esc_html($labels[$status] ?? 'Ej migrerad') . '</p>';
        $verified = (string) get_post_meta($application_id, '_ssf_sp_migration_verified_at', true);
        if ($verified) { echo '<p><strong>Verifierad:</strong><br>' . esc_html($verified) . '</p>'; }
        if (get_post_meta($application_id, '_ssf_sp_migration_old_refs', true)) {
            echo '<p><strong>Gamla arkivet:</strong><br>Bevarat</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_application_archive_restore_refs"><input type="hidden" name="application_id" value="' . esc_attr((string) $application_id) . '">';
            wp_nonce_field('ssf_application_archive_restore_refs_' . $application_id);
            submit_button('Återställ gamla SharePoint-referenser', 'secondary', 'submit', false, array('onclick' => "return confirm('Peka tillbaka ärendet till gamla SharePoint-referenser? Inga filer raderas.');"));
            echo '</form>';
        }
    }

    public function save_target(): void
    {
        $this->guard('ssf_application_archive_save_target');
        $input = (array) wp_unslash($_POST['target'] ?? array());
        $target = array();
        foreach ($this->target_fields() as $key => $label) {
            $target[$key] = 'site_url' === $key || 'folder_web_url' === $key ? esc_url_raw((string) ($input[$key] ?? '')) : sanitize_text_field((string) ($input[$key] ?? ''));
        }
        $target['folder_path'] = trim((string) ($target['folder_path'] ?? ''), '/');
        $settings = $this->settings();
        $settings['targets'][$this->environment()] = array_merge($this->default_target(), $target);
        update_option(self::OPTION, $settings, false);
        $this->redirect('Målkatalogen har sparats.', 'success');
    }

    public function run_readiness(): void
    {
        $this->guard('ssf_application_archive_readiness');
        $result = $this->readiness();
        update_option(self::READINESS_OPTION, $result, false);
        $this->redirect(! empty($result['ok']) ? 'Ny katalog är verifierad.' : 'Ny katalog behöver åtgärdas innan migrering.', ! empty($result['ok']) ? 'success' : 'error');
    }

    public function run_write_test(): void
    {
        $this->guard('ssf_application_archive_write_test');
        $result = $this->write_test();
        update_option(self::WRITE_TEST_OPTION, $result, false);
        $this->redirect(! empty($result['ok']) ? 'Skrivtestet lyckades och testmappen togs bort.' : 'Skrivtestet misslyckades.', ! empty($result['ok']) ? 'success' : 'error');
    }

    public function refresh_plan(): void
    {
        $this->guard('ssf_application_archive_plan');
        update_option(self::PLAN_OPTION, $this->plan(true), false);
        $this->redirect('Migreringsplanen har uppdaterats.', 'success');
    }

    public function migrate_selected(): void
    {
        $this->guard('ssf_application_archive_migrate_one');
        $result = $this->migrate_one(absint($_POST['application_id'] ?? 0));
        $this->redirect(is_wp_error($result) ? $result->get_error_message() : 'Testärendet migrerades och verifierades.', is_wp_error($result) ? 'error' : 'success');
    }

    public function activate_target(): void
    {
        $this->guard('ssf_application_archive_cutover');
        $readiness = (array) get_option(self::READINESS_OPTION, array());
        $write = (array) get_option(self::WRITE_TEST_OPTION, array());
        if (empty($readiness['ok']) || empty($write['ok']) || ! class_exists('SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations')) {
            $this->redirect('AKTIVERING BLOCKERAD: readiness och skrivtest måste vara PASS.', 'error');
        }
        $target = $this->resolve_target(true);
        if (is_wp_error($target)) {
            $this->redirect($target->get_error_message(), 'error');
        }
        \SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations::save('membership_applications', $this->environment(), $target);
        $settings = $this->settings();
        $settings['cutover'][$this->environment()] = array('activated_at' => gmdate('c'), 'by' => get_current_user_id());
        update_option(self::OPTION, $settings, false);
        $this->redirect('Ny katalog är aktiverad för nya medlemsansökningar.', 'success');
    }

    public function export_plan(): void
    {
        $this->guard('ssf_application_archive_export');
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=ssf-medlemsansokningar-migrering-' . gmdate('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('ansöknings-ID', 'ansökningsnummer', 'fartyg', 'migreringsstatus', 'gammalt arkiv funnet', 'nytt arkiv funnet', 'antal gamla filer', 'antal nya filer', 'verifierad', 'felstatus'), ';');
        foreach ($this->plan(false)['rows'] as $row) {
            fputcsv($out, array($row['id'], $row['number'], $row['vessel'], $row['status'], $row['old_found'] ? 'ja' : 'nej', $row['new_found'] ? 'ja' : 'nej', $row['old_files'], $row['new_files'], $row['verified_at'], $row['error']), ';');
        }
        fclose($out);
        exit;
    }

    public function restore_old_refs(): void
    {
        $application_id = absint($_POST['application_id'] ?? 0);
        if (! $application_id || ! current_user_can('ssf_manage_application_settings') || ! check_admin_referer('ssf_application_archive_restore_refs_' . $application_id)) {
            wp_die('Du saknar behörighet.');
        }
        $old_refs = (array) get_post_meta($application_id, '_ssf_sp_migration_old_refs', true);
        foreach ($this->reference_keys() as $key) {
            if (array_key_exists($key, $old_refs)) { update_post_meta($application_id, $key, $old_refs[$key]); }
        }
        update_post_meta($application_id, '_ssf_sp_migration_status', 'restored_old_reference');
        SSF_Medlemsprocess_Application::add_history($application_id, 'sharepoint_migration', 'Ärendet pekades tillbaka till gamla SharePoint-referenser. Inga filer raderades.', false);
        wp_safe_redirect(get_edit_post_link($application_id, ''));
        exit;
    }

    private function readiness(): array
    {
        $target = $this->resolve_target();
        if (is_wp_error($target)) {
            return array('ok' => false, 'checked_at' => gmdate('c'), 'steps' => array('target' => $this->step('Målkatalog', $target)));
        }
        $auth = $this->graph ? $this->graph->authentication()->test() : new WP_Error('graph_unavailable', 'Microsoft Graph-klienten är inte tillgänglig.');
        $site = is_wp_error($auth) ? new WP_Error('auth_required', 'Autentisering måste fungera först.') : $this->request('GET', 'sites/' . rawurlencode((string) $target['site_id']) . '?$select=id,displayName,webUrl');
        $drive = is_wp_error($site) ? new WP_Error('site_required', 'Site måste fungera först.') : $this->request('GET', $this->drive_base($target) . '?$select=id,name,webUrl');
        $folder = is_wp_error($drive) ? new WP_Error('drive_required', 'Drive måste fungera först.') : $this->request('GET', $this->item_path($target, (string) $target['folder_id']) . '?$select=id,name,folder,parentReference,webUrl');
        $list = is_wp_error($drive) ? new WP_Error('drive_required', 'Drive måste fungera först.') : $this->request('GET', 'sites/' . rawurlencode((string) $target['site_id']) . '/lists/' . rawurlencode((string) $target['list_id']) . '?$select=id,displayName,webUrl');
        $schema = is_wp_error($list) ? new WP_Error('list_required', 'List access måste fungera först.') : $this->metadata($target);
        $steps = array('authentication' => $this->step('Microsoft-anslutning', $auth), 'site' => $this->step('Styrelsens site', $site, (string) $target['site_id']), 'drive' => $this->step('Dokumentbibliotek', $drive, (string) $target['drive_id']), 'folder' => $this->step('Mappen Ansökningar', $folder, (string) $target['folder_id']), 'list' => $this->step('List ID', $list, (string) $target['list_id']), 'metadata' => $this->step('Metadata', $schema));
        $ok = true;
        foreach ($steps as $step) { if (empty($step['ok'])) { $ok = false; } }
        return array('ok' => $ok, 'checked_at' => gmdate('c'), 'steps' => $steps, 'fields' => is_wp_error($schema) ? (array) (($schema->get_error_data()['fields'] ?? array())) : (array) ($schema['fields'] ?? array()));
    }

    private function write_test(): array
    {
        $target = $this->resolve_target();
        if (is_wp_error($target)) {
            return array('ok' => false, 'tested_at' => gmdate('c'), 'steps' => array('target' => $this->step('Målkatalog', $target)));
        }
        $name = 'SSF-TEST-' . gmdate('Ymd-His') . '-' . wp_generate_password(4, false, false);
        $created = $this->request('POST', $this->children_path($target, (string) $target['folder_id']), array('name' => $name, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'));
        $id = is_wp_error($created) ? '' : (string) ($created['id'] ?? '');
        $read = $id ? $this->request('GET', $this->item_path($target, $id) . '?$select=id,name,folder') : new WP_Error('write_test_create_required', 'Testmappen kunde inte skapas.');
        $delete = $id ? $this->request('DELETE', $this->item_path($target, $id)) : new WP_Error('write_test_delete_required', 'Ingen testmapp att ta bort.');
        $steps = array('create' => $this->step('Skapa', $created), 'read' => $this->step('Läsa', $read, $id), 'verify' => array('label' => 'Verifiera', 'ok' => ! is_wp_error($read) && $name === (string) ($read['name'] ?? '')), 'delete' => $this->step('Ta bort', $delete));
        return array('ok' => ! in_array(false, array_column($steps, 'ok'), true), 'tested_at' => gmdate('c'), 'folder_name' => $name, 'steps' => $steps);
    }

    private function migrate_one(int $application_id)
    {
        $post = get_post($application_id);
        if (! $post || SSF_Medlemsprocess_Application::POST_TYPE !== $post->post_type) { return new WP_Error('application_missing', 'Ansökan kunde inte hittas.'); }
        if (empty(get_option(self::READINESS_OPTION, array())['ok']) || empty(get_option(self::WRITE_TEST_OPTION, array())['ok'])) {
            return new WP_Error('migration_blocked', 'MIGRERING BLOCKERAD: ny katalog, metadata och skrivtest måste vara PASS.');
        }
        if (! get_post_meta($application_id, '_ssf_sp_migration_old_refs', true)) {
            update_post_meta($application_id, '_ssf_sp_migration_old_refs', $this->current_refs($application_id));
        }
        update_post_meta($application_id, '_ssf_sp_migration_status', 'running');
        $target = $this->resolve_target(true);
        if (is_wp_error($target)) {
            return $this->migration_error($application_id, $target);
        }
        $folders = $this->create_target_folders($application_id, $target);
        if (is_wp_error($folders)) { return $this->migration_error($application_id, $folders); }
        $items = $this->upload_wordpress_files($application_id, $target, $folders);
        if (is_wp_error($items)) { return $this->migration_error($application_id, $items); }
        $metadata = $this->write_metadata($application_id, $target, (string) $folders['application_folder_id']);
        if (is_wp_error($metadata)) { return $this->migration_error($application_id, $metadata); }
        $verified = $this->verify_items($target, $folders, $items);
        if (is_wp_error($verified)) { return $this->migration_error($application_id, $verified); }
        update_post_meta($application_id, '_ssf_sp_site_id', (string) $target['site_id']);
        update_post_meta($application_id, '_ssf_sp_drive_id', (string) $target['drive_id']);
        update_post_meta($application_id, '_ssf_sp_list_id', (string) $target['list_id']);
        update_post_meta($application_id, '_ssf_sp_application_folder_id', (string) $folders['application_folder_id']);
        update_post_meta($application_id, '_ssf_sp_application_web_url', esc_url_raw((string) ($folders['application_web_url'] ?? '')));
        update_post_meta($application_id, '_ssf_sp_images_folder_id', (string) $folders['images_folder_id']);
        update_post_meta($application_id, '_ssf_sp_documents_folder_id', (string) $folders['documents_folder_id']);
        update_post_meta($application_id, '_ssf_sp_application_list_item_id', sanitize_text_field((string) ($metadata['list_item_id'] ?? '')));
        update_post_meta($application_id, '_ssf_sp_items', $items);
        update_post_meta($application_id, '_ssf_sp_migration_status', 'completed');
        update_post_meta($application_id, '_ssf_sp_migration_verified_at', gmdate('c'));
        delete_post_meta($application_id, '_ssf_sp_migration_error');
        SSF_Medlemsprocess_Application::add_history($application_id, 'sharepoint_migration', 'Ansökningsarkivet kopierades och verifierades i styrelsens SharePoint. Gamla arkivet är bevarat.', false);
        update_option(self::PLAN_OPTION, $this->plan(true), false);
        return array('ok' => true);
    }

    private function create_target_folders(int $application_id, array $target)
    {
        $submitted = (string) get_post_meta($application_id, '_ssf_submitted_at', true);
        $year = $submitted ? (int) mysql2date('Y', $submitted) : (int) wp_date('Y');
        $year_folder = $this->find_or_create_folder($target, (string) $target['folder_id'], (string) $year);
        if (is_wp_error($year_folder)) { return $year_folder; }
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $number = (string) get_post_meta($application_id, '_ssf_application_number', true);
        $folder = $this->find_or_create_folder($target, (string) $year_folder['id'], sanitize_file_name($number . ' - ' . ($data['ship_name'] ?? 'Fartyg')));
        if (is_wp_error($folder)) { return $folder; }
        $images = $this->find_or_create_folder($target, (string) $folder['id'], 'Bilder');
        $documents = $this->find_or_create_folder($target, (string) $folder['id'], 'Bilagor');
        if (is_wp_error($images) || is_wp_error($documents)) { return is_wp_error($images) ? $images : $documents; }
        return array('application_folder_id' => (string) $folder['id'], 'application_web_url' => esc_url_raw((string) ($folder['webUrl'] ?? '')), 'images_folder_id' => (string) $images['id'], 'documents_folder_id' => (string) $documents['id']);
    }

    private function upload_wordpress_files(int $application_id, array $target, array $folders)
    {
        $pdf_id = (int) get_post_meta($application_id, '_ssf_application_pdf_id', true);
        if (! $pdf_id) { $pdf_id = SSF_Medlemsprocess_Plugin::instance()->pdf->create_attachment($application_id); }
        $groups = array(
            'pdf' => array($pdf_id),
            'images' => array_values(array_unique(array_filter(array_merge(array((int) get_post_meta($application_id, '_ssf_application_main_image_id', true)), array_map('absint', (array) get_post_meta($application_id, '_ssf_application_gallery_ids', true)))))),
            'documents' => array_values(array_unique(array_filter(array_merge(array_map('absint', (array) get_post_meta($application_id, '_ssf_application_document_ids', true)), array_map('absint', (array) get_post_meta($application_id, '_ssf_completion_files', true)))))),
        );
        $items = array();
        foreach ($groups as $group => $attachment_ids) {
            $folder_id = 'pdf' === $group ? $folders['application_folder_id'] : ('images' === $group ? $folders['images_folder_id'] : $folders['documents_folder_id']);
            foreach ($attachment_ids as $attachment_id) {
                if (! $attachment_id) { continue; }
                $uploaded = $this->upload_attachment($target, (int) $attachment_id, (string) $folder_id);
                if (is_wp_error($uploaded)) { return $uploaded; }
                $items[$attachment_id] = array('status' => 'synced', 'group' => $group, 'drive_item_id' => (string) ($uploaded['id'] ?? ''), 'web_url' => esc_url_raw((string) ($uploaded['webUrl'] ?? '')), 'filename' => sanitize_file_name((string) ($uploaded['name'] ?? '')), 'uploaded_at' => gmdate('c'));
            }
        }
        return $items;
    }

    private function write_metadata(int $application_id, array $target, string $folder_id)
    {
        $list_item = $this->list_item($target, $folder_id);
        if (is_wp_error($list_item)) { return $list_item; }
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $submitted = (string) get_post_meta($application_id, '_ssf_submitted_at', true);
        $values = array('wordpress_id' => (string) $application_id, 'number' => (string) get_post_meta($application_id, '_ssf_application_number', true), 'vessel' => (string) ($data['ship_name'] ?? ''), 'received' => $submitted ? gmdate('Y-m-d', strtotime($submitted)) : gmdate('Y-m-d'), 'route' => (string) ($data['application_path'] ?? ''), 'status' => $this->sharepoint_status(SSF_Medlemsprocess_Application::status($application_id)), 'membership_status' => SSF_Medlemsprocess_Application::membership_status_label(SSF_Medlemsprocess_Application::membership_status($application_id)), 'decision_date' => (string) get_post_meta($application_id, '_ssf_decision_date', true), 'aspirant_start' => (string) get_post_meta($application_id, '_ssf_aspirant_started_at', true), 'aspirant_review' => (string) get_post_meta($application_id, '_ssf_aspirant_review_due_at', true));
        $patched = $this->request('PATCH', 'sites/' . rawurlencode((string) $target['site_id']) . '/lists/' . rawurlencode((string) $target['list_id']) . '/items/' . rawurlencode((string) ($list_item['id'] ?? '')) . '/fields', array_filter($this->metadata_fields($values), static function ($value, $key) { return '' !== (string) $key && '' !== (string) $value; }, ARRAY_FILTER_USE_BOTH));
        return is_wp_error($patched) ? $patched : array('list_item_id' => sanitize_text_field((string) ($list_item['id'] ?? '')));
    }

    private function verify_items(array $target, array $folders, array $items)
    {
        $folder = $this->request('GET', $this->item_path($target, (string) $folders['application_folder_id']) . '?$select=id,name,folder');
        if (is_wp_error($folder)) { return $folder; }
        foreach ($items as $item) {
            $remote = $this->request('GET', $this->item_path($target, (string) ($item['drive_item_id'] ?? '')) . '?$select=id,name,size,file');
            if (is_wp_error($remote)) { return $remote; }
            if ((int) ($remote['size'] ?? 0) <= 0) { return new WP_Error('migration_file_empty', 'En migrerad fil kunde inte verifieras eller är tom.'); }
        }
        return array('ok' => true);
    }

    private function plan(bool $refresh): array
    {
        if (! $refresh) {
            $stored = (array) get_option(self::PLAN_OPTION, array());
            if (! empty($stored['rows'])) { return $stored; }
        }
        $ids = get_posts(array('post_type' => SSF_Medlemsprocess_Application::POST_TYPE, 'post_status' => 'private', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC'));
        $rows = array();
        foreach ($ids as $id) { $rows[] = $this->plan_row((int) $id); }
        $summary = array('wordpress' => count($rows), 'old_found' => 0, 'migrated' => 0, 'waiting' => 0, 'errors' => 0, 'unverified' => 0);
        foreach ($rows as $row) {
            if ($row['old_found']) { ++$summary['old_found']; }
            if ('MIGRATED' === $row['status']) { ++$summary['migrated']; }
            if ('ERROR' === $row['status']) { ++$summary['errors']; }
            if (in_array($row['status'], array('READY', 'READY FROM WORDPRESS', 'VERIFY TARGET'), true)) { ++$summary['waiting']; }
            if ('MIGRATED' === $row['status'] && ! $row['verified_at']) { ++$summary['unverified']; }
        }
        return array('generated_at' => gmdate('c'), 'summary' => $summary, 'rows' => $rows);
    }

    private function plan_row(int $application_id): array
    {
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $migration_status = (string) get_post_meta($application_id, '_ssf_sp_migration_status', true);
        $old_found = (bool) get_post_meta($application_id, '_ssf_sp_application_folder_id', true);
        $new_found = 'completed' === $migration_status || $this->current_matches_target($application_id);
        $verified_at = (string) get_post_meta($application_id, '_ssf_sp_migration_verified_at', true);
        $error = (string) get_post_meta($application_id, '_ssf_sp_migration_error', true);
        $status = $error ? 'ERROR' : ($new_found && $verified_at ? 'MIGRATED' : ($new_found ? 'VERIFY TARGET' : ($old_found ? 'READY' : 'READY FROM WORDPRESS')));
        return array('id' => $application_id, 'number' => (string) get_post_meta($application_id, '_ssf_application_number', true), 'vessel' => (string) ($data['ship_name'] ?? get_the_title($application_id)), 'status' => $status, 'old_found' => $old_found, 'new_found' => $new_found, 'old_files' => count((array) get_post_meta($application_id, '_ssf_sp_items', true)), 'new_files' => $new_found ? count((array) get_post_meta($application_id, '_ssf_sp_items', true)) : 0, 'verified_at' => $verified_at, 'error' => $error);
    }

    private function render_plan(array $plan): void
    {
        $summary = (array) ($plan['summary'] ?? array());
        echo '<p>Ansökningar i WordPress: ' . esc_html((string) ($summary['wordpress'] ?? 0)) . ' · Finns i gammalt arkiv: ' . esc_html((string) ($summary['old_found'] ?? 0)) . ' · Redan migrerade: ' . esc_html((string) ($summary['migrated'] ?? 0)) . ' · Väntar: ' . esc_html((string) ($summary['waiting'] ?? 0)) . ' · Fel: ' . esc_html((string) ($summary['errors'] ?? 0)) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>Ansökan</th><th>Fartyg</th><th>WordPress</th><th>Gammalt arkiv</th><th>Nytt arkiv</th><th>Filer</th><th>Status</th></tr></thead><tbody>';
        foreach ((array) ($plan['rows'] ?? array()) as $row) {
            echo '<tr><td>' . esc_html($row['number']) . '</td><td>' . esc_html($row['vessel']) . '</td><td>FOUND</td><td>' . esc_html($row['old_found'] ? 'FOUND' : 'NOT FOUND') . '</td><td>' . esc_html($row['new_found'] ? 'FOUND' : 'NOT FOUND') . '</td><td>' . esc_html((string) $row['old_files']) . '</td><td>' . esc_html($row['status']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function metadata(array $target)
    {
        $columns = $this->request('GET', 'sites/' . rawurlencode((string) $target['site_id']) . '/lists/' . rawurlencode((string) $target['list_id']) . '/columns?$select=id,name,displayName,choice,text,dateTime');
        if (is_wp_error($columns)) { return $columns; }
        $existing = array();
        foreach ((array) ($columns['value'] ?? array()) as $column) { $existing[strtolower((string) ($column['name'] ?? ''))] = $column; }
        $fields = array();
        $missing = array();
        foreach ($this->schema_requirements() as $key => $requirement) {
            $name = (string) ($this->metadata_config()[$key] ?? '');
            $column = $name ? ($existing[strtolower($name)] ?? null) : null;
            $missing_choices = $column && ! empty($requirement['choices']) ? array_values(array_diff($requirement['choices'], (array) ($column['choice']['choices'] ?? array()))) : array();
            $type_ok = (bool) $column && array_key_exists($requirement['type'], $column);
            $ok = $type_ok && ! $missing_choices;
            $fields[$key] = array('label' => $requirement['label'], 'name' => $name, 'ok' => $ok, 'missing_choices' => $missing_choices);
            if (! $ok) { $missing[] = $name ?: $requirement['label']; }
        }
        $result = array('ok' => ! $missing, 'fields' => $fields, 'missing' => $missing);
        return $missing ? new WP_Error('migration_metadata_incomplete', 'MIGRERING BLOCKERAD: metadatafält saknas eller har fel val: ' . implode(', ', $missing) . '.', $result) : $result;
    }

    private function status_rows(array $readiness, array $write, array $plan): array
    {
        $steps = (array) ($readiness['steps'] ?? array());
        $summary = (array) ($plan['summary'] ?? array());
        return array(
            array('Microsoft-anslutning', $this->status_label($steps['authentication']['ok'] ?? null)),
            array('Styrelsens site', $this->status_label($steps['site']['ok'] ?? null)),
            array('Dokumentbibliotek', $this->status_label($steps['drive']['ok'] ?? null)),
            array('Mappen Medlemskap', $this->target()['folder_path'] ? 'KONFIGURERAD' : 'EJ TESTAD'),
            array('Mappen Ansökningar', $this->status_label($steps['folder']['ok'] ?? null)),
            array('Metadata', $this->status_label($steps['metadata']['ok'] ?? null)),
            array('Skrivtest', $this->status_label($write['ok'] ?? null)),
            array('Ansökningar i WordPress', (string) ($summary['wordpress'] ?? 0)),
            array('Finns i gammalt arkiv', (string) ($summary['old_found'] ?? 0)),
            array('Redan migrerade', (string) ($summary['migrated'] ?? 0)),
            array('Väntar', (string) ($summary['waiting'] ?? 0)),
            array('Fel', (string) ($summary['errors'] ?? 0)),
        );
    }

    private function target_fields(): array
    {
        return array('site_url' => 'SharePoint Site URL', 'site_id' => 'Site ID', 'drive_name' => 'Bibliotek', 'drive_id' => 'Drive ID', 'list_id' => 'List ID', 'folder_path' => 'Mappsökväg', 'folder_name' => 'Mappnamn', 'folder_id' => 'Mappens DriveItem ID', 'folder_web_url' => 'Mappens webbadress');
    }

    private function default_target(): array
    {
        return array('site_url' => 'https://tradtionsfartyg.sharepoint.com/sites/styrelsen9', 'site_id' => '', 'drive_name' => 'Dokument', 'drive_id' => '', 'list_id' => '', 'folder_path' => 'General/Medlemskap/Ansökningar', 'folder_name' => 'Ansökningar', 'folder_id' => '', 'folder_web_url' => '');
    }

    private function target(): array
    {
        $settings = $this->settings();
        return array_merge($this->default_target(), (array) ($settings['targets'][$this->environment()] ?? array()));
    }

    private function resolve_target(bool $save = false)
    {
        $target = $this->target();
        if (empty($target['site_id'])) {
            $site_path = $this->site_lookup_path((string) ($target['site_url'] ?? ''));
            if (! $site_path) {
                return new WP_Error('migration_target_site_url_missing', 'Ange en giltig SharePoint Site URL.');
            }
            $site = $this->request('GET', $site_path . '?$select=id,displayName,webUrl');
            if (is_wp_error($site)) { return $site; }
            $target['site_id'] = sanitize_text_field((string) ($site['id'] ?? ''));
            $target['site_url'] = esc_url_raw((string) ($site['webUrl'] ?? $target['site_url']));
        }
        if (empty($target['drive_id'])) {
            $drives = $this->request('GET', 'sites/' . rawurlencode((string) $target['site_id']) . '/drives?$select=id,name,webUrl');
            if (is_wp_error($drives)) { return $drives; }
            foreach ((array) ($drives['value'] ?? array()) as $drive) {
                if (0 === strcasecmp((string) ($target['drive_name'] ?: 'Dokument'), (string) ($drive['name'] ?? ''))) {
                    $target['drive_id'] = sanitize_text_field((string) ($drive['id'] ?? ''));
                    break;
                }
            }
            if (empty($target['drive_id'])) {
                return new WP_Error('migration_target_drive_missing', 'Dokumentbiblioteket kunde inte hittas.');
            }
        }
        if (empty($target['list_id'])) {
            $list = $this->request('GET', $this->drive_base($target) . '/list?$select=id,displayName,webUrl');
            if (is_wp_error($list)) { return $list; }
            $target['list_id'] = sanitize_text_field((string) ($list['id'] ?? ''));
        }
        if (empty($target['folder_id'])) {
            $folder_path = $this->encode_drive_path((string) ($target['folder_path'] ?? ''));
            if (! $folder_path) {
                return new WP_Error('migration_target_folder_path_missing', 'Ange mappsökvägen till Medlemskap / Ansökningar.');
            }
            $folder = $this->request('GET', $this->drive_base($target) . '/root:/' . $folder_path . '?$select=id,name,folder,webUrl,parentReference');
            if (is_wp_error($folder)) { return $this->friendly_folder_error($folder, $target); }
            if (empty($folder['folder'])) {
                return new WP_Error('migration_target_not_folder', 'Målplatsen är inte en SharePoint-mapp.');
            }
            $target['folder_id'] = sanitize_text_field((string) ($folder['id'] ?? ''));
            $target['folder_name'] = sanitize_text_field((string) ($folder['name'] ?? $target['folder_name']));
            $target['folder_web_url'] = esc_url_raw((string) ($folder['webUrl'] ?? ''));
        }
        if ($save) {
            $settings = $this->settings();
            $settings['targets'][$this->environment()] = $target;
            update_option(self::OPTION, $settings, false);
        }
        return $target;
    }

    private function settings(): array
    {
        return (array) get_option(self::OPTION, array());
    }

    private function metadata_config(): array
    {
        if (class_exists('SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations')) {
            $profile = \SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations::get('membership_applications');
            return (array) ($profile['metadata'] ?? array());
        }
        return array('wordpress_id' => 'WordPressApplicationID', 'number' => 'ApplicationNumber', 'vessel' => 'VesselName', 'route' => 'ApplicationPath', 'status' => 'ApplicationStatus', 'membership_status' => 'MembershipStatus', 'received' => 'ReceivedDate', 'decision_date' => 'DecisionDate', 'aspirant_start' => 'AspirantStartDate', 'aspirant_review' => 'AspirantReviewDate', 'public_comment' => 'ExternStatuskommentar');
    }

    private function metadata_fields(array $values): array
    {
        $fields = array();
        foreach ($values as $key => $value) {
            $name = (string) ($this->metadata_config()[$key] ?? '');
            if ($name) { $fields[$name] = $value; }
        }
        return $fields;
    }

    private function schema_requirements(): array
    {
        return array(
            'number' => array('label' => 'Ansökningsnummer', 'type' => 'text', 'choices' => array()),
            'vessel' => array('label' => 'Fartyg', 'type' => 'text', 'choices' => array()),
            'route' => array('label' => 'Ansökningsväg', 'type' => 'choice', 'choices' => array('Normalfallet', 'Mindre registrerat fartyg', 'Fartyg under restaurering', 'Nybyggt traditionsfartyg')),
            'status' => array('label' => 'Ansökningsstatus', 'type' => 'choice', 'choices' => array_values($this->status_labels())),
            'membership_status' => array('label' => 'Medlemsstatus', 'type' => 'choice', 'choices' => array_values(SSF_Medlemsprocess_Application::membership_statuses())),
            'received' => array('label' => 'Inkommet datum', 'type' => 'dateTime', 'choices' => array()),
            'decision_date' => array('label' => 'Beslutsdatum', 'type' => 'dateTime', 'choices' => array()),
            'aspirant_start' => array('label' => 'Aspirant från', 'type' => 'dateTime', 'choices' => array()),
            'aspirant_review' => array('label' => 'Aspirant uppföljning', 'type' => 'dateTime', 'choices' => array()),
            'wordpress_id' => array('label' => 'WordPress-ID', 'type' => 'text', 'choices' => array()),
        );
    }

    private function current_refs(int $application_id): array
    {
        $refs = array();
        foreach ($this->reference_keys() as $key) { $refs[$key] = get_post_meta($application_id, $key, true); }
        return $refs;
    }

    private function reference_keys(): array
    {
        return array('_ssf_sp_site_id', '_ssf_sp_drive_id', '_ssf_sp_list_id', '_ssf_sp_application_folder_id', '_ssf_sp_application_web_url', '_ssf_sp_images_folder_id', '_ssf_sp_documents_folder_id', '_ssf_sp_application_list_item_id', '_ssf_sp_items', '_ssf_sp_pdf_drive_item_id', '_ssf_sp_pdf_web_url', '_ssf_sp_pdf_list_item_id');
    }

    private function current_matches_target(int $application_id): bool
    {
        $target = $this->target();
        return $target['site_id'] && 0 === strcasecmp((string) get_post_meta($application_id, '_ssf_sp_site_id', true), (string) $target['site_id']) && $target['drive_id'] && 0 === strcasecmp((string) get_post_meta($application_id, '_ssf_sp_drive_id', true), (string) $target['drive_id']);
    }

    private function migration_error(int $application_id, WP_Error $error): WP_Error
    {
        update_post_meta($application_id, '_ssf_sp_migration_status', 'error');
        update_post_meta($application_id, '_ssf_sp_migration_error', $error->get_error_message());
        SSF_Medlemsprocess_Application::add_history($application_id, 'sharepoint_migration_error', 'SharePoint-migreringen misslyckades. Gamla referenser är bevarade.', false);
        update_option(self::PLAN_OPTION, $this->plan(true), false);
        return $error;
    }

    private function find_or_create_folder(array $target, string $parent_id, string $name)
    {
        $children = $this->request('GET', $this->children_path($target, $parent_id) . '?$select=id,name,folder,webUrl');
        if (is_wp_error($children)) { return $children; }
        foreach ((array) ($children['value'] ?? array()) as $child) {
            if (isset($child['folder']) && 0 === strcasecmp($name, (string) ($child['name'] ?? ''))) { return $child; }
        }
        return $this->request('POST', $this->children_path($target, $parent_id), array('name' => $name, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'));
    }

    private function upload_attachment(array $target, int $attachment_id, string $folder_id)
    {
        $file = get_attached_file($attachment_id);
        if (! $file || ! is_readable($file)) { return new WP_Error('migration_attachment_missing', 'En ansökningsfil kunde inte läsas från WordPress.'); }
        $content = file_get_contents($file);
        if (false === $content) { return new WP_Error('migration_attachment_read', 'En ansökningsfil kunde inte läsas.'); }
        return $this->request('PUT', $this->item_path($target, $folder_id) . ':/' . rawurlencode(sanitize_file_name(basename($file))) . ':/content', $content, array('Content-Type' => get_post_mime_type($attachment_id) ?: 'application/octet-stream'));
    }

    private function list_item(array $target, string $drive_item_id)
    {
        $result = null;
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $result = $this->request('GET', $this->item_path($target, $drive_item_id) . '/listItem?$expand=fields');
            if (! is_wp_error($result) && ! empty($result['id'])) { return $result; }
            if ($attempt < 3) { usleep((250 + ($attempt * 250)) * 1000); }
        }
        return is_wp_error($result) ? $result : new WP_Error('migration_list_item_missing', 'SharePoint returnerade inget ListItem-ID för målmappen.');
    }

    private function step(string $label, $result, string $expected_id = ''): array
    {
        if (is_wp_error($result)) { return array_merge(array('label' => $label, 'ok' => false, 'message' => $result->get_error_message()), $this->error_details($result)); }
        $ok = ! $expected_id || 0 === strcasecmp($expected_id, (string) ($result['id'] ?? ''));
        return array('label' => $label, 'ok' => $ok, 'message' => $ok ? '' : 'ID matchar inte konfigurationen.');
    }

    private function status_label($ok): string
    {
        if (true === $ok) { return 'PASS'; }
        if (false === $ok) { return 'FAIL'; }
        return 'EJ TESTAD';
    }

    private function sharepoint_status(string $status): string
    {
        return $this->status_labels()[$status] ?? SSF_Medlemsprocess_Application::status_label($status);
    }

    private function status_labels(): array
    {
        return array('received' => 'Inkommen', 'under_review' => 'Under granskning', 'needs_completion' => 'Begär komplettering', 'awaiting_completion' => 'Väntar på komplettering', 'inspection_planned' => 'Inspektion ska bokas', 'inspection_booked' => 'Inspektion bokad', 'awaiting_decision' => 'Under slutbedömning', 'approved_aspirant' => 'Godkänd som aspirant', 'rejected' => 'Avslagen');
    }

    private function environment(): string
    {
        return function_exists('wp_get_environment_type') && 'production' === wp_get_environment_type() ? 'production' : 'development';
    }

    private function request(string $method, string $path, $body = null, array $headers = array())
    {
        $this->ensure_graph();
        return $this->graph ? $this->graph->request($method, $path, $body, $headers) : new WP_Error('graph_unavailable', 'Microsoft Graph-klienten är inte tillgänglig.');
    }

    private function ensure_graph(): void
    {
        if (! $this->graph && class_exists('SSF\\MemberPortal\\Integrations\\Microsoft365\\GraphClient') && class_exists('SSF\\MemberPortal\\Integrations\\Microsoft365\\Authentication')) {
            $this->graph = new \SSF\MemberPortal\Integrations\Microsoft365\GraphClient(new \SSF\MemberPortal\Integrations\Microsoft365\Authentication());
        }
    }

    private function drive_base(array $target): string
    {
        return 'drives/' . rawurlencode((string) $target['drive_id']);
    }

    private function item_path(array $target, string $item_id): string
    {
        return $this->drive_base($target) . '/items/' . rawurlencode($item_id);
    }

    private function children_path(array $target, string $folder_id): string
    {
        return $this->item_path($target, $folder_id) . '/children';
    }

    private function site_lookup_path(string $site_url): string
    {
        $parts = wp_parse_url($site_url);
        if (empty($parts['host']) || empty($parts['path'])) {
            return '';
        }
        return 'sites/' . rawurlencode(strtolower((string) $parts['host'])) . ':/' . ltrim((string) $parts['path'], '/');
    }

    private function encode_drive_path(string $path): string
    {
        $segments = array_filter(array_map('trim', explode('/', trim($path, '/'))), static function ($segment): bool { return '' !== $segment; });
        return implode('/', array_map('rawurlencode', $segments));
    }

    private function friendly_folder_error(WP_Error $error, array $target): WP_Error
    {
        $data = (array) $error->get_error_data();
        $status = (int) ($data['http_status'] ?? $data['status'] ?? 0);
        if (404 === $status || 'itemnotfound' === strtolower((string) ($data['graph_code'] ?? ''))) {
            return new WP_Error(
                'migration_target_folder_missing',
                'Målmappen hittades inte. Skapa mappen i SharePoint först: ' . (string) ($target['folder_path'] ?? '') . '. Verktyget skapar inte katalogstruktur, kolumner eller Choice-värden automatiskt.',
                $data
            );
        }
        return $error;
    }

    private function error_details(WP_Error $error): array
    {
        $data = (array) $error->get_error_data();
        return array('http_status' => (int) ($data['http_status'] ?? $data['status'] ?? 0), 'graph_code' => sanitize_text_field((string) ($data['graph_code'] ?? $error->get_error_code())));
    }

    private function guard(string $nonce): void
    {
        $this->require_manage();
        check_admin_referer($nonce);
    }

    private function require_manage(): void
    {
        if (! current_user_can('ssf_manage_application_settings')) { wp_die('Du saknar behörighet.'); }
    }

    private function button(string $action, string $label, string $class = 'secondary'): void
    {
        echo '<a class="button button-' . esc_attr($class) . '" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . $action), $action)) . '">' . esc_html($label) . '</a> ';
    }

    private function redirect(string $message, string $type): void
    {
        wp_safe_redirect(add_query_arg(array('page' => 'ssf-application-archive-migration', 'ssf_archive_message' => rawurlencode($message), 'ssf_archive_type' => $type), admin_url('admin.php')));
        exit;
    }

    private function notice(): void
    {
        $message = sanitize_text_field((string) wp_unslash($_GET['ssf_archive_message'] ?? ''));
        if (! $message) { return; }
        $type = 'error' === sanitize_key((string) ($_GET['ssf_archive_type'] ?? '')) ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
    }
}
