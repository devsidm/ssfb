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
    private const SOURCE_SCHEMA_OPTION = 'ssf_medlemsprocess_archive_source_schema';
    private const SCHEMA_COMPARE_OPTION = 'ssf_medlemsprocess_archive_schema_compare';
    private const SCHEMA_SYNC_OPTION = 'ssf_medlemsprocess_archive_schema_sync';
    private const BATCH_OPTION = 'ssf_medlemsprocess_archive_batch';
    private const SCHEMA_ERROR_PREFIX = 'ssf_archive_schema_error_';
    private const GENERIC_OPTION = 'ssf_sharepoint_folder_migration';

    private $graph;
    private $generic_core;

    public function __construct()
    {
        $this->ensure_graph();
        add_action('admin_post_ssf_application_archive_save_source', array($this, 'save_source'));
        add_action('admin_post_ssf_application_archive_read_source_schema', array($this, 'read_source_schema'));
        add_action('admin_post_ssf_application_archive_save_target', array($this, 'save_target'));
        add_action('admin_post_ssf_application_archive_create_target_folder', array($this, 'create_target_folder'));
        add_action('admin_post_ssf_application_archive_use_existing_target', array($this, 'use_existing_target'));
        add_action('admin_post_ssf_application_archive_create_browser_folder', array($this, 'create_browser_folder'));
        add_action('admin_post_ssf_application_archive_compare_schema', array($this, 'compare_schema'));
        add_action('admin_post_ssf_application_archive_preview_schema', array($this, 'preview_schema_sync'));
        add_action('admin_post_ssf_application_archive_create_columns', array($this, 'create_missing_columns'));
        add_action('admin_post_ssf_application_archive_verify_schema', array($this, 'verify_schema'));
        add_action('admin_post_ssf_application_archive_readiness', array($this, 'run_readiness'));
        add_action('admin_post_ssf_application_archive_write_test', array($this, 'run_write_test'));
        add_action('admin_post_ssf_application_archive_plan', array($this, 'refresh_plan'));
        add_action('admin_post_ssf_application_archive_migrate_one', array($this, 'migrate_selected'));
        add_action('admin_post_ssf_application_archive_batch', array($this, 'run_batch'));
        add_action('admin_post_ssf_application_archive_batch_control', array($this, 'control_batch'));
        add_action('admin_post_ssf_application_archive_cutover', array($this, 'activate_target'));
        add_action('admin_post_ssf_application_archive_export', array($this, 'export_plan'));
        add_action('admin_post_ssf_application_archive_restore_refs', array($this, 'restore_old_refs'));
        add_action('admin_post_ssf_folder_migration_mode', array($this, 'generic_save_mode'));
        add_action('admin_post_ssf_folder_migration_location', array($this, 'generic_save_location'));
        add_action('admin_post_ssf_folder_migration_destination', array($this, 'generic_save_destination'));
        add_action('admin_post_ssf_folder_migration_confirm_existing', array($this, 'generic_confirm_existing_target'));
        add_action('admin_post_ssf_folder_migration_inventory', array($this, 'generic_inventory'));
        add_action('admin_post_ssf_folder_migration_dry_run', array($this, 'generic_dry_run'));
        add_action('admin_post_ssf_folder_migration_prepare', array($this, 'generic_prepare'));
        add_action('admin_post_ssf_folder_migration_write_test', array($this, 'generic_write_test'));
        add_action('admin_post_ssf_folder_migration_test_case', array($this, 'generic_test_case'));
        add_action('admin_post_ssf_folder_migration_run', array($this, 'generic_run'));
    }

    public static function unschedule(): void
    {
    }

    public function render_page(): void
    {
        $this->require_manage();
        if ($this->generic_enabled()) {
            $this->render_generic_wizard();
            return;
        }
        $this->render_wizard();
        return;
        $target = $this->target();
        $readiness = (array) get_option(self::READINESS_OPTION, array());
        $write = (array) get_option(self::WRITE_TEST_OPTION, array());
        $plan = $this->plan(false);
        ?>
        <div class="wrap ssf-archive-migration">
            <h2>Flytta SharePoint-kataloger</h2>
            <?php if (class_exists('SSF_Admin_Navigation')) { SSF_Admin_Navigation::render_system_tabs('ssf-application-archive-migration'); } ?>
            <?php $this->notice(); ?>
            <p class="ssf-archive-migration__intro">Flytta medlemsansökningarnas SharePoint-arkiv till en ny plats. Följ stegen i ordning och aktivera först när samtliga kontroller är godkända.</p>

            <div class="ssf-archive-summary" aria-label="Migreringsöversikt">
                <dl><div><dt>Katalog</dt><dd>Medlemsansökningar</dd></div><div><dt>Nuvarande plats</dt><dd>Medlemsgruppens SharePoint</dd></div><div><dt>Ny plats</dt><dd><?php echo esc_html((string) ($target['folder_path'] ?: 'Inte vald')); ?></dd></div><div><dt>Miljö</dt><dd><span class="ssf-archive-environment"><?php echo esc_html(strtoupper($this->environment())); ?></span></dd></div></dl>
            </div>

            <section class="ssf-archive-step" aria-labelledby="ssf-archive-source-heading">
            <div class="ssf-archive-step__heading"><span>1</span><div><h2 id="ssf-archive-source-heading">Välj katalog</h2><p>Välj vilket arkiv som ska flyttas.</p></div></div>
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
            </section>

            <section class="ssf-archive-step" aria-labelledby="ssf-archive-target-heading">
            <div class="ssf-archive-step__heading"><span>2</span><div><h2 id="ssf-archive-target-heading">Välj ny plats</h2><p>Ange SharePoint-siten, dokumentbiblioteket och sökvägen till den nya mappen.</p></div></div>
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
            </section>

            <section class="ssf-archive-step" aria-labelledby="ssf-archive-test-heading">
            <div class="ssf-archive-step__heading"><span>3</span><div><h2 id="ssf-archive-test-heading">Testa anslutningen</h2><p>Verifiera katalogen och skrivrättigheten innan du skapar en migreringsplan.</p></div></div>
            <div class="ssf-archive-actions">
                <?php $this->button('ssf_application_archive_readiness', 'Testa ny katalog'); ?>
                <?php $this->button('ssf_application_archive_write_test', 'Testa skrivning'); ?>
                <?php $this->button('ssf_application_archive_plan', 'Visa migreringsplan'); ?>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_application_archive_export'), 'ssf_application_archive_export')); ?>">Exportera rapport</a>
            </div>

            <h3>Kontrollstatus</h3>
            <table class="widefat striped ssf-archive-status"><tbody>
                <?php foreach ($this->status_rows($readiness, $write, $plan) as $row) : ?>
                    <tr><th><?php echo esc_html($row[0]); ?></th><td><?php echo esc_html($row[1]); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            </section>

            <section class="ssf-archive-step" aria-labelledby="ssf-archive-case-heading">
            <div class="ssf-archive-step__heading"><span>4</span><div><h2 id="ssf-archive-case-heading">Migrera ett testärende</h2><p>Flytta ett valt ärende och kontrollera resultatet innan aktivering.</p></div></div>
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
            </section>

            <section class="ssf-archive-step ssf-archive-step--activation" aria-labelledby="ssf-archive-activate-heading">
            <div class="ssf-archive-step__heading"><span>5</span><div><h2 id="ssf-archive-activate-heading">Aktivera för nya ansökningar</h2><p>Nya ansökningar börjar använda den verifierade platsen. Befintliga ärenden byter inte automatiskt.</p></div></div>
            <?php $this->button('ssf_application_archive_cutover', 'Aktivera ny katalog', 'primary'); ?>
            </section>

            <details class="ssf-archive-details" open><summary>Migreringsplan</summary><?php $this->render_plan($plan); ?></details>

            <details class="ssf-archive-details"><summary>Tekniska detaljer</summary><pre><?php echo esc_html(wp_json_encode(array('target' => $target, 'readiness' => $readiness, 'write_test' => $write), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
        </div>
        <?php
    }

    /**
     * New generic folder-migration UI.  The legacy wizard below remains the
     * membership adapter and can be selected explicitly for its WP reference
     * switch/restore behaviour.
     */
    private function render_generic_wizard(): void
    {
        $this->enqueue_generic_discovery();
        $state = $this->generic_state();
        $source = (array) ($state['source'] ?? array());
        $target = (array) ($state['target'] ?? array());
        $inventory = (array) ($state['inventory'] ?? array());
        $dry_run = (array) ($state['dry_run'] ?? array());
        $target_check = (array) ($state['target_check'] ?? array());
        $prepared = (array) ($state['prepared'] ?? array());
        $write = (array) ($state['write_test'] ?? array());
        $final = (array) ($state['reconciliation'] ?? array());
        $editing = sanitize_key((string) ($_GET['location'] ?? 'source')) === 'target' ? 'target' : 'source';
        $keep_name = '0' !== (string) ($target['keep_name'] ?? '1');
        $source_name = (string) ($source['folder_name'] ?? '');
        $custom_name = $keep_name ? '' : (string) ($target['destination_folder_name'] ?? '');
        $result_name = $keep_name ? $source_name : $custom_name;
        $preview = implode('/', array_values(array_filter(array(trim((string) ($target['folder_path'] ?? ''), '/'), trim((string) ($target['extra_structure'] ?? ''), '/'), $result_name), 'strlen')));
        $destination_check = $dry_run ?: $target_check;
        $existing_destination = (array) ($destination_check['existing_destination'] ?? array());
        $existing_confirmed = ! empty($existing_destination['id'])
            && 'replace_files' === (string) ($target['existing_target_policy'] ?? '')
            && hash_equals((string) $existing_destination['id'], (string) ($target['confirmed_existing_target_id'] ?? ''));
        $destination_label = implode(' / ', array_values(array_filter(array((string) ($target['site_name'] ?? ''), (string) ($target['drive_name'] ?? ''), (string) ($destination_check['destination_path'] ?? $preview)), 'strlen')));
        ?>
        <div class="wrap ssf-archive-migration">
            <h1>Migrera katalogstruktur</h1>
            <?php if (class_exists('SSF_Admin_Navigation')) { SSF_Admin_Navigation::render_system_tabs('ssf-application-archive-migration'); } ?>
            <?php $this->generic_notice(); ?>
            <p class="ssf-archive-migration__intro">Kopiera och verifiera en vald SharePoint-katalog. Källan lämnas alltid orörd.</p>
            <?php $this->render_migration_mode_selector('generic'); ?>

            <section id="archive-source" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>1</span><div><h2>Välj källa</h2><p>Hitta site, dokumentbibliotek och källa med samma SharePoint-utforskare som används för SharePoint-integrationer.</p></div></div>
                <p><strong>KÄLLA:</strong> <?php echo esc_html((string) ($source['site_name'] ?? 'Inte vald')); ?> / <?php echo esc_html((string) ($source['drive_name'] ?? '')); ?> / <?php echo esc_html((string) ($source['folder_path'] ?? '')); ?></p>
                <p class="<?php echo empty($source['folder_id']) ? 'ssf-archive-pending-state' : 'ssf-archive-verified-state'; ?>"><?php echo empty($source['folder_id']) ? 'Välj och spara en källa för att fortsätta.' : '✓ Källan är verifierad och sparad.'; ?></p>
                <?php if ('source' === $editing) : ?>
                    <?php $this->render_generic_discovery_form('source', $source); ?>
                <?php else : ?>
                    <p><a class="button" href="<?php echo esc_url(add_query_arg('location', 'source', remove_query_arg('location')) . '#archive-source'); ?>">Ändra källa</a></p>
                <?php endif; ?>
            </section>

            <section id="archive-inventory" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>2</span><div><h2>Inventera källa</h2><p>Hela den valda strukturen, metadata som faktiskt används och relevanta kolumner läses utan skrivning.</p></div></div>
                <?php $this->generic_button('ssf_folder_migration_inventory', 'Inventera källa', 'archive-inventory', empty($source['folder_id'])); ?>
                <?php if (empty($source['folder_id'])) : ?><p class="description">Steg 1 måste vara verifierat och sparat innan inventeringen kan starta.</p><?php endif; ?>
                <?php if ($inventory) : ?><p><strong>Struktur inventerad:</strong> <?php echo esc_html((string) ($inventory['summary']['folders'] ?? 0)); ?> mappar, <?php echo esc_html((string) ($inventory['summary']['files'] ?? 0)); ?> filer, <?php echo esc_html(size_format((int) ($inventory['summary']['bytes'] ?? 0))); ?>. Metadatafält använda: <?php echo esc_html((string) ($inventory['summary']['metadata_fields_used'] ?? 0)); ?>.</p><?php endif; ?>
                <?php if (! empty($inventory['metadata_policy']['excluded_fields'])) : ?><p class="description"><strong>Kanonisk medlemsmetadata:</strong> äldre dubblettfält ignoreras: <code><?php echo esc_html(implode(', ', (array) $inventory['metadata_policy']['excluded_fields'])); ?></code>.</p><?php endif; ?>
            </section>

            <section id="archive-target" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>3</span><div><h2>Välj målroot</h2><p>Välj den katalog där den migrerade strukturen ska placeras. Slutmappen skapas automatiskt vid förberedelse.</p></div></div>
                <p><strong>VALD ROOT:</strong> <?php echo esc_html((string) ($target['site_name'] ?? 'Inte vald')); ?> / <?php echo esc_html((string) ($target['drive_name'] ?? '')); ?> / <?php echo esc_html((string) ($target['folder_path'] ?? '')); ?></p>
                <p class="<?php echo empty($target['folder_id']) ? 'ssf-archive-pending-state' : 'ssf-archive-verified-state'; ?>"><?php echo empty($target['folder_id']) ? 'Välj och spara en målroot för att fortsätta.' : '✓ Målrooten är verifierad och sparad.'; ?></p>
                <?php if ('target' === $editing) : ?>
                    <?php $this->render_generic_discovery_form('target', $target); ?>
                <?php else : ?>
                    <p><a class="button" href="<?php echo esc_url(add_query_arg('location', 'target', remove_query_arg('location')) . '#archive-target'); ?>">Välj eller ändra målroot</a></p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ssf-destination-options><input type="hidden" name="action" value="ssf_folder_migration_destination"><?php wp_nonce_field('ssf_folder_migration_destination'); ?>
                    <fieldset><legend><strong>Slutmappens namn</strong></legend>
                    <p><label><input type="radio" name="keep_name" value="1" <?php checked($keep_name); ?>> Behåll källmappens namn<?php echo $source_name ? ': ' . esc_html($source_name) : ''; ?></label><br><label><input type="radio" name="keep_name" value="0" <?php checked(! $keep_name); ?>> Använd ett nytt namn</label></p>
                    <p><label>Nytt namn <input class="regular-text" name="destination_folder_name" value="<?php echo esc_attr($custom_name); ?>" <?php disabled($keep_name); ?>></label> <span class="description">Används endast när “Använd ett nytt namn” är valt.</span></p>
                    </fieldset>
                    <p><label>Extra struktur <input class="regular-text" name="extra_structure" value="<?php echo esc_attr((string) ($target['extra_structure'] ?? '')); ?>" placeholder="arkiv/2026"></label></p>
                    <?php submit_button('Spara namn och struktur', 'secondary', 'submit', false); ?></form>
                <div class="ssf-archive-preview"><strong>SÅ KOMMER DET ATT SE UT</strong><dl><div><dt>Källa</dt><dd><?php echo esc_html((string) ($source['folder_path'] ?? '')); ?></dd></div><div><dt>Mål</dt><dd data-ssf-migration-preview><?php echo esc_html($preview); ?></dd></div></dl></div>
                <?php if (! empty($existing_destination['id'])) : ?>
                    <div class="notice notice-warning inline ssf-archive-result">
                        <p><strong>Det finns redan en mapp med samma namn i <?php echo esc_html($destination_label); ?>.</strong></p>
                        <?php if ($existing_confirmed) : ?>
                            <p>✓ Du har valt att använda den befintliga mappen. Filer med samma namn skrivs över; övriga filer och mappar lämnas orörda.</p>
                        <?php else : ?>
                            <p>Vill du skriva över den? Detta återanvänder mappen och ersätter bara filer som har samma namn. Tidigare versioner av ersatta filer kan gå förlorade.</p>
                            <form method="post" class="ssf-archive-inline-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="ssf_folder_migration_confirm_existing">
                                <input type="hidden" name="existing_target_id" value="<?php echo esc_attr((string) $existing_destination['id']); ?>">
                                <?php wp_nonce_field('ssf_folder_migration_confirm_existing'); ?>
                                <?php submit_button('Ja, använd mappen och skriv över filer med samma namn', 'primary', 'submit', false); ?>
                            </form>
                            <a class="button" href="#archive-target">Nej, välj ett annat namn eller mål</a>
                        <?php endif; ?>
                    </div>
                <?php elseif (! empty($target_check['ok'])) : ?>
                    <p class="ssf-archive-verified-state">✓ Målet är verifierat. Ingen mapp med namnet finns ännu.</p>
                <?php endif; ?>
            </section>

            <section id="archive-plan" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>4</span><div><h2>Migreringsplan och torrkörning</h2><p>Beräknar mål, schema, konflikter och blockerare. Torrkörning gör noll SharePoint-skrivningar.</p></div></div>
                <?php $this->generic_button('ssf_folder_migration_dry_run', 'Kör torrkörning', 'archive-plan', empty($inventory['ok']) || empty($target['folder_id'])); ?>
                <?php if ($dry_run) : ?>
                    <?php $blockers = (array) ($dry_run['blockers'] ?? array()); $warnings = (array) ($dry_run['warnings'] ?? array()); $create_columns = (array) ($dry_run['schema']['create'] ?? array()); ?>
                    <p class="<?php echo empty($dry_run['ok']) ? 'ssf-archive-blocked-state' : 'ssf-archive-verified-state'; ?>"><strong><?php echo empty($dry_run['ok']) ? '✕ Torrkörningen är blockerad. Åtgärda punkterna nedan och kör igen.' : '✓ Torrkörningen är godkänd. Du kan gå vidare till steg 5.'; ?></strong></p>
                    <dl class="ssf-archive-plan-summary"><div><dt>Mål</dt><dd><?php echo esc_html((string) ($dry_run['destination_path'] ?? '')); ?></dd></div><div><dt>Matchande kolumner</dt><dd><?php echo esc_html((string) count((array) ($dry_run['schema']['exact'] ?? array()))); ?></dd></div><div><dt>Saknade målkolumner</dt><dd><?php echo esc_html((string) count($create_columns)); ?></dd></div><div><dt>Blockerare</dt><dd><?php echo esc_html((string) count($blockers)); ?></dd></div></dl>
                    <?php if ($blockers) : ?><div class="notice notice-error inline ssf-archive-result"><p><strong>Det går inte att förbereda målet ännu:</strong></p><ul><?php foreach ($blockers as $blocker) : ?><li><?php echo esc_html((string) $blocker); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                    <?php if ($warnings) : ?><div class="notice notice-warning inline ssf-archive-result"><p><strong>Observera:</strong></p><ul><?php foreach ($warnings as $warning) : ?><li><?php echo esc_html((string) $warning); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                    <?php if ($create_columns) : ?><details class="ssf-archive-result" open><summary>Skapa <?php echo esc_html((string) count($create_columns)); ?> kolumner manuellt i SharePoint</summary><p>Skapa kolumnerna i målbiblioteket med exakt internt namn och kör sedan torrkörningen igen. WordPress ändrar aldrig biblioteksschemat.</p><ul><?php foreach ($create_columns as $column) : $choice_values = (array) ($column['payload']['choice']['choices'] ?? array()); ?><li><code><?php echo esc_html((string) ($column['name'] ?? '')); ?></code> — <?php echo esc_html((string) ($column['display_name'] ?? $column['name'] ?? '')); ?> (<?php echo esc_html((string) ($column['type'] ?? 'okänd')); ?>)<?php if ($choice_values) : ?><br><span class="description">Val: <?php echo esc_html(implode(' | ', $choice_values)); ?></span><?php endif; ?></li><?php endforeach; ?></ul></details><?php endif; ?>
                <?php else : ?><p class="description">Kör torrkörningen för att få ett tydligt godkänt eller blockerat resultat innan något skapas.</p><?php endif; ?>
            </section>

            <section id="archive-prepare" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>5</span><div><h2>Förbered målmapp</h2><p>Verifierar först att hela biblioteksschemat redan är korrekt. Först därefter skapas eventuell extra struktur och slutlig målmapp.</p></div></div>
                <?php $this->generic_button('ssf_folder_migration_prepare', 'Förbered målmapp', 'archive-prepare', empty($dry_run['ok'])); ?>
                <?php if (empty($dry_run['ok'])) : ?><p class="description">Knappen aktiveras först när steg 4 visar att torrkörningen är godkänd utan saknade kolumner eller andra blockerare. Inga mappar skapas medan schemat är ofullständigt.</p><?php endif; ?>
                <?php if ($prepared) : ?><p><strong>Slutligt mål:</strong> <?php echo esc_html((string) ($dry_run['destination_path'] ?? '')); ?>. Schemat verifierades före mappen skapades. Skapade migreringsundermapppar: 0.</p><?php endif; ?>
            </section>

            <section id="archive-write-test" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>6</span><div><h2>Skrivtest</h2><p>Verifierar mapp, fil och representativ metadata i den verkliga förberedda målroten, och städar testdata.</p></div></div>
                <?php $this->generic_button('ssf_folder_migration_write_test', 'Kör skrivtest', 'archive-write-test', empty($prepared['target_folder_id'])); ?>
                <?php if ($write) : foreach ((array) ($write['steps'] ?? array()) as $step) : ?><p><?php echo ! empty($step['ok']) ? '✓' : '✗'; ?> <?php echo esc_html((string) ($step['label'] ?? '')); ?></p><?php endforeach; endif; ?>
            </section>

            <section id="archive-test-case" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>7</span><div><h2>Testärende – valfritt</h2><p>Du kan hoppa över testärende. Slutförandet använder samma generella motor och hoppar över redan verifierade objekt vid återupptagning.</p></div></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ssf_folder_migration_test_case"><?php wp_nonce_field('ssf_folder_migration_test_case'); ?><label>Testärende <select name="source_item_id"><?php foreach ((array) ($inventory['items'] ?? array()) as $item) : if ('folder' !== ($item['type'] ?? '') || empty($item['depth'])) continue; ?><option value="<?php echo esc_attr((string) $item['id']); ?>"><?php echo esc_html((string) $item['path']); ?></option><?php endforeach; ?></select></label> <?php submit_button('Migrera testärende', 'secondary', 'submit', false, empty($write['ok']) ? array('disabled' => 'disabled') : array()); ?></form>
                <p><a class="button" href="#archive-run">Hoppa över testärende</a> <em>Ingen separat förenklad kopieringsväg används.</em></p>
            </section>
            <section id="archive-run" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>8</span><div><h2>Slutför migrering av källmappen</h2><p>Mappar och filer kopieras stegvis från SharePoint, metadata läses tillbaka och varje objekt sparas som verifierat. Källan raderas aldrig.</p></div></div>
                <?php $this->generic_button('ssf_folder_migration_run', 'Slutför migrering av källmappen', 'archive-run', empty($write['ok']) || empty($prepared['target_folder_id']), 'primary'); ?>
            </section>
            <section id="archive-reconcile" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>9</span><div><h2>Slutkontroll</h2><p>Källan är kvar och orörd. Rapporten visar verifierade objekt och eventuella fel.</p></div></div>
                <?php if ($final) : ?><p>Verifierade objekt: <?php echo esc_html((string) ($final['verified_items'] ?? 0)); ?> / <?php echo esc_html((string) ($final['expected_items'] ?? 0)); ?>. Källa: <?php echo ! empty($final['source_untouched']) ? '✓ kvar och orörd' : 'okänd'; ?>.</p><?php endif; ?>
            </section>
        </div>
        <script>
        (function () {
            var preview = document.querySelector('[data-ssf-migration-preview]');
            if (!preview) return;
            var sourceName = <?php echo wp_json_encode((string) ($source['folder_name'] ?? '')); ?>;
            var storedRoot = <?php echo wp_json_encode((string) ($target['folder_path'] ?? '')); ?>;
            function update() {
                var rootField = document.querySelector('[data-ssf-sharepoint-admin][data-location-kind="target"] [data-sp-field="folder_path"]');
                var root = rootField && rootField.value ? rootField.value : storedRoot;
                var keep = document.querySelector('input[name="keep_name"]:checked');
                var nameField = document.querySelector('input[name="destination_folder_name"]');
                var extraField = document.querySelector('input[name="extra_structure"]');
                if (nameField) {
                    nameField.disabled = Boolean(keep && keep.value === '1');
                }
                var name = keep && keep.value === '1' ? sourceName : (nameField ? nameField.value : '');
                preview.textContent = [root, extraField ? extraField.value : '', name].filter(Boolean).join('/').replace(/\/+/g, '/');
            }
            document.addEventListener('input', update); document.addEventListener('change', update); update();
        }());
        </script>
        <?php
    }

    private function generic_enabled(): bool
    {
        $state = $this->generic_state();
        return 'membership' !== (string) ($state['mode'] ?? 'generic');
    }

    private function render_migration_mode_selector(string $current): void
    {
        ?>
        <section class="ssf-archive-mode" aria-labelledby="ssf-archive-mode-heading">
            <div><strong id="ssf-archive-mode-heading">Migreringsflöde</strong><p>Välj generell katalogkopiering eller medlemsarkivets specialflöde. Ett byte öppnar det valda flödet men startar ingen migrering.</p></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_folder_migration_mode">
                <?php wp_nonce_field('ssf_folder_migration_mode'); ?>
                <label class="screen-reader-text" for="ssf-folder-migration-mode">Migreringsflöde</label>
                <select id="ssf-folder-migration-mode" name="mode">
                    <option value="generic" <?php selected('generic', $current); ?>>Valfri SharePoint-katalog</option>
                    <option value="membership" <?php selected('membership', $current); ?>>Medlemsansökningarnas arkiv</option>
                </select>
                <?php submit_button('Öppna valt flöde', 'secondary', 'submit', false); ?>
            </form>
        </section>
        <?php
    }

    private function generic_state(): array
    {
        return array_merge(array('mode' => 'generic', 'source' => array(), 'target' => array()), (array) get_option(self::GENERIC_OPTION, array()));
    }

    private function generic_core()
    {
        $this->ensure_graph();
        if (! $this->generic_core && $this->graph && class_exists('SSF\\MemberPortal\\Integrations\\Microsoft365\\FolderMigrationCore')) {
            $this->generic_core = new \SSF\MemberPortal\Integrations\Microsoft365\FolderMigrationCore($this->graph);
        }
        return $this->generic_core ?: new WP_Error('migration_core_unavailable', 'Den generella SharePoint-migreringen är inte tillgänglig.');
    }

    public function generic_save_mode(): void
    {
        $this->require_manage(); check_admin_referer('ssf_folder_migration_mode');
        $state = $this->generic_state(); $state['mode'] = 'membership' === sanitize_key((string) ($_POST['mode'] ?? 'generic')) ? 'membership' : 'generic';
        $label = 'membership' === $state['mode'] ? 'Medlemsansökningarnas arkiv' : 'Valfri SharePoint-katalog';
        update_option(self::GENERIC_OPTION, $state, false); $this->generic_redirect('', 'Migreringsflödet har bytts till: ' . $label . '. Ingen migrering har startats.', 'success');
    }

    public function generic_save_location(): void
    {
        $this->require_manage(); check_admin_referer('ssf_folder_migration_location');
        $kind = 'target' === sanitize_key((string) ($_POST['location_kind'] ?? 'source')) ? 'target' : 'source';
        $input = (array) wp_unslash($_POST['profile'] ?? array()); $location = array();
        foreach (array('site_url','site_id','site_name','drive_id','drive_name','list_id','folder_id','folder_name','folder_path','folder_web_url') as $key) $location[$key] = in_array($key, array('site_url','folder_web_url'), true) ? esc_url_raw((string) ($input[$key] ?? '')) : sanitize_text_field((string) ($input[$key] ?? ''));
        foreach (array('site_id','drive_id','list_id','folder_id') as $key) if (empty($location[$key])) $this->generic_redirect('archive-' . $kind, 'Välj site, dokumentbibliotek och mapp med Hitta/bläddra så att verifierade identifierare sparas.', 'error');
        $state = $this->generic_state(); $state[$kind] = $location;
        if ('source' === $kind && empty($state['target']['destination_folder_name'])) $state['target']['destination_folder_name'] = $location['folder_name'];
        unset($state['target']['use_existing_target'], $state['target']['existing_target_policy'], $state['target']['confirmed_existing_target_id']);
        unset($state['target_check'], $state['inventory'], $state['dry_run'], $state['prepared'], $state['write_test'], $state['reconciliation']); update_option(self::GENERIC_OPTION, $state, false);
        $this->generic_redirect('archive-' . $kind, ucfirst($kind) . ' verifierad och sparad.', 'success');
    }

    public function generic_save_destination(): void
    {
        $this->require_manage(); check_admin_referer('ssf_folder_migration_destination'); $state = $this->generic_state();
        $keep = '0' !== (string) ($_POST['keep_name'] ?? '1'); $source_name = (string) ($state['source']['folder_name'] ?? '');
        $destination_name = $keep ? $source_name : sanitize_text_field((string) ($_POST['destination_folder_name'] ?? ''));
        if (! $destination_name) $this->generic_redirect('archive-target', $keep ? 'Spara en verifierad källa innan källmappens namn kan behållas.' : 'Ange ett nytt namn för slutmappen.', 'error');
        $state['target']['keep_name'] = $keep ? '1' : '0'; $state['target']['destination_folder_name'] = $destination_name;
        $state['target']['extra_structure'] = trim(sanitize_text_field((string) ($_POST['extra_structure'] ?? '')), '/');
        unset($state['target']['use_existing_target'], $state['target']['existing_target_policy'], $state['target']['confirmed_existing_target_id']);
        unset($state['target_check'], $state['dry_run'], $state['prepared'], $state['write_test'], $state['reconciliation']);
        $core = $this->generic_core();
        if (is_wp_error($core)) { update_option(self::GENERIC_OPTION, $state, false); $this->generic_redirect('archive-target', $core->get_error_message(), 'error'); }
        $check = $core->inspect_destination((array) $state['source'], (array) $state['target']);
        if (is_wp_error($check)) { update_option(self::GENERIC_OPTION, $state, false); $this->generic_redirect('archive-target', $check->get_error_message(), 'error'); }
        $state['target_check'] = $check; update_option(self::GENERIC_OPTION, $state, false);
        $message = ! empty($check['exists']) ? 'Målmappen finns redan. Bekräfta hur konflikten ska hanteras.' : 'Målet är verifierat och mappnamnet är ledigt.';
        $this->generic_redirect('archive-target', $message, ! empty($check['exists']) ? 'error' : 'success');
    }

    public function generic_confirm_existing_target(): void
    {
        $this->require_manage(); check_admin_referer('ssf_folder_migration_confirm_existing'); $state = $this->generic_state();
        $check = ! empty($state['dry_run']['existing_destination']['id']) ? (array) $state['dry_run'] : (array) ($state['target_check'] ?? array());
        $existing_id = (string) ($check['existing_destination']['id'] ?? '');
        $posted_id = sanitize_text_field((string) ($_POST['existing_target_id'] ?? ''));
        if (! $existing_id || ! $posted_id || ! hash_equals($existing_id, $posted_id)) $this->generic_redirect('archive-target', 'Målmappen har ändrats. Verifiera målet igen.', 'error');
        $state['target']['use_existing_target'] = '1';
        $state['target']['existing_target_policy'] = 'replace_files';
        $state['target']['confirmed_existing_target_id'] = $existing_id;
        unset($state['dry_run'], $state['prepared'], $state['write_test'], $state['reconciliation']); update_option(self::GENERIC_OPTION, $state, false);
        $this->generic_redirect('archive-target', 'Den befintliga målmappen är bekräftad. Filer med samma namn skrivs över först när migreringen körs.', 'success');
    }

    public function generic_inventory(): void { $this->generic_execute('ssf_folder_migration_inventory', 'archive-inventory', function ($core, &$state) { return $core->inventory((array) $state['source']); }, 'inventory', 'Källan är inventerad.'); }
    public function generic_dry_run(): void { $this->generic_execute('ssf_folder_migration_dry_run', 'archive-plan', function ($core, &$state) { return $core->dry_run((array) $state['source'], (array) $state['target'], (array) $state['inventory']); }, 'dry_run', 'Torrkörningen är klar; inga SharePoint-skrivningar gjordes.'); }
    public function generic_prepare(): void { $this->generic_execute('ssf_folder_migration_prepare', 'archive-prepare', function ($core, &$state) { return $core->prepare((array) $state['target'], (array) $state['dry_run']); }, 'prepared', 'Schemat är verifierat och målmappen är förberedd.'); }
    public function generic_write_test(): void { $this->generic_execute('ssf_folder_migration_write_test', 'archive-write-test', function ($core, &$state) { return $core->write_test((array) $state['target'], (string) ($state['prepared']['target_folder_id'] ?? ''), (array) $state['inventory']); }, 'write_test', 'Skrivtestet är klart.'); }
    public function generic_run(): void
    {
        $this->require_manage(); check_admin_referer('ssf_folder_migration_run'); $state = $this->generic_state(); $core = $this->generic_core();
        if (is_wp_error($core)) $this->generic_redirect('archive-run', $core->get_error_message(), 'error');
        $result = $core->migrate((array) $state['source'], (array) $state['target'], (array) $state['inventory'], (string) ($state['prepared']['target_folder_id'] ?? ''));
        if (is_wp_error($result)) $this->generic_redirect('archive-run', $result->get_error_message(), 'error');
        $state['migration'] = $result; $state['reconciliation'] = $core->reconcile((array) $state['source'], (array) $state['target'], (array) $state['inventory'], (string) ($state['prepared']['target_folder_id'] ?? '')); update_option(self::GENERIC_OPTION, $state, false);
        $this->generic_redirect('archive-reconcile', 'Migreringen är klar och slutkontrollerad.', 'success');
    }

    public function generic_test_case(): void
    {
        $this->require_manage(); check_admin_referer('ssf_folder_migration_test_case'); $state = $this->generic_state(); $core = $this->generic_core();
        if (is_wp_error($core) || empty($state['write_test']['ok']) || empty($state['prepared']['target_folder_id'])) $this->generic_redirect('archive-test-case', 'Skrivtest och förberedd målroot krävs före testärendet.', 'error');
        $id = sanitize_text_field((string) ($_POST['source_item_id'] ?? '')); $item = array(); foreach ((array) ($state['inventory']['items'] ?? array()) as $candidate) if ($id === (string) ($candidate['id'] ?? '')) { $item = $candidate; break; }
        if (empty($item) || 'folder' !== ($item['type'] ?? '') || empty($item['depth'])) $this->generic_redirect('archive-test-case', 'Välj en undermapp som testärende.', 'error');
        $test_source = array_merge((array) $state['source'], array('folder_id' => $item['id'], 'folder_name' => $item['name'], 'folder_path' => $item['path']));
        $test_inventory = $core->inventory($test_source); if (is_wp_error($test_inventory)) $this->generic_redirect('archive-test-case', $test_inventory->get_error_message(), 'error');
        $test_target = array_merge((array) $state['target'], array('folder_id' => (string) $state['prepared']['target_folder_id'], 'folder_path' => (string) ($state['dry_run']['destination_path'] ?? ''), 'destination_folder_name' => (string) $item['name'], 'extra_structure' => ''));
        $test_dry_run = $core->dry_run($test_source, $test_target, $test_inventory); if (is_wp_error($test_dry_run) || empty($test_dry_run['ok'])) $this->generic_redirect('archive-test-case', is_wp_error($test_dry_run) ? $test_dry_run->get_error_message() : 'Testärendet har blockerare.', 'error');
        $prepared = $core->prepare($test_target, $test_dry_run); if (is_wp_error($prepared)) $this->generic_redirect('archive-test-case', $prepared->get_error_message(), 'error');
        $result = $core->migrate($test_source, $test_target, $test_inventory, (string) $prepared['target_folder_id']); if (is_wp_error($result)) $this->generic_redirect('archive-test-case', $result->get_error_message(), 'error');
        $state['test_case'] = array('source_item_id' => $id, 'target_folder_id' => $prepared['target_folder_id'], 'verified_at' => gmdate('c')); update_option(self::GENERIC_OPTION, $state, false); $this->generic_redirect('archive-test-case', 'Testärendet är kopierat och verifierat. Slutmigreringen kommer att hoppa över verifierade objekt.', 'success');
    }

    private function generic_execute(string $nonce, string $section, callable $operation, string $key, string $success): void
    {
        $this->require_manage(); check_admin_referer($nonce); $state = $this->generic_state(); $core = $this->generic_core();
        if (is_wp_error($core)) $this->generic_redirect($section, $core->get_error_message(), 'error'); $result = $operation($core, $state);
        if (is_wp_error($result)) $this->generic_redirect($section, $result->get_error_message(), 'error'); $state[$key] = $result; update_option(self::GENERIC_OPTION, $state, false); $this->generic_redirect($section, $success, 'success');
    }

    private function generic_redirect(string $section, string $message, string $type): void
    {
        set_transient('ssf_folder_migration_notice_' . get_current_user_id(), array('message' => $message, 'type' => $type), MINUTE_IN_SECONDS);
        $url = add_query_arg(array('page' => 'ssf-application-archive-migration'), admin_url('admin.php'));
        wp_safe_redirect($section ? $url . '#' . $section : $url); exit;
    }

    private function generic_notice(): void { $notice = get_transient('ssf_folder_migration_notice_' . get_current_user_id()); if ($notice) { delete_transient('ssf_folder_migration_notice_' . get_current_user_id()); echo '<div class="notice notice-' . esc_attr('error' === ($notice['type'] ?? '') ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html((string) $notice['message']) . '</p></div>'; } }

    private function generic_button(string $action, string $label, string $section, bool $disabled = false, string $class = 'secondary'): void { echo '<form method="post" class="ssf-archive-inline-form" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr($action) . '">'; wp_nonce_field($action); submit_button($label, $class, 'submit', false, $disabled ? array('disabled' => 'disabled') : array()); echo '</form>'; }

    private function render_generic_discovery_form(string $kind, array $profile): void
    {
        ?>
        <form class="ssf-sp-wizard" data-ssf-sharepoint-admin data-location-kind="<?php echo esc_attr($kind); ?>" data-destination="folder_migration" data-environment="<?php echo esc_attr($this->environment()); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ssf_folder_migration_location"><input type="hidden" name="location_kind" value="<?php echo esc_attr($kind); ?>"><?php wp_nonce_field('ssf_folder_migration_location'); ?>
            <p><strong>Redigerar:</strong> <?php echo 'source' === $kind ? 'KÄLLA' : 'VALD ROOT'; ?></p>
            <p><label>SharePoint Site URL <input class="regular-text" name="profile[site_url]" value="<?php echo esc_attr((string) ($profile['site_url'] ?? '')); ?>" data-sp-field="site_url"></label> <button type="button" class="button" data-sp-operation="site">Hitta site</button></p><div class="ssf-sp-result" data-sp-result="site" aria-live="polite"></div>
            <p><label>Dokumentbibliotek <input class="regular-text" name="profile[drive_name]" value="<?php echo esc_attr((string) ($profile['drive_name'] ?? '')); ?>" data-sp-field="drive_name"></label> <button type="button" class="button" data-sp-operation="drives">Hitta dokumentbibliotek</button></p><div class="ssf-sp-result" data-sp-result="drives" aria-live="polite"></div>
            <p><label>Mappväg <input class="regular-text" name="profile[folder_path]" value="<?php echo esc_attr((string) ($profile['folder_path'] ?? '')); ?>" data-sp-field="folder_path"></label> <button type="button" class="button" data-sp-operation="folder_path">Hitta mapp</button> <button type="button" class="button" data-sp-operation="folders" data-parent-id="" data-parent-path="">Bläddra från roten</button></p><div class="ssf-sp-result" data-sp-result="folders" aria-live="polite"></div>
            <details><summary>Avancerat / identifierare</summary><?php foreach (array('site_name','site_id','drive_id','list_id','folder_name','folder_id','folder_web_url') as $key) : ?><p><label><?php echo esc_html($key); ?> <input class="regular-text" name="profile[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) ($profile[$key] ?? '')); ?>" data-sp-field="<?php echo esc_attr($key); ?>"></label></p><?php endforeach; ?></details>
            <?php submit_button('Spara verifierad ' . ('source' === $kind ? 'källa' : 'målroot'), 'secondary'); ?>
        </form>
        <?php
    }

    private function enqueue_generic_discovery(): void
    {
        if (! defined('SSF_MEMBER_PORTAL_URL')) return;
        wp_enqueue_style('ssf-sharepoint-admin', SSF_MEMBER_PORTAL_URL . 'assets/css/sharepoint-admin.css', array(), SSF_MEMBER_PORTAL_VERSION);
        wp_enqueue_script('ssf-sharepoint-admin', SSF_MEMBER_PORTAL_URL . 'assets/js/sharepoint-admin.js', array(), SSF_MEMBER_PORTAL_VERSION, true);
        wp_localize_script('ssf-sharepoint-admin', 'ssfSharePointAdmin', array('ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ssf_sharepoint_admin'), 'currentEnvironment' => $this->environment()));
    }

    private function render_wizard(): void
    {
        $source = $this->source();
        $target = $this->target();
        $source_schema = (array) get_option(self::SOURCE_SCHEMA_OPTION, array());
        $comparison = (array) get_option(self::SCHEMA_COMPARE_OPTION, array());
        $schema_sync = (array) get_option(self::SCHEMA_SYNC_OPTION, array());
        $write = (array) get_option(self::WRITE_TEST_OPTION, array());
        $readiness = (array) get_option(self::READINESS_OPTION, array());
        $batch = (array) get_option(self::BATCH_OPTION, array());
        $plan = $this->plan(false);
        ?>
        <div class="wrap ssf-archive-migration">
            <h1>Migrera medlemsansökningarnas SharePoint-arkiv</h1>
            <?php if (class_exists('SSF_Admin_Navigation')) { SSF_Admin_Navigation::render_system_tabs('ssf-application-archive-migration'); } ?>
            <?php $this->generic_notice(); ?>
            <?php $this->notice(); ?>
            <?php $this->render_migration_mode_selector('membership'); ?>
            <p class="ssf-archive-migration__intro">Guidad migrering enbart för medlemsansökningar. Motioner, årsmöten, arbetsflöden och e-post lämnas oförändrade.</p>
            <div class="ssf-archive-summary"><dl><div><dt>Källa</dt><dd><?php echo esc_html((string) ($source['folder_path'] ?: 'Inte vald')); ?></dd></div><div><dt>Mål</dt><dd><?php echo esc_html((string) ($target['folder_path'] ?: 'Inte vald')); ?></dd></div><div><dt>Målstatus</dt><dd><?php echo esc_html($this->target_state_label((string) ($target['target_state'] ?? ''))); ?></dd></div><div><dt>Miljö</dt><dd><?php echo esc_html(strtoupper($this->environment())); ?></dd></div></dl></div>

            <?php $this->render_location_step(1, 'Välj källa', 'Källan hämtas normalt från medlemsansökningarnas aktiva SharePoint-konfiguration.', 'source', $source); ?>

            <section id="archive-inventory" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>2</span><div><h2>Läs källans schema</h2><p>Inventera alla kolumndefinitioner och klassificera system-, innehållstyp- och anpassade kolumner.</p></div></div>
                <?php if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('archive-inventory'); } ?>
                <?php $this->button('ssf_application_archive_read_source_schema', 'Läs källans kolumnschema'); ?>
                <?php $this->render_schema_inventory($source_schema); ?>
            </section>

            <?php $this->render_target_location_step($source, $target); ?>

            <section id="archive-schema" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>4</span><div><h2>Jämför schema</h2><p>Matchning sker på internt kolumnnamn. Konflikter och typer som inte stöds kräver manuell kontroll.</p></div></div>
                <?php if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('archive-schema'); } ?>
                <?php $this->button('ssf_application_archive_compare_schema', 'Jämför schema'); ?>
                <?php $this->render_schema_comparison($comparison); ?>
            </section>

            <section class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>5</span><div><h2>Migrera/verifiera schema</h2><p>Endast saknade, stödda anpassade kolumner skapas. Befintliga kolumner ändras aldrig.</p></div></div>
                <?php $this->button('ssf_application_archive_preview_schema', 'Förhandsgranska schemasynk'); ?>
                <?php $this->button('ssf_application_archive_create_columns', 'Skapa saknade kolumner', 'primary'); ?>
                <?php $this->button('ssf_application_archive_verify_schema', 'Verifiera schema'); ?>
                <p><strong>Status:</strong> <?php echo esc_html($this->status_label($schema_sync['verified'] ?? null)); ?><?php if (! empty($schema_sync['mode'])) { echo ' (' . esc_html((string) $schema_sync['mode']) . ')'; } ?></p>
            </section>

            <section id="archive-write-test" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>6</span><div><h2>Skrivtest</h2><p>Skapa, metadata-sätt, läs tillbaka, jämför och radera en temporär testfil och testkatalog.</p></div></div>
                <?php if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('archive-write-test'); } ?>
                <?php $this->button('ssf_application_archive_write_test', 'Kör skrivtest'); ?>
                <?php $this->render_check_steps($write); ?>
            </section>

            <section id="archive-plan" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>7</span><div><h2>Förhandsgranska ansökningar</h2><p>Torrkörning: inga filer eller referenser ändras.</p></div></div>
                <?php if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('archive-plan'); } ?>
                <?php $this->button('ssf_application_archive_plan', 'Förhandsgranska migrering'); ?>
                <?php $this->render_plan($plan); ?>
            </section>

            <section id="archive-migrate" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>8</span><div><h2>Migrera ett testärende</h2><p>Kopiera och verifiera ett valt ärende innan dess aktiva referenser byts.</p></div></div>
                <?php if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('archive-migrate'); } ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ssf_application_archive_migrate_one"><?php wp_nonce_field('ssf_application_archive_migrate_one'); ?>
                    <select name="application_id"><?php foreach ((array) ($plan['rows'] ?? array()) as $row) : ?><option value="<?php echo esc_attr((string) $row['id']); ?>"><?php echo esc_html($row['number'] . ' - ' . $row['vessel'] . ' (' . $row['status'] . ')'); ?></option><?php endforeach; ?></select>
                    <?php submit_button('Migrera testärende', 'primary', 'submit', false); ?>
                </form>
            </section>

            <section id="archive-batch" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>9</span><div><h2>Migrera resterande</h2><p>Kör en liten återupptagbar batch. Fel isoleras per ansökan.</p></div></div>
                <?php if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('archive-batch'); } ?>
                <?php $this->button('ssf_application_archive_batch', 'Migrera resterande', 'primary'); ?>
                <?php $this->batch_button('pause', 'Pausa'); $this->batch_button('resume', 'Återuppta'); $this->batch_button('retry', 'Försök igen för fel'); ?>
                <p><strong>Batchstatus:</strong> <?php echo esc_html((string) ($batch['status'] ?? 'ej startad')); ?></p>
            </section>

            <section id="archive-cutover" class="ssf-archive-step ssf-archive-step--activation"><div class="ssf-archive-step__heading"><span>10</span><div><h2>Slutkontroll</h2><p>Stäm av resultatet och gör ett explicit byte för framtida medlemsansökningar. Befintliga ärenden byter inte automatiskt.</p></div></div>
                <?php if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('archive-cutover'); } ?>
                <?php $this->button('ssf_application_archive_readiness', 'Kör slutkontroll'); ?>
                <?php $this->button('ssf_application_archive_cutover', 'Använd nya katalogen för nya medlemsansökningar', 'primary'); ?>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_application_archive_export'), 'ssf_application_archive_export')); ?>">Exportera avstämningsrapport</a>
                <table class="widefat striped ssf-archive-status"><tbody><?php foreach ($this->status_rows($readiness, $write, $plan) as $row) : ?><tr><th><?php echo esc_html($row[0]); ?></th><td><?php echo esc_html($row[1]); ?></td></tr><?php endforeach; ?></tbody></table>
            </section>

            <details class="ssf-archive-details"><summary>Tekniska detaljer</summary><pre><?php echo esc_html(wp_json_encode(array('source' => $source, 'target' => $target, 'schema' => $schema_sync, 'write_test' => $write, 'batch' => $batch), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
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

    public function save_source(): void
    {
        $this->guard('ssf_application_archive_save_source');
        $input = (array) wp_unslash($_POST['source'] ?? array());
        $source = array();
        foreach ($this->target_fields() as $key => $label) {
            $source[$key] = 'site_url' === $key || 'folder_web_url' === $key ? esc_url_raw((string) ($input[$key] ?? '')) : sanitize_text_field((string) ($input[$key] ?? ''));
        }
        $source['folder_path'] = trim((string) ($source['folder_path'] ?? ''), '/');
        $settings = $this->settings();
        $settings['sources'][$this->environment()] = array_merge($this->default_source(), $source);
        update_option(self::OPTION, $settings, false);
        $resolved = $this->resolve_location('source', true);
        $this->redirect(is_wp_error($resolved) ? $resolved->get_error_message() : 'Källan har sparats och kontrollerats.', is_wp_error($resolved) ? 'error' : 'success');
    }

    public function read_source_schema(): void
    {
        $this->guard('ssf_application_archive_read_source_schema');
        $source = $this->resolve_location('source', true);
        $schema = is_wp_error($source) ? $source : $this->column_schema($source);
        if (is_wp_error($schema)) {
            set_transient(self::SCHEMA_ERROR_PREFIX . get_current_user_id(), $this->safe_schema_error($schema), 10 * MINUTE_IN_SECONDS);
            $this->redirect('Källans kolumnschema kunde inte läsas.', 'error');
        }
        update_option(self::SOURCE_SCHEMA_OPTION, array('read_at' => gmdate('c'), 'columns' => $schema), false);
        $this->redirect('Källans fullständiga kolumnschema har lästs.', 'success');
    }

    public function compare_schema(): void
    {
        $this->guard('ssf_application_archive_compare_schema');
        $comparison = $this->schema_comparison();
        if (is_wp_error($comparison)) { $this->redirect($comparison->get_error_message(), 'error'); }
        update_option(self::SCHEMA_COMPARE_OPTION, $comparison, false);
        $this->redirect('Kolumnschemana har jämförts på internt namn.', 'success');
    }

    public function preview_schema_sync(): void
    {
        $this->guard('ssf_application_archive_preview_schema');
        $comparison = $this->schema_comparison();
        if (is_wp_error($comparison)) { $this->redirect($comparison->get_error_message(), 'error'); }
        update_option(self::SCHEMA_COMPARE_OPTION, $comparison, false);
        update_option(self::SCHEMA_SYNC_OPTION, array('mode' => 'FÖRHANDSVISNING', 'verified' => false, 'planned' => array_values(array_filter($comparison['columns'], static function ($row) { return 'MISSING' === $row['status']; }))), false);
        $this->redirect('Schemasynken har förhandsgranskats. Inga ändringar gjordes.', 'success');
    }

    public function create_missing_columns(): void
    {
        $this->guard('ssf_application_archive_create_columns');
        $comparison = $this->schema_comparison();
        $target = $this->resolve_target(true);
        if (is_wp_error($comparison) || is_wp_error($target)) { $error = is_wp_error($comparison) ? $comparison : $target; $this->redirect($error->get_error_message(), 'error'); }
        if (! $this->target_write_allowed($target)) { $this->redirect('Skrivning blockerad: DEV får inte använda produktionsmålet.', 'error'); }
        $created = array();
        $errors = array();
        foreach ($comparison['columns'] as $row) {
            if ('MISSING' !== $row['status']) { continue; }
            $payload = $this->column_create_payload((array) $row['source']);
            if (is_wp_error($payload)) { $errors[] = $payload->get_error_message(); continue; }
            $result = $this->request('POST', $this->columns_path($target), $payload);
            if (is_wp_error($result)) { $errors[] = $result->get_error_message(); continue; }
            // Read the created column back from SharePoint before considering it created.
            $read_back = $this->request('GET', $this->columns_path($target) . '/' . rawurlencode((string) ($result['id'] ?? '')));
            if (is_wp_error($read_back)) { $errors[] = $read_back->get_error_message(); continue; }
            $created[] = (string) ($read_back['name'] ?? $row['internal_name']);
        }
        update_option(self::SCHEMA_SYNC_OPTION, array('mode' => 'UTFÖRD', 'verified' => false, 'created' => $created, 'errors' => $errors, 'run_at' => gmdate('c')), false);
        $this->redirect($errors ? 'Schemasynken slutfördes med fel.' : 'Saknade stödda kolumner skapades och lästes tillbaka.', $errors ? 'error' : 'success');
    }

    public function verify_schema(): void
    {
        $this->guard('ssf_application_archive_verify_schema');
        $comparison = $this->schema_comparison();
        if (is_wp_error($comparison)) { $this->redirect($comparison->get_error_message(), 'error'); }
        update_option(self::SCHEMA_COMPARE_OPTION, $comparison, false);
        $blocking = array_filter($comparison['columns'], static function ($row) { return in_array($row['status'], array('MISSING', 'CONFLICT', 'UNSUPPORTED'), true); });
        $state = (array) get_option(self::SCHEMA_SYNC_OPTION, array());
        $state['mode'] = 'VERIFIERAD';
        $state['verified'] = ! $blocking;
        $state['verified_at'] = gmdate('c');
        $state['blocking'] = array_values($blocking);
        update_option(self::SCHEMA_SYNC_OPTION, $state, false);
        $this->redirect($blocking ? 'Schema verifierades med blockerande avvikelser.' : 'Målets schema matchar källans stödda anpassade kolumner.', $blocking ? 'error' : 'success');
    }

    public function save_target(): void
    {
        $this->guard('ssf_application_archive_save_target');
        $target = $this->posted_target();
        $name_check = $this->validate_sharepoint_folder_name((string) ($target['destination_folder_name'] ?? ''));
        if (is_wp_error($name_check)) { $this->redirect($name_check->get_error_message(), 'error'); }
        $settings = $this->settings();
        $settings['targets'][$this->environment()] = $target;
        update_option(self::OPTION, $settings, false);
        $this->reset_target_dependent_state();
        $resolved = $this->resolve_target_parent(true);
        if (is_wp_error($resolved)) {
            set_transient(self::SCHEMA_ERROR_PREFIX . get_current_user_id(), $this->safe_schema_error($resolved), 10 * MINUTE_IN_SECONDS);
            $this->redirect('Kunde inte hitta den valda SharePoint-mappen.', 'error');
        }
        $state = $this->refresh_target_state($resolved, true);
        if (is_wp_error($state)) {
            set_transient(self::SCHEMA_ERROR_PREFIX . get_current_user_id(), $this->safe_schema_error($state), 10 * MINUTE_IN_SECONDS);
            $this->redirect($state->get_error_message(), 'error');
        }
        $message = 'final_destination_exists' === ($state['target_state'] ?? '') ? 'Mappen finns redan.' : 'Platsen är vald och verifierad. Målet kan nu skapas automatiskt.';
        $this->redirect($message, 'final_destination_exists' === ($state['target_state'] ?? '') ? 'error' : 'success');
    }

    public function create_target_folder(): void
    {
        $this->guard('ssf_application_archive_create_target_folder');
        $target = $this->resolve_target_parent(true);
        if (is_wp_error($target)) { $this->redirect($target->get_error_message(), 'error'); }
        if (! $this->target_write_allowed($target)) { $this->redirect('Skrivning blockerad: DEV får inte använda produktionsmålet.', 'error'); }
        $created = $this->create_and_verify_final_target($target);
        if (is_wp_error($created)) {
            set_transient(self::SCHEMA_ERROR_PREFIX . get_current_user_id(), $this->safe_schema_error($created), 10 * MINUTE_IN_SECONDS);
            $this->redirect($created->get_error_message(), 'error');
        }
        $this->persist_verified_target($created, 'final_destination_created');
        $this->reset_target_dependent_state();
        $this->redirect('Målmappen skapades, lästes tillbaka från SharePoint och är verifierad.', 'success');
    }

    public function use_existing_target(): void
    {
        $this->guard('ssf_application_archive_use_existing_target');
        $target = $this->resolve_target_parent(true);
        if (is_wp_error($target)) { $this->redirect($target->get_error_message(), 'error'); }
        $existing = $this->find_final_target($target);
        if (is_wp_error($existing)) { $this->redirect($existing->get_error_message(), 'error'); }
        if (empty($existing)) { $this->redirect('Den befintliga mappen kunde inte längre hittas. Välj platsen igen.', 'error'); }
        $verified = $this->verify_folder_item($target, (string) ($existing['id'] ?? ''));
        if (is_wp_error($verified)) { $this->redirect($verified->get_error_message(), 'error'); }
        $this->persist_verified_target(array_merge($target, $this->target_folder_reference($verified, (string) $target['folder_path'])), 'final_destination_verified');
        $this->reset_target_dependent_state();
        $this->redirect('Befintlig målmapp är verifierad och vald. Schemajämförelsen avgör om den kan användas säkert.', 'success');
    }

    public function create_browser_folder(): void
    {
        $this->guard('ssf_application_archive_create_browser_folder');
        $settings = $this->settings();
        $settings['targets'][$this->environment()] = $this->posted_target();
        update_option(self::OPTION, $settings, false);
        $target = $this->resolve_target_parent(true);
        if (is_wp_error($target)) { $this->redirect($target->get_error_message(), 'error'); }
        if (! $this->target_write_allowed($target)) { $this->redirect('Skrivning blockerad: DEV får inte använda produktionsmålet.', 'error'); }
        $name = sanitize_text_field((string) wp_unslash($_POST['new_folder_name'] ?? ''));
        $name_check = $this->validate_sharepoint_folder_name($name);
        if (is_wp_error($name_check)) { $this->redirect($name_check->get_error_message(), 'error'); }
        $created = $this->request('POST', $this->children_path($target, (string) $target['parent_folder_id']), array('name' => $name, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'));
        if (is_wp_error($created)) {
            set_transient(self::SCHEMA_ERROR_PREFIX . get_current_user_id(), $this->safe_schema_error($created), 10 * MINUTE_IN_SECONDS);
            $this->redirect('Mappen kunde inte skapas.', 'error');
        }
        $verified = $this->verify_folder_item($target, (string) ($created['id'] ?? ''));
        if (is_wp_error($verified)) {
            set_transient(self::SCHEMA_ERROR_PREFIX . get_current_user_id(), $this->safe_schema_error($verified), 10 * MINUTE_IN_SECONDS);
            $this->redirect('Mappen skapades men kunde inte verifieras.', 'error');
        }
        $target['parent_folder_id'] = sanitize_text_field((string) ($verified['id'] ?? ''));
        $target['parent_folder_name'] = sanitize_text_field((string) ($verified['name'] ?? $name));
        $target['parent_folder_path'] = $this->join_drive_path((string) ($target['parent_folder_path'] ?? ''), $name);
        $target['parent_folder_web_url'] = esc_url_raw((string) ($verified['webUrl'] ?? ''));
        $state = $this->refresh_target_state($target, false);
        if (is_wp_error($state)) { $this->redirect($state->get_error_message(), 'error'); }
        $this->save_target_state($state);
        $this->reset_target_dependent_state();
        $this->redirect('Mappen skapades, verifierades och valdes som placering.', 'success');
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

    public function run_batch(): void
    {
        $this->guard('ssf_application_archive_batch');
        $plan = $this->plan(true);
        $completed_test = array_filter((array) ($plan['rows'] ?? array()), static function ($row) { return 'MIGRATED' === $row['status']; });
        if (! $completed_test) { $this->redirect('BATCH BLOCKERAD: migrera och verifiera ett testärende först.', 'error'); }
        $state = (array) get_option(self::BATCH_OPTION, array('status' => 'running', 'processed' => array(), 'errors' => array()));
        if ('paused' === ($state['status'] ?? '')) { $this->redirect('Batchen är pausad.', 'error'); }
        $state['status'] = 'running';
        $count = 0;
        foreach ((array) ($plan['rows'] ?? array()) as $row) {
            if ($count >= 5 || 'MIGRATED' === $row['status'] || in_array((int) $row['id'], (array) ($state['processed'] ?? array()), true)) { continue; }
            $result = $this->migrate_one((int) $row['id']);
            if (is_wp_error($result)) { $state['errors'][(int) $row['id']] = $result->get_error_message(); }
            else { $state['processed'][] = (int) $row['id']; unset($state['errors'][(int) $row['id']]); }
            ++$count;
        }
        $remaining = array_filter($this->plan(true)['rows'], static function ($row) { return 'MIGRATED' !== $row['status']; });
        $state['status'] = $remaining ? 'ready_for_next_batch' : 'completed';
        $state['updated_at'] = gmdate('c');
        update_option(self::BATCH_OPTION, $state, false);
        $this->redirect($remaining ? 'Batchen körde högst fem ansökningar och kan fortsättas.' : 'Alla återstående ansökningar är migrerade.', empty($state['errors']) ? 'success' : 'error');
    }

    public function control_batch(): void
    {
        $this->guard('ssf_application_archive_batch_control');
        $command = sanitize_key((string) ($_GET['command'] ?? ''));
        $state = (array) get_option(self::BATCH_OPTION, array());
        if ('pause' === $command) { $state['status'] = 'paused'; }
        elseif ('resume' === $command) { $state['status'] = 'ready_for_next_batch'; }
        elseif ('retry' === $command) { $state['status'] = 'ready_for_next_batch'; $state['processed'] = array_values(array_diff((array) ($state['processed'] ?? array()), array_map('intval', array_keys((array) ($state['errors'] ?? array()))))); $state['errors'] = array(); }
        else { $this->redirect('Okänt batchkommando.', 'error'); }
        $state['updated_at'] = gmdate('c');
        update_option(self::BATCH_OPTION, $state, false);
        $this->redirect('Batchstatusen har uppdaterats.', 'success');
    }

    public function activate_target(): void
    {
        $this->guard('ssf_application_archive_cutover');
        $readiness = (array) get_option(self::READINESS_OPTION, array());
        $write = (array) get_option(self::WRITE_TEST_OPTION, array());
        $schema = (array) get_option(self::SCHEMA_SYNC_OPTION, array());
        if (empty($readiness['ok']) || empty($write['ok']) || empty($schema['verified']) || ! class_exists('SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations')) {
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
        return $this->diagnostic_write_test();
        /* Legacy folder-only probe retained below for upgrade traceability. */
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

    private function diagnostic_write_test(): array
    {
        $target = $this->resolve_target();
        if (is_wp_error($target)) { return array('ok' => false, 'tested_at' => gmdate('c'), 'steps' => array($this->step('Folder access', $target))); }
        if (! $this->target_write_allowed($target)) { return array('ok' => false, 'tested_at' => gmdate('c'), 'steps' => array(array('label' => 'Environment write policy', 'ok' => false, 'message' => 'DEV får inte skriva till produktionsmålet.'))); }
        $schema = (array) get_option(self::SCHEMA_SYNC_OPTION, array());
        if (empty($schema['verified'])) { return array('ok' => false, 'tested_at' => gmdate('c'), 'steps' => array(array('label' => 'Migrated schema', 'ok' => false, 'message' => 'Verifiera schemat först.'))); }
        $auth = $this->graph ? $this->graph->authentication()->test() : new WP_Error('graph_unavailable', 'Graph saknas.');
        $site = is_wp_error($auth) ? new WP_Error('auth_required', 'Autentisering krävs.') : $this->request('GET', 'sites/' . rawurlencode((string) $target['site_id']));
        $drive = is_wp_error($site) ? new WP_Error('site_required', 'Site access krävs.') : $this->request('GET', $this->drive_base($target));
        $folder = is_wp_error($drive) ? new WP_Error('drive_required', 'Library access krävs.') : $this->request('GET', $this->item_path($target, (string) $target['folder_id']));
        $name = 'SSF-TEST-' . gmdate('Ymd-His') . '-' . wp_generate_password(4, false, false);
        $created_folder = is_wp_error($folder) ? $folder : $this->request('POST', $this->children_path($target, (string) $target['folder_id']), array('name' => $name, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'));
        $folder_id = is_wp_error($created_folder) ? '' : (string) ($created_folder['id'] ?? '');
        $file = $folder_id ? $this->request('PUT', $this->item_path($target, $folder_id) . ':/diagnostic.txt:/content', 'SSF migration diagnostic', array('Content-Type' => 'text/plain')) : new WP_Error('test_folder_required', 'Testkatalog saknas.');
        $file_id = is_wp_error($file) ? '' : (string) ($file['id'] ?? '');
        $list_item = $file_id ? $this->list_item($target, $file_id) : new WP_Error('test_file_required', 'Testfil saknas.');
        $sample_fields = array_filter($this->metadata_fields(array('wordpress_id' => 'SSF-TEST', 'number' => $name, 'vessel' => 'Diagnostiskt skrivtest')), static function ($value, $key) { return '' !== $key && '' !== $value; }, ARRAY_FILTER_USE_BOTH);
        $metadata = is_wp_error($list_item) ? $list_item : $this->request('PATCH', 'sites/' . rawurlencode((string) $target['site_id']) . '/lists/' . rawurlencode((string) $target['list_id']) . '/items/' . rawurlencode((string) ($list_item['id'] ?? '')) . '/fields', $sample_fields);
        $read_file = $file_id ? $this->request('GET', $this->item_path($target, $file_id) . '?$select=id,name,size') : new WP_Error('test_file_required', 'Testfil saknas.');
        $read_metadata = is_wp_error($list_item) ? $list_item : $this->request('GET', 'sites/' . rawurlencode((string) $target['site_id']) . '/lists/' . rawurlencode((string) $target['list_id']) . '/items/' . rawurlencode((string) ($list_item['id'] ?? '')) . '/fields');
        $matches = ! is_wp_error($read_metadata);
        foreach ($sample_fields as $key => $value) { if ((string) ($read_metadata[$key] ?? '') !== (string) $value) { $matches = false; } }
        $delete_file = $file_id ? $this->request('DELETE', $this->item_path($target, $file_id)) : new WP_Error('test_file_required', 'Testfil saknas.');
        $delete_folder = $folder_id ? $this->request('DELETE', $this->item_path($target, $folder_id)) : new WP_Error('test_folder_required', 'Testkatalog saknas.');
        $steps = array(
            $this->step('Graph authentication', $auth), $this->step('Site access', $site), $this->step('Library access', $drive), $this->step('Folder access', $folder),
            $this->step('Create temporary test folder', $created_folder), $this->step('Create small temporary test file', $file), $this->step('Write representative metadata using the migrated schema', $metadata),
            $this->step('Read file back', $read_file, $file_id), $this->step('Read metadata back', $read_metadata), array('label' => 'Compare values', 'ok' => $matches, 'message' => $matches ? '' : 'Metadata matchar inte.'),
            $this->step('Delete test file', $delete_file), $this->step('Delete test folder', $delete_folder),
        );
        return array('ok' => ! in_array(false, array_column($steps, 'ok'), true), 'tested_at' => gmdate('c'), 'steps' => $steps);
    }

    private function migrate_one(int $application_id)
    {
        $post = get_post($application_id);
        if (! $post || SSF_Medlemsprocess_Application::POST_TYPE !== $post->post_type) { return new WP_Error('application_missing', 'Ansökan kunde inte hittas.'); }
        if (empty(get_option(self::READINESS_OPTION, array())['ok']) || empty(get_option(self::WRITE_TEST_OPTION, array())['ok']) || empty(get_option(self::SCHEMA_SYNC_OPTION, array())['verified'])) {
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
        if (! $this->target_write_allowed($target)) { return $this->migration_error($application_id, new WP_Error('migration_write_blocked', 'DEV får inte skriva till produktionsmålet.')); }
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
        foreach ($ids as $id) { $rows[] = $this->plan_row((int) $id, $refresh); }
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

    private function plan_row(int $application_id, bool $inspect_source = false): array
    {
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $migration_status = (string) get_post_meta($application_id, '_ssf_sp_migration_status', true);
        $old_found = (bool) get_post_meta($application_id, '_ssf_sp_application_folder_id', true);
        $new_found = 'completed' === $migration_status || $this->current_matches_target($application_id);
        $verified_at = (string) get_post_meta($application_id, '_ssf_sp_migration_verified_at', true);
        $error = (string) get_post_meta($application_id, '_ssf_sp_migration_error', true);
        $status = $error ? 'ERROR' : ($new_found && $verified_at ? 'MIGRATED' : ($new_found ? 'VERIFY TARGET' : ($old_found ? 'READY' : 'READY FROM WORDPRESS')));
        $known_files = count((array) get_post_meta($application_id, '_ssf_sp_items', true));
        $inspection = $inspect_source && $old_found ? $this->inspect_source_data($application_id) : array('count' => $known_files, 'inspected' => false, 'error' => '');
        $extra_files = ! empty($inspection['inspected']) && (int) $inspection['count'] > $known_files;
        $diagnosis = $error ? 'SCHEMAFEL' : ($extra_files ? 'EXTRA FILER' : (! $old_found ? 'KÄLLA SAKNAS' : ($new_found && ! $verified_at ? 'MÅL FINNS - VERIFIERA' : ($new_found ? 'KLAR' : 'MANUELL KONTROLL'))));
        return array('id' => $application_id, 'number' => (string) get_post_meta($application_id, '_ssf_application_number', true), 'vessel' => (string) ($data['ship_name'] ?? get_the_title($application_id)), 'status' => $status, 'diagnosis' => $diagnosis, 'old_found' => $old_found, 'new_found' => $new_found, 'old_files' => (int) $inspection['count'], 'new_files' => $new_found ? $known_files : 0, 'extra_files' => $extra_files, 'source_inspected' => ! empty($inspection['inspected']), 'verified_at' => $verified_at, 'error' => $error ?: (string) ($inspection['error'] ?? ''));
    }

    private function inspect_source_data(int $application_id): array
    {
        $location = array_merge($this->source(), array('site_id' => (string) get_post_meta($application_id, '_ssf_sp_site_id', true), 'drive_id' => (string) get_post_meta($application_id, '_ssf_sp_drive_id', true)));
        $folder_id = (string) get_post_meta($application_id, '_ssf_sp_application_folder_id', true);
        if (empty($location['drive_id']) || ! $folder_id) { return array('count' => 0, 'inspected' => true, 'error' => 'Källreferens saknas.'); }
        $result = $this->count_source_files($location, $folder_id, 0);
        return is_wp_error($result) ? array('count' => 0, 'inspected' => true, 'error' => $result->get_error_message()) : array('count' => $result, 'inspected' => true, 'error' => '');
    }

    private function count_source_files(array $location, string $folder_id, int $depth)
    {
        if ($depth > 3) { return 0; }
        $children = $this->request('GET', $this->children_path($location, $folder_id) . '?$select=id,name,file,folder,size');
        if (is_wp_error($children)) { return $children; }
        $count = 0;
        foreach ((array) ($children['value'] ?? array()) as $child) {
            if (! empty($child['file'])) { ++$count; continue; }
            if (! empty($child['folder']) && ! empty($child['id'])) { $nested = $this->count_source_files($location, (string) $child['id'], $depth + 1); if (is_wp_error($nested)) { return $nested; } $count += $nested; }
        }
        return $count;
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

    private function render_location_step(int $number, string $title, string $description, string $kind, array $location): void
    {
        $action = 'ssf_application_archive_save_' . $kind;
        $section = 'source' === $kind ? 'archive-source' : 'archive-target';
        echo '<section id="' . esc_attr($section) . '" class="ssf-archive-step"><div class="ssf-archive-step__heading"><span>' . esc_html((string) $number) . '</span><div><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p></div></div>';
        if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline($section); }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        echo '<table class="form-table"><tr><th>SharePoint-site</th><td><input class="regular-text" name="' . esc_attr($kind) . '[site_url]" value="' . esc_attr((string) ($location['site_url'] ?? '')) . '"></td></tr><tr><th>Dokumentbibliotek</th><td><input class="regular-text" name="' . esc_attr($kind) . '[drive_name]" value="' . esc_attr((string) ($location['drive_name'] ?? '')) . '"></td></tr><tr><th>Katalog</th><td><input class="regular-text" name="' . esc_attr($kind) . '[folder_path]" value="' . esc_attr((string) ($location['folder_path'] ?? '')) . '"></td></tr></table>';
        echo '<details><summary>Tekniska detaljer</summary><table class="form-table">';
        foreach (array('site_id', 'drive_id', 'list_id', 'folder_name', 'folder_id', 'folder_web_url') as $key) {
            echo '<tr><th>' . esc_html($this->target_fields()[$key]) . '</th><td><input class="regular-text" name="' . esc_attr($kind) . '[' . esc_attr($key) . ']" value="' . esc_attr((string) ($location[$key] ?? '')) . '"></td></tr>';
        }
        echo '</table></details>';
        submit_button('source' === $kind ? 'Kontrollera källan' : 'Spara och kontrollera målet', 'secondary');
        echo '</form></section>';
    }

    private function render_target_location_step(array $source, array $target): void
    {
        $source_name = $this->source_folder_name($source);
        $children = $this->target_browser_children($target);
        $parent_path = (string) ($target['parent_folder_path'] ?? '');
        $final_path = (string) ($target['folder_path'] ?? $this->join_drive_path($parent_path, (string) ($target['destination_folder_name'] ?? $source_name)));
        echo '<section id="archive-target" class="ssf-archive-step ssf-archive-target-step"><div class="ssf-archive-step__heading"><span>3</span><div><h2>Välj var mappen ska placeras</h2><p>Välj den SharePoint-mapp där källmappen ska placeras.</p></div></div>';
        if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('archive-target'); }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_application_archive_save_target">';
        wp_nonce_field('ssf_application_archive_save_target');
        $this->render_target_hidden_fields($target);
        echo '<div class="ssf-archive-target-grid"><div><h3>SharePoint-plats</h3><table class="form-table">';
        echo '<tr><th>SharePoint-site</th><td><input class="regular-text" name="target[site_url]" value="' . esc_attr((string) ($target['site_url'] ?? '')) . '"></td></tr>';
        echo '<tr><th>Dokumentbibliotek</th><td><input class="regular-text" name="target[drive_name]" value="' . esc_attr((string) ($target['drive_name'] ?? '')) . '"></td></tr>';
        echo '<tr><th>Aktuell plats</th><td><input class="regular-text" name="target[parent_folder_path]" value="' . esc_attr($parent_path) . '"><p class="description">' . esc_html($this->display_drive_path($target, $parent_path)) . '</p></td></tr>';
        echo '</table><p>';
        submit_button('Välj aktuell plats som parent', 'secondary', 'submit', false);
        echo '</p></div><div><h3>Destination</h3><table class="form-table">';
        echo '<tr><th>Mapp som ska flyttas</th><td><strong>' . esc_html($source_name) . '</strong></td></tr>';
        echo '<tr><th>Behåll källmappens namn</th><td><label><input type="checkbox" name="target[keep_source_folder_name]" value="1" ' . checked(! empty($target['keep_source_folder_name']), true, false) . '> Behåll källmappens namn</label></td></tr>';
        echo '<tr><th>Mappnamn på mål</th><td><input id="ssf-archive-target-name" class="regular-text" name="target[destination_folder_name]" value="' . esc_attr((string) ($target['destination_folder_name'] ?? $source_name)) . '"' . (! empty($target['keep_source_folder_name']) ? ' readonly' : '') . '></td></tr>';
        echo '</table></div></div>';
        echo '<details><summary>Avancerade tekniska uppgifter</summary><table class="form-table">';
        foreach (array('site_id', 'drive_id', 'list_id', 'parent_folder_name', 'parent_folder_id', 'parent_folder_web_url', 'folder_name', 'folder_id', 'folder_web_url', 'target_state', 'verified_at') as $key) {
            echo '<tr><th>' . esc_html($this->target_fields()[$key] ?? $key) . '</th><td><input class="regular-text" name="target[' . esc_attr($key) . ']" value="' . esc_attr((string) ($target[$key] ?? '')) . '"></td></tr>';
        }
        echo '</table></details></form>';
        $this->render_target_browser($target, $children);
        $this->render_target_create_parent_form($target);
        $this->render_target_preview($source, $target, $final_path);
        echo '</section>';
    }

    private function render_target_browser(array $target, $children): void
    {
        echo '<div class="ssf-archive-browser"><h3>Bläddra i mappar</h3><p><strong>Aktuell plats</strong><br>' . esc_html($this->display_drive_path($target, (string) ($target['parent_folder_path'] ?? ''))) . '</p>';
        echo '<div class="ssf-archive-actions">';
        if ('' !== (string) ($target['parent_folder_path'] ?? '')) {
            $up_target = $target;
            $up_target['parent_folder_path'] = $this->dirname_path((string) ($target['parent_folder_path'] ?? ''));
            $up_target['parent_folder_id'] = '';
            $this->target_post_button('ssf_application_archive_save_target', 'Gå upp en nivå', $up_target);
        }
        $this->target_post_button('ssf_application_archive_save_target', 'Välj aktuell folder', $target, 'primary');
        echo '</div>';
        if (is_wp_error($children)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($children->get_error_message()) . '</p></div>';
        } elseif (empty($children)) {
            echo '<p>Inga undermappar hittades här.</p>';
        } else {
            echo '<ul class="ssf-archive-folder-list">';
            foreach ($children as $child) {
                $child_target = $target;
                $child_target['parent_folder_id'] = (string) ($child['id'] ?? '');
                $child_target['parent_folder_name'] = (string) ($child['name'] ?? '');
                $child_target['parent_folder_path'] = (string) ($child['path'] ?? $this->join_drive_path((string) ($target['parent_folder_path'] ?? ''), (string) ($child['name'] ?? '')));
                $child_target['parent_folder_web_url'] = (string) ($child['web_url'] ?? '');
                echo '<li><span><strong>' . esc_html((string) ($child['name'] ?? '')) . '</strong><small>' . esc_html($this->display_drive_path($target, (string) ($child['path'] ?? ''))) . '</small></span><span>';
                $this->target_post_button('ssf_application_archive_save_target', 'Öppna', $child_target);
                echo '</span></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private function render_target_create_parent_form(array $target): void
    {
        echo '<details class="ssf-archive-create-folder"><summary>+ Skapa ny mapp</summary><p>Skapa en manuell mellanliggande mapp medan du bläddrar.</p><p><strong>Skapa mapp under:</strong><br>' . esc_html($this->display_drive_path($target, (string) ($target['parent_folder_path'] ?? ''))) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_application_archive_create_browser_folder">';
        wp_nonce_field('ssf_application_archive_create_browser_folder');
        $this->render_target_hidden_fields($target);
        echo '<p><label><strong>Namn</strong><br><input class="regular-text" name="new_folder_name" value=""></label></p><div class="ssf-archive-actions"><a class="button" href="' . esc_url(admin_url('admin.php?page=ssf-application-archive-migration')) . '">Avbryt</a>';
        submit_button('Skapa mapp', 'primary', 'submit', false);
        echo '</div></form></details>';
    }

    private function render_target_preview(array $source, array $target, string $final_path): void
    {
        $state = (string) ($target['target_state'] ?? 'destination_parent_selected');
        $selected_path = '/' . trim((string) ($target['parent_folder_path'] ?? ''), '/');
        $display_final_path = '/' . trim($final_path, '/');
        echo '<div class="ssf-archive-preview">';
        if (in_array($state, array('destination_parent_selected', 'final_destination_missing'), true)) {
            echo '<h3>Vald plats</h3><p><code>' . esc_html($selected_path) . '</code></p><p class="ssf-archive-verified-state"><span aria-hidden="true">✓</span> Verifierad</p>';
            echo '<h3>Mapp som skapas automatiskt</h3><p><code>' . esc_html($display_final_path) . '</code></p>';
        }
        if ('final_destination_missing' === $state) {
            $this->button('ssf_application_archive_create_target_folder', 'Skapa och verifiera mål', 'primary');
            echo '<p class="description">Mappen skapas automatiskt i SharePoint och verifieras innan du går vidare.</p>';
        } elseif ('final_destination_exists' === $state) {
            echo '<h3>Mappen finns redan:</h3><p><code>' . esc_html($display_final_path) . '</code></p><div class="ssf-archive-actions">';
            $this->button('ssf_application_archive_use_existing_target', 'Använd befintlig mapp', 'primary');
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=ssf-application-archive-migration')) . '">Välj annan plats</a>';
            echo '<a class="button" href="#ssf-archive-target-name">Ändra mappnamn</a></div>';
        } elseif (in_array($state, array('final_destination_created', 'final_destination_verified'), true)) {
            echo '<h3>Slutligt mål</h3><p><code>' . esc_html($display_final_path) . '</code></p><p class="ssf-archive-verified-state"><span aria-hidden="true">✓</span> Mappen finns och är verifierad i SharePoint</p>';
        }
        echo '</div>';
    }

    private function render_schema_inventory(array $inventory): void
    {
        if (empty($inventory['columns'])) { echo '<p>Status: EJ TESTAD</p>'; return; }
        echo '<p><strong>Läst:</strong> ' . esc_html((string) ($inventory['read_at'] ?? '')) . ' · <strong>Kolumner:</strong> ' . esc_html((string) count($inventory['columns'])) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>Internt namn</th><th>Visningsnamn</th><th>Typ</th><th>Klass</th><th>Inställningar</th></tr></thead><tbody>';
        foreach ($inventory['columns'] as $column) {
            echo '<tr><td><code>' . esc_html((string) $column['name']) . '</code></td><td>' . esc_html((string) $column['display_name']) . '</td><td>' . esc_html((string) $column['type']) . '</td><td>' . esc_html((string) $column['classification']) . '</td><td><code>' . esc_html(wp_json_encode($column['settings'], JSON_UNESCAPED_UNICODE)) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_schema_comparison(array $comparison): void
    {
        if (empty($comparison['columns'])) { echo '<p>Status: EJ TESTAD</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Internt namn</th><th>Typ</th><th>Resultat</th><th>Åtgärd</th></tr></thead><tbody>';
        foreach ($comparison['columns'] as $row) {
            echo '<tr><td><code>' . esc_html((string) $row['internal_name']) . '</code></td><td>' . esc_html((string) $row['type']) . '</td><td>' . esc_html((string) $row['label']) . '</td><td>' . esc_html((string) $row['action']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_check_steps(array $result): void
    {
        if (empty($result['steps'])) { echo '<p>Status: EJ TESTAD</p>'; return; }
        echo '<table class="widefat striped"><tbody>';
        foreach ($result['steps'] as $step) { echo '<tr><th>' . esc_html((string) ($step['label'] ?? 'Kontroll')) . '</th><td>' . esc_html($this->status_label($step['ok'] ?? null)) . '</td><td>' . esc_html((string) ($step['message'] ?? '')) . '</td></tr>'; }
        echo '</tbody></table>';
    }

    private function batch_button(string $command, string $label): void
    {
        $url = wp_nonce_url(admin_url('admin-post.php?action=ssf_application_archive_batch_control&command=' . rawurlencode($command)), 'ssf_application_archive_batch_control');
        echo '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a> ';
    }

    private function column_schema(array $location)
    {
        $result = $this->request('GET', $this->columns_path($location) . '?$expand=sourceColumn&$select=id,name,displayName,description,columnGroup,required,hidden,readOnly,indexed,enforceUniqueValues,defaultValue,text,choice,number,currency,boolean,dateTime,personOrGroup,lookup,hyperlinkOrPicture,calculated,term,sourceColumn');
        if (is_wp_error($result)) { return $result; }
        $columns = array();
        foreach ((array) ($result['value'] ?? array()) as $column) { $columns[] = $this->normalize_column((array) $column); }
        return $columns;
    }

    private function normalize_column(array $column): array
    {
        $types = array('text', 'choice', 'number', 'currency', 'boolean', 'dateTime', 'personOrGroup', 'lookup', 'hyperlinkOrPicture', 'calculated', 'term');
        $type = 'unknown';
        foreach ($types as $candidate) { if (array_key_exists($candidate, $column)) { $type = $candidate; break; } }
        $system_names = array('id', 'created', 'modified', 'author', 'editor', 'contenttype', '_uiversionstring', 'attachments', 'edit', 'linktitle');
        $name = (string) ($column['name'] ?? '');
        $source_column = (array) ($column['sourceColumn'] ?? array());
        $classification = in_array(strtolower($name), $system_names, true) || ! empty($column['readOnly']) || ! empty($column['hidden']) ? 'SYSTEM' : (! empty($source_column) ? 'CONTENT TYPE' : 'CUSTOM');
        $settings = (array) ($column[$type] ?? array());
        $schema_status = 'SUPPORTED';
        if ('choice' === $type) {
            $display_as = (string) ($settings['displayAs'] ?? '');
            $settings = array(
                'choices' => array_values(array_map('strval', (array) ($settings['choices'] ?? array()))),
                'allowTextEntry' => ! empty($settings['allowTextEntry']),
                'displayAs' => $display_as,
            );
            if ('checkBoxes' === $display_as) {
                $type = 'multiChoice';
            } elseif (! in_array($display_as, array('dropDownMenu', 'radioButtons'), true)) {
                $schema_status = 'AMBIGUOUS';
            }
        }
        foreach (array('required', 'indexed', 'enforceUniqueValues', 'defaultValue', 'description', 'columnGroup') as $key) { if (array_key_exists($key, $column)) { $settings[$key] = $column[$key]; } }
        return array('id' => (string) ($column['id'] ?? ''), 'name' => $name, 'display_name' => (string) ($column['displayName'] ?? $name), 'type' => $type, 'classification' => $classification, 'schema_status' => $schema_status, 'settings' => $settings, 'raw' => $column);
    }

    private function schema_comparison()
    {
        $source_state = (array) get_option(self::SOURCE_SCHEMA_OPTION, array());
        if (empty($source_state['columns'])) { return new WP_Error('source_schema_missing', 'Läs källans kolumnschema först.'); }
        $target = $this->resolve_target(true);
        if (is_wp_error($target)) { return $target; }
        $target_columns = $this->column_schema($target);
        if (is_wp_error($target_columns)) { return $target_columns; }
        $by_name = array();
        $by_display_name = array();
        foreach ($target_columns as $column) { $by_name[strtolower($column['name'])] = $column; $by_display_name[strtolower($column['display_name'])] = $column; }
        $supported = array('text', 'choice', 'multiChoice', 'number', 'currency', 'boolean', 'dateTime');
        $rows = array();
        foreach ($source_state['columns'] as $source) {
            $status = 'SYSTEM'; $label = 'SYSTEM / NO ACTION'; $action = 'Ingen'; $target_column = null;
            if ('CUSTOM' === $source['classification']) {
                if ('SUPPORTED' !== ($source['schema_status'] ?? 'SUPPORTED') || ! in_array($source['type'], $supported, true)) { $status = 'UNSUPPORTED'; $label = 'UNSUPPORTED / MANUELL KONTROLL'; $action = 'Manuell kontroll'; }
                elseif (! isset($by_name[strtolower($source['name'])]) && isset($by_display_name[strtolower($source['display_name'])])) { $status = 'CONFLICT'; $label = 'CONFLICT - INTERNAL-NAME MISMATCH - MANUELL KONTROLL KRÄVS'; $action = 'Blockerad'; }
                elseif (! isset($by_name[strtolower($source['name'])])) { $status = 'MISSING'; $label = 'MISSING'; $action = 'Skapa'; }
                else {
                    $target_column = $by_name[strtolower($source['name'])];
                    if ($source['type'] === $target_column['type'] && $this->comparable_settings($source) === $this->comparable_settings($target_column)) { $status = 'EXACT'; $label = 'EXACT MATCH'; $action = 'Ingen'; }
                    else { $status = 'CONFLICT'; $label = 'CONFLICT - MANUELL KONTROLL KRÄVS'; $action = 'Blockerad'; }
                }
            }
            $rows[] = array('internal_name' => $source['name'], 'type' => $source['type'], 'status' => $status, 'label' => $label, 'action' => $action, 'source' => $source, 'target' => $target_column);
        }
        return array('compared_at' => gmdate('c'), 'columns' => $rows);
    }

    private function comparable_settings(array $column): string
    {
        $settings = (array) ($column['settings'] ?? array());
        unset($settings['description'], $settings['columnGroup']);
        ksort($settings);
        return wp_json_encode($settings);
    }

    private function column_create_payload(array $column)
    {
        if ('CUSTOM' !== ($column['classification'] ?? '') || ! in_array($column['type'] ?? '', array('text', 'choice', 'multiChoice', 'number', 'currency', 'boolean', 'dateTime'), true)) { return new WP_Error('unsupported_column', 'Kolumntypen kräver manuell kontroll.'); }
        if ('SUPPORTED' !== ($column['schema_status'] ?? 'SUPPORTED')) { return new WP_Error('ambiguous_column', 'Kolumnschemat är tvetydigt och kräver manuell kontroll.'); }
        $raw = (array) ($column['raw'] ?? array());
        $type = (string) $column['type'];
        $facet = 'multiChoice' === $type ? 'choice' : $type;
        $payload = array('name' => (string) $column['name'], 'displayName' => (string) $column['display_name'], 'description' => (string) ($raw['description'] ?? ''), 'required' => ! empty($raw['required']), $facet => (object) ((array) ($raw[$facet] ?? array())));
        if (isset($raw['defaultValue'])) { $payload['defaultValue'] = $raw['defaultValue']; }
        if (! empty($raw['indexed'])) { $payload['indexed'] = true; }
        if (! empty($raw['enforceUniqueValues'])) { $payload['enforceUniqueValues'] = true; }
        return $payload;
    }

    private function columns_path(array $location): string
    {
        return 'sites/' . rawurlencode((string) $location['site_id']) . '/lists/' . rawurlencode((string) $location['list_id']) . '/columns';
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
        return array('site_url' => 'SharePoint Site URL', 'site_id' => 'Site ID', 'drive_name' => 'Bibliotek', 'drive_id' => 'Drive ID', 'list_id' => 'List ID', 'parent_folder_path' => 'Parent-mappsökväg', 'parent_folder_name' => 'Parent-mappnamn', 'parent_folder_id' => 'Parent DriveItem ID', 'parent_folder_web_url' => 'Parent webbadress', 'destination_folder_name' => 'Mappnamn på mål', 'keep_source_folder_name' => 'Behåll källmappens namn', 'folder_path' => 'Final mappsökväg', 'folder_name' => 'Final mappnamn', 'folder_id' => 'Final DriveItem ID', 'folder_web_url' => 'Final webbadress', 'target_state' => 'Målstatus', 'verified_at' => 'Verifierad');
    }

    private function default_target(): array
    {
        return array('site_url' => 'https://tradtionsfartyg.sharepoint.com/sites/styrelsen9', 'site_id' => '', 'drive_name' => 'Dokument', 'drive_id' => '', 'list_id' => '', 'parent_folder_path' => 'General/Medlemskap', 'parent_folder_name' => 'Medlemskap', 'parent_folder_id' => '', 'parent_folder_web_url' => '', 'destination_folder_name' => '', 'keep_source_folder_name' => '1', 'folder_path' => '', 'folder_name' => '', 'folder_id' => '', 'folder_web_url' => '', 'target_state' => '', 'verified_at' => '', 'existing_folder_id' => '', 'existing_folder_web_url' => '');
    }

    private function default_source(): array
    {
        $source = array('site_url' => '', 'site_id' => '', 'drive_name' => 'Dokument', 'drive_id' => '', 'list_id' => '', 'folder_path' => 'Medlemsansökningar', 'folder_name' => 'Medlemsansökningar', 'folder_id' => '', 'folder_web_url' => '');
        if (class_exists('SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations')) {
            $profile = (array) \SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations::get('membership_applications', $this->environment());
            foreach (array_keys($source) as $key) { if (isset($profile[$key])) { $source[$key] = $profile[$key]; } }
            if (empty($source['site_url']) && ! empty($profile['hostname'])) { $source['site_url'] = 'https://' . trim((string) $profile['hostname'], '/') . '/' . ltrim((string) ($profile['site_path'] ?? ''), '/'); }
        }
        return $source;
    }

    private function source(): array
    {
        $settings = $this->settings();
        return array_merge($this->default_source(), (array) ($settings['sources'][$this->environment()] ?? array()));
    }

    private function target(): array
    {
        $settings = $this->settings();
        return $this->normalize_target(array_merge($this->default_target(), (array) ($settings['targets'][$this->environment()] ?? array())));
    }

    private function posted_target(): array
    {
        $input = (array) wp_unslash($_POST['target'] ?? array());
        $target = $this->target();
        foreach (array_keys($this->target_fields()) as $key) {
            if (! array_key_exists($key, $input)) { continue; }
            $target[$key] = in_array($key, array('site_url', 'folder_web_url', 'parent_folder_web_url'), true) ? esc_url_raw((string) $input[$key]) : sanitize_text_field((string) $input[$key]);
        }
        $target['keep_source_folder_name'] = ! empty($input['keep_source_folder_name']) ? '1' : '';
        if (! empty($target['keep_source_folder_name'])) { $target['destination_folder_name'] = $this->source_folder_name($this->source()); }
        $target['parent_folder_path'] = trim((string) ($target['parent_folder_path'] ?? ''), '/');
        $target['_has_new_target_fields'] = '1';
        $target['folder_id'] = '';
        $target['folder_web_url'] = '';
        $target['existing_folder_id'] = '';
        $target['existing_folder_web_url'] = '';
        $target['verified_at'] = '';
        return $this->normalize_target($target);
    }

    private function normalize_target(array $target): array
    {
        $source_name = $this->source_folder_name($this->source());
        $stored = (array) ($this->settings()['targets'][$this->environment()] ?? array());
        $had_new_fields = ! empty($target['_has_new_target_fields']) || array_key_exists('parent_folder_path', $stored) || array_key_exists('destination_folder_name', $stored);
        $folder_path = trim((string) ($target['folder_path'] ?? ''), '/');
        if (! $had_new_fields && $folder_path) {
            $target['parent_folder_path'] = $this->dirname_path($folder_path);
            $target['destination_folder_name'] = $this->basename_path($folder_path);
            $target['keep_source_folder_name'] = 0 === strcasecmp((string) $target['destination_folder_name'], $source_name) ? '1' : '';
        }
        $target['parent_folder_path'] = trim((string) ($target['parent_folder_path'] ?? ''), '/');
        if (! empty($target['keep_source_folder_name']) || '' === trim((string) ($target['destination_folder_name'] ?? ''))) {
            $target['destination_folder_name'] = $source_name;
            $target['keep_source_folder_name'] = '1';
        }
        $target['destination_folder_name'] = sanitize_text_field((string) $target['destination_folder_name']);
        $target['folder_path'] = $this->join_drive_path((string) ($target['parent_folder_path'] ?? ''), (string) $target['destination_folder_name']);
        $target['folder_name'] = (string) $target['destination_folder_name'];
        if (! empty($target['folder_id']) && empty($target['target_state'])) { $target['target_state'] = 'final_destination_verified'; }
        unset($target['_has_new_target_fields']);
        return $target;
    }

    private function render_target_hidden_fields(array $target): void
    {
        foreach (array('site_id', 'drive_id', 'list_id', 'parent_folder_id', 'parent_folder_name', 'parent_folder_web_url', 'folder_path', 'folder_id', 'folder_web_url', 'target_state', 'verified_at') as $key) {
            echo '<input type="hidden" name="target[' . esc_attr($key) . ']" value="' . esc_attr((string) ($target[$key] ?? '')) . '">';
        }
    }

    private function target_post_button(string $action, string $label, array $target, string $class = 'secondary'): void
    {
        echo '<form method="post" class="ssf-archive-inline-form" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        foreach ($target as $key => $value) {
            if (is_scalar($value)) { echo '<input type="hidden" name="target[' . esc_attr((string) $key) . ']" value="' . esc_attr((string) $value) . '">'; }
        }
        submit_button($label, $class, 'submit', false);
        echo '</form>';
    }

    private function resolve_target(bool $save = false)
    {
        $target = $this->target();
        if (empty($target['folder_id']) || ! in_array((string) ($target['target_state'] ?? ''), array('final_destination_created', 'final_destination_verified'), true)) {
            return new WP_Error('migration_target_not_verified', 'Målmappen är inte verifierad. Skapa och verifiera målmappen eller använd en befintlig verifierad mapp först.');
        }
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

    private function resolve_target_parent(bool $save = false)
    {
        $target = $this->target();
        if (empty($target['site_id'])) {
            $site_path = $this->site_lookup_path((string) ($target['site_url'] ?? ''));
            if (! $site_path) { return new WP_Error('migration_target_site_url_missing', 'Ange en giltig SharePoint Site URL.'); }
            $site = $this->request('GET', $site_path . '?$select=id,displayName,webUrl');
            if (is_wp_error($site)) { return $site; }
            $target['site_id'] = sanitize_text_field((string) ($site['id'] ?? ''));
            $target['site_url'] = esc_url_raw((string) ($site['webUrl'] ?? $target['site_url']));
        } else {
            $site = $this->request('GET', 'sites/' . rawurlencode((string) $target['site_id']) . '?$select=id,displayName,webUrl');
            if (is_wp_error($site)) { return $site; }
        }
        if (empty($target['drive_id'])) {
            $drives = $this->request('GET', 'sites/' . rawurlencode((string) $target['site_id']) . '/drives?$select=id,name,webUrl');
            if (is_wp_error($drives)) { return $drives; }
            foreach ((array) ($drives['value'] ?? array()) as $drive) {
                if (0 === strcasecmp((string) ($target['drive_name'] ?: 'Dokument'), (string) ($drive['name'] ?? ''))) {
                    $target['drive_id'] = sanitize_text_field((string) ($drive['id'] ?? ''));
                    $target['drive_name'] = sanitize_text_field((string) ($drive['name'] ?? $target['drive_name']));
                    break;
                }
            }
            if (empty($target['drive_id'])) { return new WP_Error('migration_target_drive_missing', 'Dokumentbiblioteket kunde inte hittas.'); }
        } else {
            $drive = $this->request('GET', $this->drive_base($target) . '?$select=id,name,webUrl');
            if (is_wp_error($drive)) { return $drive; }
            $target['drive_name'] = sanitize_text_field((string) ($drive['name'] ?? $target['drive_name']));
        }
        if (empty($target['list_id'])) {
            $list = $this->request('GET', $this->drive_base($target) . '/list?$select=id,displayName,webUrl');
            if (is_wp_error($list)) { return $list; }
            $target['list_id'] = sanitize_text_field((string) ($list['id'] ?? ''));
        }
        if (empty($target['parent_folder_id'])) {
            $parent_path = trim((string) ($target['parent_folder_path'] ?? ''), '/');
            $folder = '' === $parent_path
                ? $this->request('GET', $this->drive_base($target) . '/root?$select=id,name,folder,webUrl,parentReference')
                : $this->request('GET', $this->drive_base($target) . '/root:/' . $this->encode_drive_path($parent_path) . '?$select=id,name,folder,webUrl,parentReference');
            if (is_wp_error($folder)) { return $this->friendly_parent_error($folder, $target); }
        } else {
            $folder = $this->request('GET', $this->item_path($target, (string) $target['parent_folder_id']) . '?$select=id,name,folder,webUrl,parentReference');
            if (is_wp_error($folder)) { return $this->friendly_parent_error($folder, $target); }
        }
        if (empty($folder['folder'])) { return new WP_Error('migration_parent_not_folder', 'Den valda SharePoint-posten är inte en mapp.'); }
        $target['parent_folder_id'] = sanitize_text_field((string) ($folder['id'] ?? ''));
        $target['parent_folder_name'] = sanitize_text_field((string) ($folder['name'] ?? $target['parent_folder_name']));
        $target['parent_folder_web_url'] = esc_url_raw((string) ($folder['webUrl'] ?? ''));
        $target = $this->normalize_target($target);
        if ($save) { $this->save_target_state($target); }
        return $target;
    }

    private function refresh_target_state(array $target, bool $save)
    {
        $target = $this->normalize_target($target);
        $target['target_state'] = 'destination_parent_selected';
        $target['folder_id'] = '';
        $target['folder_web_url'] = '';
        $target['existing_folder_id'] = '';
        $target['existing_folder_web_url'] = '';
        $existing = $this->find_final_target($target);
        if (is_wp_error($existing)) { return $existing; }
        if ($existing) {
            $target['target_state'] = 'final_destination_exists';
            $target['existing_folder_id'] = sanitize_text_field((string) ($existing['id'] ?? ''));
            $target['existing_folder_web_url'] = esc_url_raw((string) ($existing['webUrl'] ?? ''));
        } else {
            $target['target_state'] = 'final_destination_missing';
        }
        if ($save) { $this->save_target_state($target); }
        return $target;
    }

    private function create_and_verify_final_target(array $target)
    {
        $existing = $this->find_final_target($target);
        if (is_wp_error($existing)) { return $existing; }
        if ($existing) { return new WP_Error('migration_target_folder_exists', 'Mappen finns redan. Använd befintlig mapp, välj annan plats eller ändra mappnamn.'); }
        $name_check = $this->validate_sharepoint_folder_name((string) $target['destination_folder_name']);
        if (is_wp_error($name_check)) { return $name_check; }
        $created = $this->request('POST', $this->children_path($target, (string) $target['parent_folder_id']), array('name' => (string) $target['destination_folder_name'], 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'));
        if (is_wp_error($created)) { return new WP_Error('migration_target_create_failed', 'Mappen kunde inte skapas.', $created->get_error_data()); }
        $verified = $this->verify_folder_item($target, (string) ($created['id'] ?? ''));
        if (is_wp_error($verified)) { return new WP_Error('migration_target_verify_failed', 'Mappen skapades men kunde inte verifieras.', $verified->get_error_data()); }
        return array_merge($target, $this->target_folder_reference($verified, (string) $target['folder_path']));
    }

    private function find_final_target(array $target)
    {
        $children = $this->request('GET', $this->children_path($target, (string) $target['parent_folder_id']) . '?$select=id,name,folder,webUrl');
        if (is_wp_error($children)) { return $children; }
        foreach ((array) ($children['value'] ?? array()) as $child) {
            if (isset($child['folder']) && 0 === strcasecmp((string) $target['destination_folder_name'], (string) ($child['name'] ?? ''))) { return $child; }
        }
        return array();
    }

    private function verify_folder_item(array $target, string $folder_id)
    {
        if (! $folder_id) { return new WP_Error('migration_folder_id_missing', 'Mappens DriveItem ID saknas.'); }
        $folder = $this->request('GET', $this->item_path($target, $folder_id) . '?$select=id,name,folder,webUrl,parentReference');
        if (is_wp_error($folder)) { return $folder; }
        if (empty($folder['folder'])) { return new WP_Error('migration_target_not_folder', 'Målplatsen är inte en SharePoint-mapp.'); }
        return $folder;
    }

    private function target_folder_reference(array $folder, string $path): array
    {
        return array('folder_id' => sanitize_text_field((string) ($folder['id'] ?? '')), 'folder_name' => sanitize_text_field((string) ($folder['name'] ?? '')), 'folder_path' => trim($path, '/'), 'folder_web_url' => esc_url_raw((string) ($folder['webUrl'] ?? '')));
    }

    private function persist_verified_target(array $target, string $state): void
    {
        $target['target_state'] = 'final_destination_created' === $state ? 'final_destination_created' : 'final_destination_verified';
        $target['verified_at'] = gmdate('c');
        $target['existing_folder_id'] = '';
        $target['existing_folder_web_url'] = '';
        $this->save_target_state($target);
    }

    private function save_target_state(array $target): void
    {
        $settings = $this->settings();
        $settings['targets'][$this->environment()] = $this->normalize_target($target);
        update_option(self::OPTION, $settings, false);
    }

    private function reset_target_dependent_state(): void
    {
        delete_option(self::READINESS_OPTION);
        delete_option(self::WRITE_TEST_OPTION);
        delete_option(self::SCHEMA_COMPARE_OPTION);
        delete_option(self::SCHEMA_SYNC_OPTION);
        delete_option(self::PLAN_OPTION);
        delete_option(self::BATCH_OPTION);
    }

    private function resolve_location(string $kind, bool $save = false)
    {
        if ('target' === $kind) { return $this->resolve_target($save); }
        $location = $this->source();
        if (empty($location['site_id'])) {
            $path = $this->site_lookup_path((string) ($location['site_url'] ?? ''));
            if (! $path) { return new WP_Error('migration_source_site_url_missing', 'Ange en giltig SharePoint Site URL för källan.'); }
            $site = $this->request('GET', $path . '?$select=id,displayName,webUrl');
            if (is_wp_error($site)) { return $site; }
            $location['site_id'] = sanitize_text_field((string) ($site['id'] ?? ''));
            $location['site_url'] = esc_url_raw((string) ($site['webUrl'] ?? $location['site_url']));
        }
        if (empty($location['drive_id'])) {
            $drives = $this->request('GET', 'sites/' . rawurlencode((string) $location['site_id']) . '/drives?$select=id,name,webUrl');
            if (is_wp_error($drives)) { return $drives; }
            foreach ((array) ($drives['value'] ?? array()) as $drive) { if (0 === strcasecmp((string) $location['drive_name'], (string) ($drive['name'] ?? ''))) { $location['drive_id'] = sanitize_text_field((string) ($drive['id'] ?? '')); break; } }
            if (empty($location['drive_id'])) { return new WP_Error('migration_source_drive_missing', 'Källans dokumentbibliotek kunde inte hittas.'); }
        }
        if (empty($location['list_id'])) {
            $list = $this->request('GET', $this->drive_base($location) . '/list?$select=id,displayName,webUrl');
            if (is_wp_error($list)) { return $list; }
            $location['list_id'] = sanitize_text_field((string) ($list['id'] ?? ''));
        }
        if (empty($location['folder_id'])) {
            $folder = $this->request('GET', $this->drive_base($location) . '/root:/' . $this->encode_drive_path((string) $location['folder_path']) . '?$select=id,name,folder,webUrl,parentReference');
            if (is_wp_error($folder)) { return $folder; }
            $location['folder_id'] = sanitize_text_field((string) ($folder['id'] ?? ''));
            $location['folder_name'] = sanitize_text_field((string) ($folder['name'] ?? ''));
            $location['folder_web_url'] = esc_url_raw((string) ($folder['webUrl'] ?? ''));
        }
        if ($save) { $settings = $this->settings(); $settings['sources'][$this->environment()] = $location; update_option(self::OPTION, $settings, false); }
        return $location;
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

    private function target_write_allowed(array $target): bool
    {
        if (! class_exists('SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations')) { return false; }
        return \SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations::write_allowed_for_profile('membership_applications', $this->environment(), $target);
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

    private function source_folder_name(array $source): string
    {
        $name = $this->basename_path((string) ($source['folder_path'] ?? ''));
        if (! $name) { $name = sanitize_text_field((string) ($source['folder_name'] ?? 'Medlemsansökningar')); }
        return $name ?: 'Medlemsansökningar';
    }

    private function join_drive_path(string $parent, string $child): string
    {
        return trim(trim($parent, '/') . '/' . trim($child, '/'), '/');
    }

    private function dirname_path(string $path): string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static function ($part): bool { return '' !== trim($part); }));
        array_pop($parts);
        return implode('/', $parts);
    }

    private function basename_path(string $path): string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static function ($part): bool { return '' !== trim($part); }));
        return $parts ? (string) end($parts) : '';
    }

    private function display_drive_path(array $target, string $path): string
    {
        return '/' . trim((string) ($target['drive_name'] ?: 'Dokumentbibliotek'), '/') . ('' !== trim($path, '/') ? '/' . trim($path, '/') : '') . '/';
    }

    private function validate_sharepoint_folder_name(string $name)
    {
        $name = trim($name);
        if ('' === $name) { return new WP_Error('migration_target_folder_name_missing', 'Ange ett mappnamn för målet.'); }
        if (preg_match('/["*:<>?\\\\\/|]/', $name) || in_array($name, array('.', '..'), true) || preg_match('/[. ]$/', $name)) {
            return new WP_Error('migration_target_folder_name_invalid', 'Mappnamnet innehåller tecken som inte är tillåtna i SharePoint.');
        }
        return true;
    }

    private function target_state_label(string $state): string
    {
        $labels = array(
            'destination_parent_selected' => 'Platsen är vald',
            'final_destination_missing' => 'Målet är redo att skapas automatiskt',
            'final_destination_exists' => 'Mappen finns redan',
            'final_destination_created' => 'Slutligt mål är verifierat i SharePoint',
            'final_destination_verified' => 'Slutligt mål är verifierat i SharePoint',
        );
        return $labels[$state] ?? 'Inte verifierad';
    }

    private function target_browser_children(array $target)
    {
        if (empty($target['drive_id']) && empty($target['site_id']) && empty($target['site_url'])) { return array(); }
        $resolved = $this->resolve_target_parent(false);
        if (is_wp_error($resolved)) { return $resolved; }
        $result = $this->request('GET', $this->children_path($resolved, (string) $resolved['parent_folder_id']) . '?$select=id,name,folder,webUrl,parentReference');
        if (is_wp_error($result)) { return $result; }
        $folders = array();
        foreach ((array) ($result['value'] ?? array()) as $item) {
            if (empty($item['folder'])) { continue; }
            $folders[] = array(
                'id' => sanitize_text_field((string) ($item['id'] ?? '')),
                'name' => sanitize_text_field((string) ($item['name'] ?? '')),
                'path' => $this->join_drive_path((string) ($resolved['parent_folder_path'] ?? ''), (string) ($item['name'] ?? '')),
                'web_url' => esc_url_raw((string) ($item['webUrl'] ?? '')),
            );
        }
        return $folders;
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

    private function friendly_parent_error(WP_Error $error, array $target): WP_Error
    {
        $data = (array) $error->get_error_data();
        $status = (int) ($data['http_status'] ?? $data['status'] ?? 0);
        if (404 === $status || 'itemnotfound' === strtolower((string) ($data['graph_code'] ?? ''))) {
            return new WP_Error('migration_parent_folder_missing', 'Kunde inte hitta den valda SharePoint-mappen.', $data);
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
        $action = sanitize_key((string) ($_REQUEST['action'] ?? ''));
        $sections = array(
            'ssf_application_archive_save_source' => 'archive-source',
            'ssf_application_archive_read_source_schema' => 'archive-inventory',
            'ssf_application_archive_save_target' => 'archive-target',
            'ssf_application_archive_create_target_folder' => 'archive-target',
            'ssf_application_archive_use_existing_target' => 'archive-target',
            'ssf_application_archive_create_browser_folder' => 'archive-target',
            'ssf_application_archive_compare_schema' => 'archive-schema',
            'ssf_application_archive_preview_schema' => 'archive-schema',
            'ssf_application_archive_create_columns' => 'archive-schema',
            'ssf_application_archive_verify_schema' => 'archive-schema',
            'ssf_application_archive_write_test' => 'archive-write-test',
            'ssf_application_archive_plan' => 'archive-plan',
            'ssf_application_archive_migrate_one' => 'archive-migrate',
            'ssf_application_archive_batch' => 'archive-batch',
            'ssf_application_archive_batch_control' => 'archive-batch',
            'ssf_application_archive_readiness' => 'archive-cutover',
            'ssf_application_archive_cutover' => 'archive-cutover',
        );
        if (class_exists('SSF_Admin_Feedback')) {
            SSF_Admin_Feedback::redirect('ssf-application-archive-migration', $sections[$action] ?? 'archive-target', $type, $message);
        }
        wp_safe_redirect(add_query_arg(array('page' => 'ssf-application-archive-migration', 'ssf_archive_message' => rawurlencode($message), 'ssf_archive_type' => $type), admin_url('admin.php')));
        exit;
    }

    private function notice(): void
    {
        $message = sanitize_text_field((string) wp_unslash($_GET['ssf_archive_message'] ?? ''));
        if (! $message) { return; }
        $type = 'error' === sanitize_key((string) ($_GET['ssf_archive_type'] ?? '')) ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
        $diagnostic = get_transient(self::SCHEMA_ERROR_PREFIX . get_current_user_id());
        if ('error' === $type && is_array($diagnostic)) {
            delete_transient(self::SCHEMA_ERROR_PREFIX . get_current_user_id());
            echo '<details><summary>Tekniska detaljer</summary><p><code>' . esc_html((string) ($diagnostic['code'] ?? 'graph_error')) . '</code></p><p>' . esc_html((string) ($diagnostic['message'] ?? 'Microsoft Graph returnerade ett fel.')) . '</p></details>';
        }
    }

    private function safe_schema_error(WP_Error $error): array
    {
        $message = sanitize_text_field($error->get_error_message());
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $message);
        $message = preg_replace('/(client_secret|access_token|refresh_token)\s*[=:]\s*[^\s,;]+/i', '$1=[REDACTED]', $message);
        return array(
            'code' => sanitize_key((string) $error->get_error_code()),
            'message' => $message ?: 'Microsoft Graph returnerade ett fel.',
        );
    }
}
