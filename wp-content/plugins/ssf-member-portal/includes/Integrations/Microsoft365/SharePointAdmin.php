<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

use SSF\MemberPortal\Core\Capabilities;
use SSF\MemberPortal\Core\Logger;

if (! defined('ABSPATH')) {
    exit;
}

final class SharePointAdmin
{
    private SharePointDiscovery $discovery;

    public function __construct(GraphClient $graph)
    {
        $this->discovery = new SharePointDiscovery($graph);
        add_action('admin_post_ssf_save_sharepoint_destination', array($this, 'save'));
        add_action('admin_post_ssf_save_sharepoint_policy', array($this, 'save_policy'));
        add_action('admin_post_ssf_save_sharepoint_credentials', array($this, 'save_credentials'));
        add_action('wp_ajax_ssf_sharepoint_admin', array($this, 'ajax'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    public function enqueue(string $hook): void
    {
        if ('toplevel_page_ssf-member-portal-microsoft365' !== $hook && 'admin_page_ssf-member-portal-microsoft365' !== $hook) {
            $page = sanitize_key((string) ($_GET['page'] ?? ''));
            if ('ssf-member-portal-microsoft365' !== $page) {
                return;
            }
        }
        wp_enqueue_style('ssf-sharepoint-admin', SSF_MEMBER_PORTAL_URL . 'assets/css/sharepoint-admin.css', array(), SSF_MEMBER_PORTAL_VERSION);
        wp_enqueue_script('ssf-sharepoint-admin', SSF_MEMBER_PORTAL_URL . 'assets/js/sharepoint-admin.js', array(), SSF_MEMBER_PORTAL_VERSION, true);
        wp_localize_script('ssf-sharepoint-admin', 'ssfSharePointAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ssf_sharepoint_admin'),
            'currentEnvironment' => SharePointDestinations::environment(),
        ));
    }

    public function render(): void
    {
        $this->enqueue('');
        $definitions = SharePointDestinations::definitions();
        $current_environment = SharePointDestinations::environment();
        $destination = sanitize_key((string) ($_GET['destination'] ?? 'annual_meetings'));
        if (! isset($definitions[$destination])) {
            $destination = 'annual_meetings';
        }
        $profile_environment = $current_environment;
        $profile = SharePointDestinations::get($destination, $profile_environment);
        $definition = $definitions[$destination];
        ?>
        <section id="sharepoint" class="ssf-sp-admin" data-ssf-sharepoint-admin data-destination="<?php echo esc_attr($destination); ?>" data-environment="<?php echo esc_attr($profile_environment); ?>">
            <header class="ssf-sp-admin__header">
                <div><h2><?php esc_html_e('SharePoint-integrationer', 'ssf-member-portal'); ?></h2><p><?php esc_html_e('Välj känd SharePoint-adress, dokumentbibliotek och mapp. Tekniska ID:n identifieras automatiskt när Graph-behörigheten tillåter det.', 'ssf-member-portal'); ?></p></div>
                <span class="ssf-sp-environment ssf-sp-environment--<?php echo esc_attr($current_environment); ?>"><?php echo esc_html(strtoupper($current_environment)); ?></span>
            </header>
            <?php if (class_exists('SSF_Admin_Feedback')) { \SSF_Admin_Feedback::render_inline('sharepoint'); } ?>

            <?php $this->render_credentials(); ?>

            <div class="ssf-sp-overview">
                <?php foreach ($definitions as $key => $item) : $active = SharePointDestinations::get($key); $missing = SharePointDestinations::missing($key); $health = SharePointDestinations::health($key); ?>
                    <article class="ssf-sp-destination">
                        <div class="ssf-sp-destination__heading"><h3><?php echo esc_html($item['label']); ?></h3><span class="ssf-sp-status ssf-sp-status--<?php echo esc_attr($missing ? 'missing' : (! empty($health['ok']) ? 'ok' : 'unknown')); ?>"><?php echo esc_html($missing ? 'Ej klar' : (! empty($health['ok']) ? 'Ansluten' : 'Konfigurerad')); ?></span></div>
                        <dl><div><dt>Site</dt><dd><?php echo esc_html($active['site_name'] ?: ($active['site_url'] ?: 'Saknas')); ?></dd></div><div><dt>Bibliotek</dt><dd><?php echo esc_html($active['drive_name'] ?: 'Saknas'); ?></dd></div><div><dt>Mapp</dt><dd><?php echo esc_html($active['folder_path'] ?: ($active['folder_name'] ?: 'Saknas')); ?></dd></div></dl>
                        <p class="description"><?php echo esc_html(implode(', ', $item['uses'])); ?></p>
                        <a class="button <?php echo $key === $destination ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'ssf-member-portal-microsoft365', 'm365_tab' => 'integrations', 'destination' => $key, 'profile_environment' => $current_environment), admin_url('admin.php')) . '#sharepoint'; ?>">Konfigurera</a>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php foreach (SharePointDestinations::warnings($destination) as $warning) : ?><div class="notice notice-warning inline"><p><strong>Varning:</strong> <?php echo esc_html($warning); ?></p></div><?php endforeach; ?>

            <div class="ssf-sp-config">
                <div class="ssf-sp-config__title"><div><h3><?php echo esc_html($definition['label']); ?></h3><p><strong>Används av:</strong> <?php echo esc_html(implode(', ', $definition['uses'])); ?></p></div></div>
                <p class="ssf-sp-environment ssf-sp-environment--<?php echo esc_attr($current_environment); ?>"><?php echo esc_html(strtoupper($current_environment)); ?> - <?php esc_html_e('endast den aktiva WordPress-miljön kan redigeras.', 'ssf-member-portal'); ?></p>

                <form class="ssf-sp-wizard" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ssf_save_sharepoint_destination">
                    <input type="hidden" name="destination" value="<?php echo esc_attr($destination); ?>">
                    <input type="hidden" name="profile_environment" value="<?php echo esc_attr($profile_environment); ?>">
                    <?php wp_nonce_field('ssf_save_sharepoint_destination_' . $destination . '_' . $profile_environment); ?>

                    <section class="ssf-sp-step" data-sp-step="site">
                        <div class="ssf-sp-step__number">1</div><div><h4>SharePoint-site</h4><p>Ange site-adressen. Hostname och site path fylls i automatiskt.</p></div>
                        <div class="ssf-sp-fields">
                            <?php $this->field('site_url', 'SharePoint Site URL', $profile, 'url', 'https://tenant.sharepoint.com/sites/namn'); ?>
                            <?php $this->field('group_id', 'Team / Group ID (valfritt)', $profile); ?>
                        </div>
                        <button type="button" class="button" data-sp-operation="site">Hitta site</button>
                        <div class="ssf-sp-result" data-sp-result="site" aria-live="polite"></div>
                    </section>

                    <section class="ssf-sp-step" data-sp-step="drive">
                        <div class="ssf-sp-step__number">2</div><div><h4>Dokumentbibliotek</h4><p>Hämta tillgängliga dokumentbibliotek och välj ett.</p></div>
                        <div class="ssf-sp-fields"><?php $this->field('drive_name', 'Bibliotek', $profile); ?></div>
                        <p><button type="button" class="button" data-sp-operation="drives">Hitta dokumentbibliotek</button></p>
                        <div class="ssf-sp-result" data-sp-result="drives" aria-live="polite"></div>
                    </section>

                    <section class="ssf-sp-step" data-sp-step="folder">
                        <div class="ssf-sp-step__number">3</div><div><h4>Mapp</h4><p>Ange en känd mappväg eller bläddra från dokumentbibliotekets rot.</p></div>
                        <div class="ssf-sp-fields"><?php $this->field('folder_path', 'Mappväg', $profile, 'text', 'General/Medlemsansökningar'); ?></div>
                        <p><button type="button" class="button" data-sp-operation="folder_path">Hitta mapp</button> <button type="button" class="button" data-sp-operation="folders" data-parent-id="" data-parent-path="">Bläddra från roten</button></p>
                        <div class="ssf-sp-result" data-sp-result="folders" aria-live="polite"></div>
                    </section>

                    <section class="ssf-sp-step" data-sp-step="test">
                        <div class="ssf-sp-step__number">4</div><div><h4>Kontroll och test</h4><p>Läsningstestet ändrar ingenting. Skrivtestet skapar och tar omedelbart bort en namngiven textfil.</p></div>
                        <p><button type="button" class="button" data-sp-operation="diagnostics">Testa anslutning</button> <button type="button" class="button" data-sp-operation="write_test" <?php disabled($profile_environment !== $current_environment); ?>>Testa skrivåtkomst</button></p>
                        <?php if ($profile_environment !== $current_environment) : ?><p class="description">Skrivtest kan endast köras för installationens aktiva miljöprofil.</p><?php endif; ?>
                        <div class="ssf-sp-result" data-sp-result="test" aria-live="polite"></div>
                    </section>

                    <details class="ssf-sp-advanced">
                        <summary>Avancerat / identifierare</summary>
                        <p>Värdena kan fyllas i manuellt vid felsökning. Serverkonfiguration har företräde för den aktiva miljön.</p>
                        <div class="ssf-sp-fields ssf-sp-fields--technical">
                            <?php $this->field('hostname', 'Hostname', $profile); ?><?php $this->field('site_path', 'Site path', $profile); ?>
                            <?php $this->field('site_name', 'Site name', $profile); ?><?php $this->field('site_id', 'Site ID', $profile); ?>
                            <?php $this->field('drive_id', 'Drive ID', $profile); ?><?php $this->field('list_id', 'List ID', $profile); ?>
                            <?php $this->field('folder_name', 'Folder name', $profile); ?><?php $this->field('folder_id', 'Folder ID', $profile); ?>
                            <?php $this->field('drive_web_url', 'Bibliotekets webbadress', $profile, 'url'); ?><?php $this->field('folder_web_url', 'Mappens webbadress', $profile, 'url'); ?>
                        </div>
                    </details>

                    <details class="ssf-sp-advanced">
                        <summary>Avancerat / metadatafält</summary>
                        <p><button type="button" class="button" data-sp-operation="columns">Läs SharePoint-kolumner</button></p>
                        <div class="ssf-sp-metadata">
                            <?php foreach ($definition['metadata'] as $key => $metadata) : ?><label><?php echo esc_html($metadata['label']); ?><select name="profile[metadata][<?php echo esc_attr($key); ?>]" data-sp-metadata="<?php echo esc_attr($key); ?>"><option value="<?php echo esc_attr((string) ($profile['metadata'][$key] ?? '')); ?>" selected><?php echo esc_html((string) ($profile['metadata'][$key] ?? 'Ej valt')); ?></option></select></label><?php endforeach; ?>
                        </div>
                        <div class="ssf-sp-result" data-sp-result="columns" aria-live="polite"></div>
                    </details>

                    <?php submit_button('Spara konfiguration'); ?>
                </form>

                <form class="ssf-sp-policy" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ssf_save_sharepoint_policy"><input type="hidden" name="destination" value="<?php echo esc_attr($destination); ?>"><?php wp_nonce_field('ssf_save_sharepoint_policy'); ?>
                    <label><input type="checkbox" name="block_shared_development" value="1" <?php checked(SharePointDestinations::policy_enabled()); ?>> Blockera skrivning från Development när samma destination används i Production</label>
                    <?php submit_button('Spara DEV-skydd', 'secondary', 'submit', false); ?>
                </form>
            </div>
        </section>
        <?php
    }

    public function save(): void
    {
        $destination = sanitize_key((string) ($_POST['destination'] ?? ''));
        $environment = SharePointDestinations::environment();
        if (! $this->can_configure() || ! check_admin_referer('ssf_save_sharepoint_destination_' . $destination . '_' . $environment)) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-member-portal'));
        }
        $result = SharePointDestinations::save($destination, $environment, (array) wp_unslash($_POST['profile'] ?? array()));
        $this->redirect_with_notice($destination, $environment, is_wp_error($result) ? $result->get_error_message() : 'SharePoint-destinationen har sparats.', is_wp_error($result) ? 'error' : 'success');
    }

    public function save_policy(): void
    {
        if (! $this->can_configure() || ! check_admin_referer('ssf_save_sharepoint_policy')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-member-portal'));
        }
        SharePointDestinations::save_policy(! empty($_POST['block_shared_development']));
        $destination = sanitize_key((string) ($_POST['destination'] ?? 'annual_meetings'));
        if (! isset(SharePointDestinations::definitions()[$destination])) {
            $destination = 'annual_meetings';
        }
        $this->redirect_with_notice($destination, SharePointDestinations::environment(), 'DEV-skyddet har sparats.', 'success');
    }

    /** Save the one application credential pair used by all SharePoint destinations. */
    public function save_credentials(): void
    {
        if (! $this->can_configure() || ! check_admin_referer('ssf_save_sharepoint_credentials')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-member-portal'));
        }
        $input = (array) wp_unslash($_POST['graph'] ?? array());
        $status = Configuration::sharepoint_credentials_status();
        if (empty($status['client_id']['editable'])) {
            unset($input['client_id']);
        }
        if (! empty($input['clear_client_secret']) && empty($input['confirm_clear_client_secret'])) {
            $this->redirect_with_notice('annual_meetings', SharePointDestinations::environment(), 'Bekräfta att det WordPress-sparade client secret ska tas bort.', 'error', 'sharepoint-credentials');
        }
        $result = Configuration::save_admin($input);
        $message = is_wp_error($result)
            ? $result->get_error_message()
            : (! empty($input['clear_client_secret']) ? 'Det WordPress-sparade client secret har tagits bort.' : 'SharePoint-appens inställningar har sparats.');
        $this->redirect_with_notice('annual_meetings', SharePointDestinations::environment(), $message, is_wp_error($result) ? 'error' : 'success', 'sharepoint-credentials');
    }

    public function ajax(): void
    {
        if (! check_ajax_referer('ssf_sharepoint_admin', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Du saknar behörighet.'), 403);
        }
        $operation = sanitize_key((string) ($_POST['operation'] ?? ''));
        if (! $this->can_run_operation($operation)) {
            wp_send_json_error(array('message' => 'Du saknar behörighet.'), 403);
        }
        $destination = sanitize_key((string) ($_POST['destination'] ?? ''));
        $environment = SharePointDestinations::environment();
        // Folder migration owns its working profile, but deliberately reuses
        // this discovery endpoint and its site/drive/folder browser.
        if (! isset(SharePointDestinations::definitions()[$destination]) && 'folder_migration' !== $destination) {
            wp_send_json_error(array('message' => 'Okänd SharePoint-destination.'), 400);
        }
        $profile = json_decode((string) wp_unslash($_POST['profile'] ?? '{}'), true);
        $profile = is_array($profile) ? $profile : array();
        $result = null;
        switch ($operation) {
            case 'site':
                $result = $this->discovery->site((string) ($profile['site_url'] ?? ''), (string) ($profile['hostname'] ?? ''), (string) ($profile['site_path'] ?? ''), (string) ($profile['group_id'] ?? ''));
                break;
            case 'drives':
                $result = $this->discovery->drives((string) ($profile['site_id'] ?? ''));
                break;
            case 'drive':
                $result = $this->discovery->drive((string) ($profile['drive_id'] ?? ''), (string) ($profile['site_id'] ?? ''));
                break;
            case 'folders':
                $result = $this->discovery->folders((string) ($profile['drive_id'] ?? ''), sanitize_text_field((string) ($_POST['parent_id'] ?? '')), sanitize_text_field((string) ($_POST['parent_path'] ?? '')));
                break;
            case 'folder_path':
                $result = $this->discovery->folder_by_path((string) ($profile['drive_id'] ?? ''), (string) ($profile['folder_path'] ?? ''));
                break;
            case 'columns':
                $result = $this->discovery->columns((string) ($profile['site_id'] ?? ''), (string) ($profile['list_id'] ?? ''));
                break;
            case 'diagnostics':
                $result = $this->discovery->diagnostics($profile);
                if ('folder_migration' !== $destination) {
                    SharePointDestinations::save_health($destination, $environment, $result);
                }
                break;
            case 'write_test':
                if ($environment !== SharePointDestinations::environment()) {
                    $result = new \WP_Error('sharepoint_environment_write_blocked', 'Skrivtest kan bara köras för installationens aktiva miljöprofil.');
                } elseif ('folder_migration' !== $destination && ! SharePointDestinations::write_allowed_for_profile($destination, $environment, $profile)) {
                    $result = new \WP_Error('sharepoint_environment_write_blocked', 'Skrivning från Development är blockerad eftersom destinationen även används i Production.');
                } else {
                    $result = $this->discovery->write_test($profile);
                    if (is_array($result) && 'folder_migration' !== $destination) {
                        $health = SharePointDestinations::health($destination, $environment);
                        $health['write'] = array('ok' => ! empty($result['write']), 'cleanup' => ! empty($result['cleanup']));
                        $health['timestamp'] = gmdate('c');
                        $health['ok'] = ! empty($health['ok']) && ! empty($result['ok']);
                        SharePointDestinations::save_health($destination, $environment, $health);
                    }
                }
                break;
            default:
                $result = new \WP_Error('sharepoint_operation_invalid', 'Okänd SharePoint-åtgärd.');
        }

        if (is_wp_error($result)) {
            $error = $this->discovery->friendly_error($result, $profile);
            $this->log($operation, $destination, $environment, $error);
            wp_send_json_error($error, 422);
        }
        $this->log($operation, $destination, $environment, is_array($result) ? $result : array());
        wp_send_json_success($result);
    }

    private function field(string $key, string $label, array $profile, string $type = 'text', string $placeholder = ''): void
    {
        $is_identifier = in_array($key, array('site_id', 'drive_id', 'list_id', 'folder_id'), true);
        ?><label><?php echo esc_html($label); ?><span class="ssf-sp-field-control"><input class="<?php echo $is_identifier ? 'large-text code' : 'regular-text'; ?>" type="<?php echo esc_attr($type); ?>" name="profile[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) ($profile[$key] ?? '')); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" data-sp-field="<?php echo esc_attr($key); ?>"><?php if ($is_identifier) : ?><button type="button" class="button ssf-sp-copy" data-sp-copy-field="<?php echo esc_attr($key); ?>" title="Kopiera <?php echo esc_attr($label); ?>"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="screen-reader-text">Kopiera <?php echo esc_html($label); ?></span></button><?php endif; ?></span></label><?php
    }

    private function render_credentials(): void
    {
        $credentials = Configuration::sharepoint_credentials_status();
        $replace = 'replace' === sanitize_key((string) ($_GET['sharepoint_secret'] ?? ''));
        $secret = (array) ($credentials['client_secret'] ?? array());
        $client_id = (array) ($credentials['client_id'] ?? array());
        ?>
        <section id="sharepoint-credentials" class="ssf-sp-credentials" aria-labelledby="sharepoint-credentials-heading">
            <div class="ssf-sp-credentials__heading"><div><h3 id="sharepoint-credentials-heading">Microsoft Entra-app för SharePoint</h3><p>Den här appen används av WordPress för att läsa och skriva data i SharePoint via Microsoft Graph.</p></div></div>
            <dl class="ssf-sp-credentials__status">
                <div><dt>Microsoft 365-tenant</dt><dd>✓ Centralt konfigurerad</dd></div>
                <div><dt>Application (client) ID</dt><dd><?php echo ! empty($client_id['configured']) ? ('server' === ($client_id['source'] ?? '') ? '✓ Konfigurerat via server' : '✓ Konfigurerat') : 'Saknas'; ?></dd></div>
                <div><dt>Client secret</dt><dd><?php echo ! empty($secret['configured']) ? ('server' === ($secret['source'] ?? '') ? '✓ Konfigurerat via server' : '✓ Konfigurerat') : 'Saknas'; ?></dd></div>
            </dl>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_save_sharepoint_credentials">
                <?php wp_nonce_field('ssf_save_sharepoint_credentials'); ?>
                <p><label for="ssf-sharepoint-client-id"><strong>Application (client) ID</strong></label><br><input id="ssf-sharepoint-client-id" class="regular-text code" name="graph[client_id]" value="<?php echo esc_attr((string) ($client_id['value'] ?? '')); ?>" <?php disabled(empty($client_id['editable'])); ?>><?php if (empty($client_id['editable'])) : ?> <span class="description">Konfigurerat via server och kan inte ändras här.</span><?php endif; ?></p>
                <?php if (! $replace) : ?>
                    <p><a class="button" href="<?php echo esc_url(add_query_arg('sharepoint_secret', 'replace') . '#sharepoint-credentials'); ?>">Byt client secret</a></p>
                <?php else : ?>
                    <p><label for="ssf-sharepoint-client-secret"><strong>Nytt client secret value</strong></label><br><input id="ssf-sharepoint-client-secret" class="regular-text" type="password" name="graph[client_secret]" value="" autocomplete="new-password"><span class="description">Ange Client secret VALUE från Microsoft Entra. Secret ID fungerar inte.</span></p>
                    <p><?php submit_button('Spara nytt secret', 'primary', 'submit', false); ?> <a class="button" href="<?php echo esc_url(remove_query_arg('sharepoint_secret') . '#sharepoint-credentials'); ?>">Avbryt</a></p>
                <?php endif; ?>
                <?php if (! $replace && ! empty($client_id['editable'])) : submit_button('Spara client ID', 'secondary', 'submit', false); endif; ?>
            </form>
            <?php if (! empty($secret['stored'])) : ?>
                <details class="ssf-sp-advanced"><summary>Avancerat</summary>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ssf_save_sharepoint_credentials"><input type="hidden" name="graph[clear_client_secret]" value="1"><?php wp_nonce_field('ssf_save_sharepoint_credentials'); ?>
                        <p>Detta tar endast bort det client secret som är sparat i WordPress. Om inget client secret finns konfigurerat på servern kommer SharePoint-integrationen därefter inte att kunna autentisera.</p>
                        <?php if (! empty($secret['server_authoritative'])) : ?><p class="description">Client secret är också konfigurerat via server. Borttagning här ändrar inte serverkonfigurationen.</p><?php endif; ?>
                        <p><label><input type="checkbox" name="graph[confirm_clear_client_secret]" value="1"> Jag bekräftar att det WordPress-sparade client secret ska tas bort.</label></p>
                        <?php submit_button('Ta bort sparat client secret', 'delete', 'submit', false); ?>
                    </form>
                </details>
            <?php endif; ?>
        </section>
        <?php
    }

    private function can_manage(): bool
    {
        return current_user_can(Capabilities::MANAGE)
            || current_user_can(Capabilities::MANAGE_MOTIONS)
            || current_user_can(Capabilities::MANAGE_ANNUAL_MEETINGS)
            || current_user_can('ssf_manage_application_settings')
            || current_user_can('manage_options');
    }

    private function can_configure(): bool
    {
        return current_user_can(Capabilities::MANAGE)
            || current_user_can('ssf_manage_application_settings')
            || current_user_can('manage_options');
    }

    private function can_run_operation(string $operation): bool
    {
        if ('write_test' === $operation) {
            return $this->can_configure();
        }

        return $this->can_manage()
            || current_user_can('manage_ssf_releases');
    }

    private function log(string $operation, string $destination, string $environment, array $result): void
    {
        $error = (array) ($result['error'] ?? $result);
        Logger::add('sharepoint_admin_' . $operation, array(
            'destination' => $destination,
            'environment' => $environment,
            'http_status' => (string) ($error['http_status'] ?? 200),
            'graph_code' => (string) ($error['graph_code'] ?? ''),
            'ok' => empty($result['ok']) && isset($result['ok']) ? '0' : '1',
        ));
    }

    private function redirect_with_notice(string $destination, string $environment, string $message, string $type, string $anchor = 'sharepoint'): void
    {
        if (class_exists('SSF_Admin_Feedback')) {
            \SSF_Admin_Feedback::redirect(
                'ssf-member-portal-microsoft365',
                'sharepoint',
                $type,
                $message,
                array('m365_tab' => 'integrations', 'destination' => $destination, 'profile_environment' => $environment)
            );
        }
        set_transient('ssf_member_portal_sharepoint_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('page' => 'ssf-member-portal-microsoft365', 'm365_tab' => 'integrations', 'destination' => $destination, 'profile_environment' => $environment), admin_url('admin.php')) . '#' . rawurlencode($anchor));
        exit;
    }
}
