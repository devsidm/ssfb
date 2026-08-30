<?php
/**
 * Newsletter post type, administration, imports, and presentation helpers.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

const SSF_SITE_NEWSLETTER_POST_TYPE = 'ssf_newsletter';
const SSF_SITE_NEWSLETTER_SERIES_META = '_ssf_newsletter_series';
const SSF_SITE_NEWSLETTER_ISSUE_META = '_ssf_newsletter_issue';
const SSF_SITE_NEWSLETTER_DATE_META = '_ssf_newsletter_date';
const SSF_SITE_NEWSLETTER_DATE_PRECISION_META = '_ssf_newsletter_date_precision';
const SSF_SITE_NEWSLETTER_YEAR_META = '_ssf_newsletter_year';
const SSF_SITE_NEWSLETTER_PDF_META = '_ssf_newsletter_pdf_id';
const SSF_SITE_NEWSLETTER_PDF_SIZE_META = '_ssf_newsletter_pdf_size';
const SSF_SITE_NEWSLETTER_IMPORT_LOG_OPTION = 'ssf_site_newsletter_import_log';

function ssf_site_register_newsletter_post_type(): void
{
    $show_in_menu = class_exists('SSF_Admin_Navigation') ? false : true;

    register_post_type(
        SSF_SITE_NEWSLETTER_POST_TYPE,
        array(
            'labels' => array(
                'name' => __('Nyhetsbrev', 'ssf-site'),
                'singular_name' => __('Nyhetsbrev', 'ssf-site'),
                'add_new' => __('Lägg till nyhetsbrev', 'ssf-site'),
                'add_new_item' => __('Lägg till nyhetsbrev', 'ssf-site'),
                'edit_item' => __('Redigera nyhetsbrev', 'ssf-site'),
                'new_item' => __('Nytt nyhetsbrev', 'ssf-site'),
                'view_item' => __('Visa nyhetsbrev', 'ssf-site'),
                'search_items' => __('Sök nyhetsbrev', 'ssf-site'),
                'not_found' => __('Inga nyhetsbrev hittades.', 'ssf-site'),
                'all_items' => __('Alla nyhetsbrev', 'ssf-site'),
                'menu_name' => __('Nyhetsbrev', 'ssf-site'),
                'item_published' => __('Nyhetsbrevet är publicerat.', 'ssf-site'),
                'item_updated' => __('Nyhetsbrevet är uppdaterat.', 'ssf-site'),
            ),
            'public' => true,
            'show_in_menu' => $show_in_menu,
            'show_in_rest' => true,
            'has_archive' => 'nyhetsbrev',
            'rewrite' => array('slug' => 'nyhetsbrev', 'with_front' => false),
            'menu_icon' => 'dashicons-media-document',
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'revisions'),
            'capability_type' => array('ssf_newsletter', 'ssf_newsletters'),
            'map_meta_cap' => true,
            'exclude_from_search' => false,
        )
    );
}
add_action('init', 'ssf_site_register_newsletter_post_type');

function ssf_site_grant_newsletter_capabilities(): void
{
    $capabilities = array(
        'read_ssf_newsletter',
        'edit_ssf_newsletter',
        'delete_ssf_newsletter',
        'edit_ssf_newsletters',
        'edit_others_ssf_newsletters',
        'publish_ssf_newsletters',
        'read_private_ssf_newsletters',
        'delete_ssf_newsletters',
        'delete_private_ssf_newsletters',
        'delete_published_ssf_newsletters',
        'delete_others_ssf_newsletters',
        'edit_private_ssf_newsletters',
        'edit_published_ssf_newsletters',
        'manage_ssf_newsletters',
    );

    foreach (array('administrator', 'editor') as $role_name) {
        $role = get_role($role_name);
        if (! $role) {
            continue;
        }

        foreach ($capabilities as $capability) {
            $role->add_cap($capability);
        }
    }
}
add_action('init', 'ssf_site_grant_newsletter_capabilities', 20);

function ssf_site_migrate_newsletter_metadata(): void
{
    if ((string) get_option('ssf_site_newsletter_metadata_version', '') === '2026-08-29') {
        return;
    }

    $posts = get_posts(
        array(
            'post_type' => SSF_SITE_NEWSLETTER_POST_TYPE,
            'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'fields' => 'ids',
        )
    );

    foreach ($posts as $post_id) {
        $date = (string) get_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_DATE_META, true);
        $year = (string) get_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_YEAR_META, true);
        $precision = (string) get_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_DATE_PRECISION_META, true);
        if (! $precision) {
            update_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_DATE_PRECISION_META, 'full_date');
        }
        if (! $year && ssf_site_is_valid_newsletter_date($date)) {
            update_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_YEAR_META, substr($date, 0, 4));
        }
    }

    update_option('ssf_site_newsletter_metadata_version', '2026-08-29', false);
    ssf_site_clear_newsletter_cache();
}
add_action('admin_init', 'ssf_site_migrate_newsletter_metadata');

function ssf_site_newsletter_settings(): array
{
    return wp_parse_args(
        (array) get_option('ssf_site_newsletter_settings', array()),
        array(
            'archive_intro' => 'Här hittar du aktuella och tidigare nyhetsbrev från Sveriges Segelfartygsförbund.',
        )
    );
}

function ssf_site_register_newsletter_settings(): void
{
    register_setting(
        'ssf_site_newsletter_settings',
        'ssf_site_newsletter_settings',
        array(
            'type' => 'array',
            'sanitize_callback' => 'ssf_site_sanitize_newsletter_settings',
            'default' => ssf_site_newsletter_settings(),
        )
    );
}
add_action('admin_init', 'ssf_site_register_newsletter_settings');

function ssf_site_sanitize_newsletter_settings($value): array
{
    $value = is_array($value) ? $value : array();
    return array(
        'archive_intro' => sanitize_textarea_field($value['archive_intro'] ?? ''),
    );
}

function ssf_site_add_newsletter_submenus(): void
{
    add_submenu_page(null, __('Nyhetsbrev', 'ssf-site'), __('Nyhetsbrev', 'ssf-site'), 'edit_ssf_newsletters', 'ssf-newsletter-editor', 'ssf_site_render_newsletter_editor_page');

    if (class_exists('SSF_Admin_Navigation')) {
        add_submenu_page(SSF_Admin_Navigation::CONTENT, __('Nyhetsbrev', 'ssf-site'), __('Nyhetsbrev', 'ssf-site'), 'edit_ssf_newsletters', 'edit.php?post_type=' . SSF_SITE_NEWSLETTER_POST_TYPE, '', 40);
        add_submenu_page(null, __('Importera äldre nummer', 'ssf-site'), __('Importera äldre nummer', 'ssf-site'), 'manage_ssf_newsletters', 'ssf-newsletter-import', 'ssf_site_render_newsletter_import_page');
        add_submenu_page(null, __('Inställningar för nyhetsbrev', 'ssf-site'), __('Inställningar', 'ssf-site'), 'manage_ssf_newsletters', 'ssf-newsletter-settings', 'ssf_site_render_newsletter_settings_page');
        return;
    }

    add_submenu_page('edit.php?post_type=' . SSF_SITE_NEWSLETTER_POST_TYPE, __('Importera äldre nummer', 'ssf-site'), __('Importera äldre nummer', 'ssf-site'), 'manage_ssf_newsletters', 'ssf-newsletter-import', 'ssf_site_render_newsletter_import_page');
    add_submenu_page('edit.php?post_type=' . SSF_SITE_NEWSLETTER_POST_TYPE, __('Inställningar för nyhetsbrev', 'ssf-site'), __('Inställningar', 'ssf-site'), 'manage_ssf_newsletters', 'ssf-newsletter-settings', 'ssf_site_render_newsletter_settings_page');
}
add_action('admin_menu', 'ssf_site_add_newsletter_submenus');

function ssf_site_render_newsletter_admin_tabs(string $active): void
{
    $tabs = array(
        'list' => array(__('Alla nyhetsbrev', 'ssf-site'), admin_url('edit.php?post_type=' . SSF_SITE_NEWSLETTER_POST_TYPE)),
        'editor' => array(__('Lägg till nyhetsbrev', 'ssf-site'), ssf_site_newsletter_editor_url()),
        'import' => array(__('Importera äldre nummer', 'ssf-site'), admin_url('admin.php?page=ssf-newsletter-import')),
        'settings' => array(__('Inställningar', 'ssf-site'), admin_url('admin.php?page=ssf-newsletter-settings')),
    );
    ?>
    <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Nyhetsbrev', 'ssf-site'); ?>">
        <?php foreach ($tabs as $key => $tab) : ?>
            <a class="nav-tab <?php echo $active === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($tab[1]); ?>"><?php echo esc_html($tab[0]); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function ssf_site_newsletter_editor_url(int $post_id = 0): string
{
    $args = array('page' => 'ssf-newsletter-editor');
    if ($post_id) {
        $args['newsletter_id'] = $post_id;
    }
    return add_query_arg($args, admin_url('admin.php'));
}

function ssf_site_redirect_newsletter_post_new(): void
{
    $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
    if (SSF_SITE_NEWSLETTER_POST_TYPE !== $post_type) {
        return;
    }
    wp_safe_redirect(ssf_site_newsletter_editor_url());
    exit;
}
add_action('load-post-new.php', 'ssf_site_redirect_newsletter_post_new');

function ssf_site_redirect_newsletter_post_edit(): void
{
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
    if (! $post_id || 'edit' !== $action || SSF_SITE_NEWSLETTER_POST_TYPE !== get_post_type($post_id)) {
        return;
    }
    wp_safe_redirect(ssf_site_newsletter_editor_url($post_id));
    exit;
}
add_action('load-post.php', 'ssf_site_redirect_newsletter_post_edit');

function ssf_site_newsletter_edit_link(string $link, int $post_id, string $context): string
{
    if (SSF_SITE_NEWSLETTER_POST_TYPE !== get_post_type($post_id)) {
        return $link;
    }
    return ssf_site_newsletter_editor_url($post_id);
}
add_filter('get_edit_post_link', 'ssf_site_newsletter_edit_link', 10, 3);

function ssf_site_newsletter_editor_state_key(): string
{
    return 'ssf_site_newsletter_editor_' . get_current_user_id();
}

function ssf_site_newsletter_editor_redirect(int $post_id = 0, array $state = array()): void
{
    if ($state) {
        $state['post_id'] = $post_id;
        set_transient(ssf_site_newsletter_editor_state_key(), $state, 2 * MINUTE_IN_SECONDS);
    }
    wp_safe_redirect(ssf_site_newsletter_editor_url($post_id));
    exit;
}

function ssf_site_newsletter_editor_error_message(string $error): string
{
    $messages = array(
        'missing_title' => __('Ange en titel för nyhetsbrevet.', 'ssf-site'),
        'invalid_date' => __('Ange ett giltigt publiceringsdatum.', 'ssf-site'),
        'invalid_year' => __('Ange ett giltigt år mellan 1900 och tillåtet maxår.', 'ssf-site'),
        'missing_pdf' => __('Välj en PDF innan nyhetsbrevet publiceras.', 'ssf-site'),
        'invalid_pdf' => __('Den valda filen är inte en giltig PDF.', 'ssf-site'),
        'invalid_cover' => __('Den valda omslagsbilden är inte en giltig bild.', 'ssf-site'),
        'save_failed' => __('Nyhetsbrevet kunde inte sparas. Försök igen.', 'ssf-site'),
    );
    return $messages[$error] ?? __('Nyhetsbrevet kunde inte sparas.', 'ssf-site');
}

function ssf_site_newsletter_editor_values(array $source): array
{
    $date_precision = ! empty($source['ssf_newsletter_year_only']) ? 'year_only' : 'full_date';
    $raw_date = isset($source['ssf_newsletter_date']) ? sanitize_text_field(wp_unslash($source['ssf_newsletter_date'])) : '';
    $raw_year = isset($source['ssf_newsletter_year']) ? sanitize_text_field(wp_unslash($source['ssf_newsletter_year'])) : '';
    $date = ssf_site_is_valid_newsletter_date($raw_date) ? $raw_date : '';
    $year = 'year_only' === $date_precision ? $raw_year : ($date ? substr($date, 0, 4) : '');
    if (! ssf_site_is_valid_newsletter_year($year)) {
        $year = '';
    }
    if ('year_only' === $date_precision && $year) {
        $date = $year . '-01-01';
    }

    return array(
        'title' => isset($source['newsletter_title']) ? sanitize_text_field(wp_unslash($source['newsletter_title'])) : '',
        'series' => isset($source['ssf_newsletter_series']) ? sanitize_text_field(wp_unslash($source['ssf_newsletter_series'])) : '',
        'issue' => isset($source['ssf_newsletter_issue']) ? sanitize_text_field(wp_unslash($source['ssf_newsletter_issue'])) : '',
        'date' => $date,
        'raw_date' => $raw_date,
        'date_precision' => $date_precision,
        'year' => $year,
        'raw_year' => $raw_year,
        'pdf_id' => isset($source['ssf_newsletter_pdf_id']) ? absint($source['ssf_newsletter_pdf_id']) : 0,
        'description' => isset($source['ssf_newsletter_excerpt']) ? sanitize_textarea_field(wp_unslash($source['ssf_newsletter_excerpt'])) : '',
        'cover_id' => isset($source['ssf_newsletter_cover_id']) ? absint($source['ssf_newsletter_cover_id']) : 0,
    );
}

function ssf_site_render_newsletter_editor_page(): void
{
    if (! current_user_can('edit_ssf_newsletters')) {
        wp_die(esc_html__('Du saknar behörighet att redigera nyhetsbrev.', 'ssf-site'));
    }

    $post_id = isset($_GET['newsletter_id']) ? absint($_GET['newsletter_id']) : 0;
    $post = $post_id ? get_post($post_id) : null;
    if ($post_id && (! $post instanceof WP_Post || SSF_SITE_NEWSLETTER_POST_TYPE !== $post->post_type || ! current_user_can('edit_post', $post_id))) {
        wp_die(esc_html__('Nyhetsbrevet kunde inte öppnas.', 'ssf-site'));
    }

    $data = $post ? ssf_site_newsletter_data($post_id) : array(
        'series' => 'Fördevind',
        'issue' => '',
        'date' => '',
        'date_precision' => 'full_date',
        'year' => '',
        'pdf_id' => 0,
        'pdf_url' => '',
        'pdf_size' => '',
    );
    $values = array(
        'title' => $post ? $post->post_title : '',
        'series' => $data['series'],
        'issue' => $data['issue'],
        'date' => 'year_only' === $data['date_precision'] ? '' : $data['date'],
        'raw_date' => 'year_only' === $data['date_precision'] ? '' : $data['date'],
        'date_precision' => $data['date_precision'],
        'year' => $data['year'],
        'raw_year' => $data['year'],
        'pdf_id' => (int) $data['pdf_id'],
        'description' => $post ? ($post->post_excerpt ?: wp_strip_all_tags($post->post_content)) : '',
        'cover_id' => $post ? (int) get_post_thumbnail_id($post_id) : 0,
    );
    $errors = array();
    $state = get_transient(ssf_site_newsletter_editor_state_key());
    if (is_array($state) && (int) ($state['post_id'] ?? 0) === $post_id) {
        delete_transient(ssf_site_newsletter_editor_state_key());
        $values = array_merge($values, (array) ($state['values'] ?? array()));
        $errors = array_map('sanitize_key', (array) ($state['errors'] ?? array()));
    }

    $pdf_name = $values['pdf_id'] ? get_the_title((int) $values['pdf_id']) : '';
    $pdf_url = $values['pdf_id'] && ssf_site_is_pdf_attachment((int) $values['pdf_id']) ? wp_get_attachment_url((int) $values['pdf_id']) : '';
    $pdf_size = $values['pdf_id'] ? ssf_site_newsletter_pdf_size((int) $values['pdf_id']) : '';
    $cover_markup = $values['cover_id'] && wp_attachment_is_image((int) $values['cover_id']) ? wp_get_attachment_image((int) $values['cover_id'], 'medium') : '';
    $status = $post ? $post->post_status : 'draft';
    $status_object = get_post_status_object($status);
    $primary_status = in_array($status, array('publish', 'future', 'private', 'pending'), true) ? $status : 'publish';
    $duplicates = ssf_site_newsletter_duplicates($post_id, (string) $values['issue'], (string) $values['year'], (int) $values['pdf_id']);
    ?>
    <div class="wrap ssf-newsletter-editor">
        <h1><?php echo esc_html($post ? __('Redigera nyhetsbrev', 'ssf-site') : __('Lägg till nyhetsbrev', 'ssf-site')); ?></h1>
        <?php ssf_site_render_newsletter_admin_tabs('editor'); ?>
        <?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Nyhetsbrevet har sparats.', 'ssf-site'); ?></p></div><?php endif; ?>
        <?php foreach ($errors as $error) : ?><div class="notice notice-error"><p><?php echo esc_html(ssf_site_newsletter_editor_error_message($error)); ?></p></div><?php endforeach; ?>
        <?php if ($duplicates) : ?><div class="notice notice-warning"><p><?php echo esc_html(implode(' ', $duplicates)); ?></p></div><?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ssf-newsletter-editor__form">
            <input type="hidden" name="action" value="ssf_save_newsletter_editor">
            <input type="hidden" name="newsletter_id" value="<?php echo esc_attr((string) $post_id); ?>">
            <?php wp_nonce_field('ssf_save_newsletter_editor'); ?>

            <div class="ssf-newsletter-editor__layout">
                <main class="ssf-newsletter-editor__main">
                    <section class="ssf-newsletter-editor__panel">
                        <h2><?php esc_html_e('Nyhetsbrev', 'ssf-site'); ?></h2>
                        <div class="ssf-newsletter-field ssf-newsletter-field--wide">
                            <label for="newsletter-title"><?php esc_html_e('Titel', 'ssf-site'); ?></label>
                            <input id="newsletter-title" name="newsletter_title" type="text" value="<?php echo esc_attr((string) $values['title']); ?>" placeholder="<?php esc_attr_e('Fördevind nr 2 - 2024', 'ssf-site'); ?>" required>
                        </div>
                        <div class="ssf-newsletter-editor__fields">
                            <div class="ssf-newsletter-field"><label for="ssf-newsletter-series"><?php esc_html_e('Serie', 'ssf-site'); ?></label><input id="ssf-newsletter-series" name="ssf_newsletter_series" type="text" value="<?php echo esc_attr((string) $values['series']); ?>" placeholder="<?php esc_attr_e('Fördevind', 'ssf-site'); ?>"></div>
                            <div class="ssf-newsletter-field"><label for="ssf-newsletter-issue"><?php esc_html_e('Nummer / utgåva', 'ssf-site'); ?></label><input id="ssf-newsletter-issue" name="ssf_newsletter_issue" type="text" value="<?php echo esc_attr((string) $values['issue']); ?>" placeholder="<?php esc_attr_e('3 eller 1/2024', 'ssf-site'); ?>"></div>
                        </div>
                    </section>

                    <section class="ssf-newsletter-editor__panel">
                        <h2><?php esc_html_e('Datum', 'ssf-site'); ?></h2>
                        <div class="ssf-newsletter-editor__fields">
                            <div class="ssf-newsletter-field" data-ssf-newsletter-date-field><label for="ssf-newsletter-date"><?php esc_html_e('Publiceringsdatum', 'ssf-site'); ?></label><input id="ssf-newsletter-date" name="ssf_newsletter_date" type="date" value="<?php echo esc_attr((string) $values['raw_date']); ?>"></div>
                            <div class="ssf-newsletter-field" data-ssf-newsletter-year-field><label for="ssf-newsletter-year"><?php esc_html_e('År', 'ssf-site'); ?></label><input id="ssf-newsletter-year" name="ssf_newsletter_year" type="number" min="1900" max="<?php echo esc_attr((string) ssf_site_newsletter_max_year()); ?>" value="<?php echo esc_attr((string) $values['raw_year']); ?>" placeholder="1998"></div>
                        </div>
                        <label class="ssf-newsletter-checkbox"><input type="checkbox" name="ssf_newsletter_year_only" value="1" data-ssf-newsletter-year-only <?php checked('year_only', $values['date_precision']); ?>> <span><?php esc_html_e('Jag känner bara till året', 'ssf-site'); ?></span></label>
                    </section>

                    <section class="ssf-newsletter-editor__panel">
                        <h2><?php esc_html_e('Kort beskrivning', 'ssf-site'); ?></h2>
                        <div class="ssf-newsletter-field ssf-newsletter-field--wide"><label class="screen-reader-text" for="ssf-newsletter-excerpt"><?php esc_html_e('Kort beskrivning', 'ssf-site'); ?></label><textarea id="ssf-newsletter-excerpt" name="ssf_newsletter_excerpt" rows="7" placeholder="<?php esc_attr_e('Kort sammanfattning av utgåvans innehåll.', 'ssf-site'); ?>"><?php echo esc_textarea((string) $values['description']); ?></textarea></div>
                    </section>
                </main>

                <aside class="ssf-newsletter-editor__side">
                    <section class="ssf-newsletter-editor__panel ssf-newsletter-editor__panel--pdf">
                        <h2><?php esc_html_e('PDF', 'ssf-site'); ?> <span class="ssf-newsletter-required"><?php esc_html_e('Obligatorisk vid publicering', 'ssf-site'); ?></span></h2>
                        <div class="ssf-newsletter-file" data-ssf-newsletter-pdf-summary>
                            <span class="dashicons dashicons-pdf" aria-hidden="true"></span>
                            <div><strong data-ssf-newsletter-pdf-name><?php echo esc_html($pdf_name ?: __('Ingen PDF vald', 'ssf-site')); ?></strong><span data-ssf-newsletter-pdf-size><?php echo esc_html($pdf_size); ?></span></div>
                        </div>
                        <input type="hidden" name="ssf_newsletter_pdf_id" value="<?php echo esc_attr((string) $values['pdf_id']); ?>" data-ssf-newsletter-pdf-id>
                        <p class="ssf-newsletter-editor__actions"><button type="button" class="button button-secondary" data-ssf-select-newsletter-pdf><?php esc_html_e('Välj eller ladda upp PDF', 'ssf-site'); ?></button><a href="<?php echo esc_url((string) $pdf_url); ?>" target="_blank" rel="noopener" data-ssf-newsletter-pdf-link <?php echo $pdf_url ? '' : 'hidden'; ?>><?php esc_html_e('Öppna PDF', 'ssf-site'); ?></a></p>
                        <button type="button" class="button-link-delete" data-ssf-remove-newsletter-pdf <?php disabled(! $values['pdf_id']); ?>><?php esc_html_e('Ta bort PDF', 'ssf-site'); ?></button>
                    </section>

                    <section class="ssf-newsletter-editor__panel">
                        <h2><?php esc_html_e('Omslagsbild', 'ssf-site'); ?> <span class="ssf-newsletter-optional"><?php esc_html_e('Valfri', 'ssf-site'); ?></span></h2>
                        <div class="ssf-newsletter-cover" data-ssf-newsletter-cover-preview><?php echo $cover_markup; ?></div>
                        <input type="hidden" name="ssf_newsletter_cover_id" value="<?php echo esc_attr((string) $values['cover_id']); ?>" data-ssf-newsletter-cover-id>
                        <p class="ssf-newsletter-editor__actions"><button type="button" class="button" data-ssf-select-newsletter-cover><?php esc_html_e('Välj bild', 'ssf-site'); ?></button><button type="button" class="button-link-delete" data-ssf-remove-newsletter-cover <?php disabled(! $values['cover_id']); ?>><?php esc_html_e('Ta bort', 'ssf-site'); ?></button></p>
                    </section>

                    <section class="ssf-newsletter-editor__panel ssf-newsletter-editor__publish">
                        <div class="ssf-newsletter-status"><span><?php esc_html_e('Status', 'ssf-site'); ?></span><strong><?php echo esc_html($status_object ? $status_object->label : __('Utkast', 'ssf-site')); ?></strong></div>
                        <?php if ($post && 'publish' === $post->post_status) : ?><p><a href="<?php echo esc_url(get_permalink($post)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Visa publicerat nyhetsbrev', 'ssf-site'); ?></a></p><?php endif; ?>
                        <div class="ssf-newsletter-editor__submit">
                            <button type="submit" class="button" name="newsletter_status" value="draft" formnovalidate><?php esc_html_e('Spara utkast', 'ssf-site'); ?></button>
                            <?php if (current_user_can('publish_ssf_newsletters')) : ?><button type="submit" class="button button-primary" name="newsletter_status" value="<?php echo esc_attr($primary_status); ?>"><?php echo esc_html($post && 'draft' !== $status ? __('Spara ändringar', 'ssf-site') : __('Publicera', 'ssf-site')); ?></button><?php endif; ?>
                        </div>
                    </section>
                </aside>
            </div>
        </form>
    </div>
    <?php
}

function ssf_site_handle_newsletter_editor_save(): void
{
    if (! current_user_can('edit_ssf_newsletters') || ! check_admin_referer('ssf_save_newsletter_editor')) {
        wp_die(esc_html__('Du saknar behörighet att spara nyhetsbrev.', 'ssf-site'));
    }

    $post_id = isset($_POST['newsletter_id']) ? absint($_POST['newsletter_id']) : 0;
    if ($post_id && (SSF_SITE_NEWSLETTER_POST_TYPE !== get_post_type($post_id) || ! current_user_can('edit_post', $post_id))) {
        wp_die(esc_html__('Du saknar behörighet att redigera nyhetsbrevet.', 'ssf-site'));
    }

    $values = ssf_site_newsletter_editor_values($_POST);
    $status = isset($_POST['newsletter_status']) ? sanitize_key(wp_unslash($_POST['newsletter_status'])) : 'draft';
    $current_status = $post_id ? (string) get_post_status($post_id) : '';
    $allowed_statuses = array('draft', 'publish');
    if (in_array($current_status, array('future', 'private', 'pending'), true)) {
        $allowed_statuses[] = $current_status;
    }
    if (! in_array($status, $allowed_statuses, true)) {
        wp_die(esc_html__('Ogiltig publiceringsstatus.', 'ssf-site'));
    }
    if (in_array($status, array('publish', 'future'), true) && ! current_user_can('publish_ssf_newsletters')) {
        wp_die(esc_html__('Du saknar behörighet att publicera nyhetsbrev.', 'ssf-site'));
    }

    $errors = array();
    if (! $values['title']) {
        $errors[] = 'missing_title';
    }
    if ('full_date' === $values['date_precision'] && $values['raw_date'] && ! $values['date']) {
        $errors[] = 'invalid_date';
    }
    if ('year_only' === $values['date_precision'] && $values['raw_year'] && ! $values['year']) {
        $errors[] = 'invalid_year';
    }
    if ($values['pdf_id'] && ! ssf_site_is_pdf_attachment((int) $values['pdf_id'])) {
        $errors[] = 'invalid_pdf';
    }
    if (in_array($status, array('publish', 'future'), true) && ! $values['pdf_id']) {
        $errors[] = 'missing_pdf';
    }
    if (in_array($status, array('publish', 'future'), true) && ! $values['year']) {
        $errors[] = 'year_only' === $values['date_precision'] ? 'invalid_year' : 'invalid_date';
    }
    if ($values['cover_id'] && ! wp_attachment_is_image((int) $values['cover_id'])) {
        $errors[] = 'invalid_cover';
    }
    $errors = array_values(array_unique($errors));
    if ($errors) {
        ssf_site_newsletter_editor_redirect($post_id, array('values' => $values, 'errors' => $errors));
    }

    $postarr = array(
        'post_type' => SSF_SITE_NEWSLETTER_POST_TYPE,
        'post_status' => $status,
        'post_title' => wp_slash((string) $values['title']),
        'post_excerpt' => wp_slash((string) $values['description']),
        'post_content' => wp_slash((string) $values['description']),
    );
    if ($post_id) {
        $postarr['ID'] = $post_id;
        $saved_id = wp_update_post($postarr, true);
    } else {
        $saved_id = wp_insert_post($postarr, true);
    }
    if (is_wp_error($saved_id)) {
        ssf_site_newsletter_editor_redirect($post_id, array('values' => $values, 'errors' => array('save_failed')));
    }
    $post_id = (int) $saved_id;

    update_post_meta($post_id, SSF_SITE_NEWSLETTER_SERIES_META, $values['series']);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_ISSUE_META, $values['issue']);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_META, $values['date']);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_PRECISION_META, $values['date_precision']);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_YEAR_META, $values['year']);
    if ($values['pdf_id']) {
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META, $values['pdf_id']);
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_SIZE_META, ssf_site_newsletter_pdf_size_bytes((int) $values['pdf_id']));
    } else {
        delete_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META);
        delete_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_SIZE_META);
    }
    if ($values['cover_id']) {
        set_post_thumbnail($post_id, (int) $values['cover_id']);
    } else {
        delete_post_thumbnail($post_id);
    }

    ssf_site_clear_newsletter_cache();
    wp_safe_redirect(add_query_arg('updated', '1', ssf_site_newsletter_editor_url($post_id)));
    exit;
}
add_action('admin_post_ssf_save_newsletter_editor', 'ssf_site_handle_newsletter_editor_save');

function ssf_site_render_newsletter_settings_page(): void
{
    if (! current_user_can('manage_ssf_newsletters')) {
        return;
    }

    $settings = ssf_site_newsletter_settings();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Inställningar för nyhetsbrev', 'ssf-site'); ?></h1>
        <?php ssf_site_render_newsletter_admin_tabs('settings'); ?>
        <form method="post" action="options.php">
            <?php settings_fields('ssf_site_newsletter_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ssf-newsletter-archive-intro"><?php esc_html_e('Ingress på arkivsidan', 'ssf-site'); ?></label></th>
                    <td><textarea id="ssf-newsletter-archive-intro" class="large-text" rows="3" name="ssf_site_newsletter_settings[archive_intro]"><?php echo esc_textarea($settings['archive_intro']); ?></textarea></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function ssf_site_add_newsletter_meta_boxes(): void
{
    add_meta_box('ssf-newsletter-details', __('Nyhetsbrev', 'ssf-site'), 'ssf_site_render_newsletter_meta_box', SSF_SITE_NEWSLETTER_POST_TYPE, 'side', 'high');
}
add_action('add_meta_boxes_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_add_newsletter_meta_boxes');

function ssf_site_render_newsletter_meta_box(WP_Post $post): void
{
    $data = ssf_site_newsletter_data($post->ID);
    $pdf_name = $data['pdf_id'] ? get_the_title((int) $data['pdf_id']) : '';
    $duplicates = ssf_site_newsletter_duplicates($post->ID, $data['issue'], $data['year'], (int) $data['pdf_id']);
    wp_nonce_field('ssf_site_save_newsletter', 'ssf_site_newsletter_nonce');
    ?>
    <p><label for="ssf-newsletter-series"><strong><?php esc_html_e('Serie', 'ssf-site'); ?></strong></label><br>
        <input class="widefat" id="ssf-newsletter-series" name="ssf_newsletter_series" value="<?php echo esc_attr($data['series']); ?>" placeholder="<?php esc_attr_e('Exempel: Fördevind', 'ssf-site'); ?>"></p>
    <p><label for="ssf-newsletter-issue"><strong><?php esc_html_e('Nummer / utgåva', 'ssf-site'); ?></strong></label><br>
        <input class="widefat" id="ssf-newsletter-issue" name="ssf_newsletter_issue" value="<?php echo esc_attr($data['issue']); ?>" placeholder="<?php esc_attr_e('Exempel: 3 eller 1/2024', 'ssf-site'); ?>"></p>
    <p><label for="ssf-newsletter-date"><strong><?php esc_html_e('Publiceringsdatum', 'ssf-site'); ?></strong></label><br>
        <input class="widefat" id="ssf-newsletter-date" type="date" name="ssf_newsletter_date" value="<?php echo esc_attr($data['date']); ?>"></p>
    <p><label><input type="checkbox" name="ssf_newsletter_year_only" value="1" data-ssf-newsletter-year-only <?php checked('year_only', $data['date_precision']); ?>> <?php esc_html_e('Jag känner bara till året', 'ssf-site'); ?></label></p>
    <p data-ssf-newsletter-year-field><label for="ssf-newsletter-year"><strong><?php esc_html_e('År', 'ssf-site'); ?></strong></label><br>
        <input class="widefat" id="ssf-newsletter-year" type="number" min="1900" max="<?php echo esc_attr((string) ssf_site_newsletter_max_year()); ?>" name="ssf_newsletter_year" value="<?php echo esc_attr($data['year']); ?>" placeholder="<?php esc_attr_e('Exempel: 1998', 'ssf-site'); ?>"></p>
    <p><label for="ssf-newsletter-excerpt"><strong><?php esc_html_e('Kort beskrivning', 'ssf-site'); ?></strong></label><br>
        <textarea class="widefat" id="ssf-newsletter-excerpt" name="ssf_newsletter_excerpt" rows="4"><?php echo esc_textarea($post->post_excerpt); ?></textarea></p>
    <p>
        <strong><?php esc_html_e('PDF-fil', 'ssf-site'); ?></strong><br>
        <span class="description" data-ssf-newsletter-pdf-name><?php echo esc_html($pdf_name ?: __('Ingen PDF vald.', 'ssf-site')); ?></span>
        <input type="hidden" name="ssf_newsletter_pdf_id" value="<?php echo esc_attr((string) $data['pdf_id']); ?>" data-ssf-newsletter-pdf-id>
    </p>
    <p>
        <button type="button" class="button" data-ssf-select-newsletter-pdf><?php esc_html_e('Välj eller ladda upp PDF', 'ssf-site'); ?></button>
        <button type="button" class="button-link-delete" data-ssf-remove-newsletter-pdf <?php disabled(! $data['pdf_id']); ?>><?php esc_html_e('Ta bort PDF', 'ssf-site'); ?></button>
    </p>
    <p class="description"><?php esc_html_e('År skapas automatiskt från datumet. Välj år-only när äldre nummer saknar exakt datum. Omslagsbild väljs i den vanliga rutan Utvald bild.', 'ssf-site'); ?></p>
    <?php if ($duplicates) : ?>
        <div class="notice notice-warning inline"><p><?php echo esc_html(implode(' ', $duplicates)); ?></p></div>
    <?php endif; ?>
    <?php
}

function ssf_site_enqueue_newsletter_admin_assets(string $hook): void
{
    $screen = get_current_screen();
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $is_editor = 'ssf-newsletter-editor' === $page || ($screen && SSF_SITE_NEWSLETTER_POST_TYPE === $screen->post_type && in_array($hook, array('post.php', 'post-new.php'), true));
    $is_import = 'ssf-newsletter-import' === $page;
    if (! $is_editor && ! $is_import) {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_style('ssf-newsletter-admin', SSF_SITE_URL . 'assets/css/ssf-newsletters-admin.css', array(), SSF_SITE_VERSION);
    wp_enqueue_script('ssf-newsletter-admin', SSF_SITE_URL . 'assets/js/ssf-newsletters-admin.js', array('jquery'), SSF_SITE_VERSION, true);
}
add_action('admin_enqueue_scripts', 'ssf_site_enqueue_newsletter_admin_assets');

function ssf_site_save_newsletter(int $post_id, WP_Post $post): void
{
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ! isset($_POST['ssf_site_newsletter_nonce'])) {
        return;
    }
    if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_site_newsletter_nonce'])), 'ssf_site_save_newsletter') || ! current_user_can('edit_post', $post_id)) {
        return;
    }

    $series = isset($_POST['ssf_newsletter_series']) ? sanitize_text_field(wp_unslash($_POST['ssf_newsletter_series'])) : '';
    $issue = isset($_POST['ssf_newsletter_issue']) ? sanitize_text_field(wp_unslash($_POST['ssf_newsletter_issue'])) : '';
    $date = isset($_POST['ssf_newsletter_date']) ? sanitize_text_field(wp_unslash($_POST['ssf_newsletter_date'])) : '';
    $date_precision = ! empty($_POST['ssf_newsletter_year_only']) ? 'year_only' : 'full_date';
    $submitted_year = isset($_POST['ssf_newsletter_year']) ? sanitize_text_field(wp_unslash($_POST['ssf_newsletter_year'])) : '';
    $pdf_id = isset($_POST['ssf_newsletter_pdf_id']) ? absint($_POST['ssf_newsletter_pdf_id']) : 0;
    $excerpt = isset($_POST['ssf_newsletter_excerpt']) ? sanitize_textarea_field(wp_unslash($_POST['ssf_newsletter_excerpt'])) : '';

    if ($date && ! ssf_site_is_valid_newsletter_date($date)) {
        $date = '';
    }
    $year = 'year_only' === $date_precision ? $submitted_year : ($date ? substr($date, 0, 4) : $submitted_year);
    if (! ssf_site_is_valid_newsletter_year($year)) {
        $year = '';
    }
    if ('year_only' === $date_precision && $year) {
        $date = $year . '-01-01';
    }

    update_post_meta($post_id, SSF_SITE_NEWSLETTER_SERIES_META, $series);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_ISSUE_META, $issue);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_META, $date);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_PRECISION_META, $date_precision);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_YEAR_META, $year);

    if ($pdf_id && ssf_site_is_pdf_attachment($pdf_id)) {
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META, $pdf_id);
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_SIZE_META, ssf_site_newsletter_pdf_size_bytes($pdf_id));
    } else {
        delete_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META);
        delete_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_SIZE_META);
    }

    if ('publish' === $post->post_status && (! $pdf_id || ! ssf_site_is_pdf_attachment($pdf_id) || ! $year)) {
        ssf_site_mark_newsletter_save_error($post_id, ! $pdf_id || ! ssf_site_is_pdf_attachment($pdf_id) ? 'missing_pdf' : 'missing_year');
        remove_action('save_post_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_save_newsletter', 10);
        wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
        add_action('save_post_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_save_newsletter', 10, 2);
    }

    if ($excerpt !== $post->post_excerpt) {
        remove_action('save_post_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_save_newsletter', 10);
        wp_update_post(array('ID' => $post_id, 'post_excerpt' => $excerpt));
        add_action('save_post_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_save_newsletter', 10, 2);
    }

    ssf_site_clear_newsletter_cache();
}
add_action('save_post_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_save_newsletter', 10, 2);

function ssf_site_mark_newsletter_save_error(int $post_id, string $error): void
{
    set_transient('ssf_site_newsletter_error_' . get_current_user_id(), array('post_id' => $post_id, 'error' => $error), 60);
}

function ssf_site_newsletter_admin_notice(): void
{
    $notice = get_transient('ssf_site_newsletter_error_' . get_current_user_id());
    if (! is_array($notice)) {
        return;
    }

    delete_transient('ssf_site_newsletter_error_' . get_current_user_id());
    $message = 'missing_year' === ($notice['error'] ?? '')
        ? __('Du måste ange ett giltigt datum eller år innan nyhetsbrevet kan publiceras.', 'ssf-site')
        : __('Du måste välja en PDF innan nyhetsbrevet kan publiceras.', 'ssf-site');
    printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($message));
}
add_action('admin_notices', 'ssf_site_newsletter_admin_notice');

function ssf_site_is_valid_newsletter_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function ssf_site_newsletter_max_year(): int
{
    return (int) wp_date('Y') + 2;
}

function ssf_site_is_valid_newsletter_year(string $year): bool
{
    if (! preg_match('/^\d{4}$/', $year)) {
        return false;
    }
    $year_int = (int) $year;
    return 1900 <= $year_int && ssf_site_newsletter_max_year() >= $year_int;
}

function ssf_site_is_pdf_attachment(int $attachment_id): bool
{
    if ('attachment' !== get_post_type($attachment_id)) {
        return false;
    }

    $file = get_attached_file($attachment_id);
    $filetype = wp_check_filetype($file);
    return 'application/pdf' === get_post_mime_type($attachment_id) && 'pdf' === strtolower((string) $filetype['ext']);
}

function ssf_site_newsletter_duplicates(int $post_id, string $issue, string $year, int $pdf_id): array
{
    $warnings = array();
    if ($issue && $year) {
        $same_issue = get_posts(
            array(
                'post_type' => SSF_SITE_NEWSLETTER_POST_TYPE,
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => 1,
                'post__not_in' => $post_id ? array($post_id) : array(),
                'meta_query' => array(
                    'relation' => 'AND',
                    array('key' => SSF_SITE_NEWSLETTER_ISSUE_META, 'value' => $issue),
                    array('key' => SSF_SITE_NEWSLETTER_YEAR_META, 'value' => $year),
                ),
            )
        );
        if ($same_issue) {
            $warnings[] = __('En annan utgåva med samma nummer och år finns redan. Du kan fortsätta om det är avsiktligt.', 'ssf-site');
        }
    }

    if ($pdf_id) {
        $same_pdf = get_posts(
            array(
                'post_type' => SSF_SITE_NEWSLETTER_POST_TYPE,
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => 1,
                'post__not_in' => $post_id ? array($post_id) : array(),
                'meta_key' => SSF_SITE_NEWSLETTER_PDF_META,
                'meta_value' => $pdf_id,
            )
        );
        if ($same_pdf) {
            $warnings[] = __('Samma PDF används redan av ett annat nyhetsbrev. Du kan fortsätta om det är avsiktligt.', 'ssf-site');
        }
    }

    return $warnings;
}

function ssf_site_newsletter_columns(array $columns): array
{
    return array(
        'cb' => $columns['cb'],
        'title' => __('Titel', 'ssf-site'),
        'ssf_series' => __('Serie', 'ssf-site'),
        'ssf_issue' => __('Nummer', 'ssf-site'),
        'ssf_year' => __('År', 'ssf-site'),
        'ssf_date' => __('Datum', 'ssf-site'),
        'ssf_pdf' => __('PDF', 'ssf-site'),
        'date' => __('Status', 'ssf-site'),
    );
}
add_filter('manage_' . SSF_SITE_NEWSLETTER_POST_TYPE . '_posts_columns', 'ssf_site_newsletter_columns');

function ssf_site_render_newsletter_column(string $column, int $post_id): void
{
    if ('ssf_series' === $column) {
        echo esc_html((string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_SERIES_META, true));
        return;
    }
    if ('ssf_issue' === $column) {
        echo esc_html((string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_ISSUE_META, true));
        return;
    }
    if ('ssf_date' === $column) {
        echo esc_html(ssf_site_newsletter_display_date($post_id));
        return;
    }
    if ('ssf_year' === $column) {
        echo esc_html((string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_YEAR_META, true));
        return;
    }
    if ('ssf_pdf' === $column) {
        $pdf_id = (int) get_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META, true);
        if ($pdf_id && ssf_site_is_pdf_attachment($pdf_id)) {
            printf('<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url(wp_get_attachment_url($pdf_id)), esc_html__('PDF', 'ssf-site'));
        } else {
            esc_html_e('Saknas', 'ssf-site');
        }
    }
}
add_action('manage_' . SSF_SITE_NEWSLETTER_POST_TYPE . '_posts_custom_column', 'ssf_site_render_newsletter_column', 10, 2);

function ssf_site_newsletter_sortable_columns(array $columns): array
{
    $columns['ssf_date'] = 'ssf_date';
    $columns['ssf_year'] = 'ssf_year';
    return $columns;
}
add_filter('manage_edit-' . SSF_SITE_NEWSLETTER_POST_TYPE . '_sortable_columns', 'ssf_site_newsletter_sortable_columns');

function ssf_site_newsletter_bulk_edit_fields(string $column_name, string $post_type): void
{
    static $rendered = false;
    if ($rendered || SSF_SITE_NEWSLETTER_POST_TYPE !== $post_type || 'ssf_series' !== $column_name) {
        return;
    }
    $rendered = true;
    ?>
    <fieldset class="inline-edit-col-right">
        <div class="inline-edit-col">
            <h4><?php esc_html_e('Nyhetsbrev', 'ssf-site'); ?></h4>
            <?php wp_nonce_field('ssf_site_bulk_edit_newsletters', 'ssf_site_bulk_newsletter_nonce'); ?>
            <label><span class="title"><?php esc_html_e('Serie', 'ssf-site'); ?></span><input type="text" name="ssf_bulk_newsletter_series" placeholder="<?php esc_attr_e('Lämna tomt för oförändrat', 'ssf-site'); ?>"></label>
            <label><span class="title"><?php esc_html_e('År', 'ssf-site'); ?></span><input type="number" min="1900" max="<?php echo esc_attr((string) ssf_site_newsletter_max_year()); ?>" name="ssf_bulk_newsletter_year" placeholder="<?php esc_attr_e('Lämna tomt för oförändrat', 'ssf-site'); ?>"></label>
        </div>
    </fieldset>
    <?php
}
add_action('bulk_edit_custom_box', 'ssf_site_newsletter_bulk_edit_fields', 10, 2);

function ssf_site_save_newsletter_bulk_edit(int $post_id): void
{
    if (! isset($_REQUEST['bulk_edit'], $_REQUEST['ssf_site_bulk_newsletter_nonce'])) {
        return;
    }
    if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['ssf_site_bulk_newsletter_nonce'])), 'ssf_site_bulk_edit_newsletters') || ! current_user_can('edit_post', $post_id)) {
        return;
    }

    $series = isset($_REQUEST['ssf_bulk_newsletter_series']) ? sanitize_text_field(wp_unslash($_REQUEST['ssf_bulk_newsletter_series'])) : '';
    $year = isset($_REQUEST['ssf_bulk_newsletter_year']) ? sanitize_text_field(wp_unslash($_REQUEST['ssf_bulk_newsletter_year'])) : '';
    if ('' !== $series) {
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_SERIES_META, $series);
    }
    if (ssf_site_is_valid_newsletter_year($year)) {
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_YEAR_META, $year);
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_META, $year . '-01-01');
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_PRECISION_META, 'year_only');
    }
    ssf_site_clear_newsletter_cache();
}
add_action('save_post_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_save_newsletter_bulk_edit', 20);

function ssf_site_newsletter_admin_order(WP_Query $query): void
{
    if (! is_admin() || ! $query->is_main_query() || SSF_SITE_NEWSLETTER_POST_TYPE !== $query->get('post_type')) {
        return;
    }

    $orderby = $query->get('orderby');
    if (! $orderby || 'ssf_date' === $orderby) {
        $query->set('meta_key', SSF_SITE_NEWSLETTER_DATE_META);
        $query->set('orderby', 'meta_value');
        $query->set('order', 'DESC');
    } elseif ('ssf_year' === $orderby) {
        $query->set('meta_key', SSF_SITE_NEWSLETTER_YEAR_META);
        $query->set('orderby', 'meta_value_num');
        $query->set('order', 'DESC');
    }

    $year = isset($_GET['ssf_newsletter_year']) ? sanitize_text_field(wp_unslash($_GET['ssf_newsletter_year'])) : '';
    if (ssf_site_is_valid_newsletter_year($year)) {
        $query->set('meta_query', array(array('key' => SSF_SITE_NEWSLETTER_YEAR_META, 'value' => $year)));
    }
}
add_action('pre_get_posts', 'ssf_site_newsletter_admin_order');

function ssf_site_newsletter_year_filter(string $post_type): void
{
    if (SSF_SITE_NEWSLETTER_POST_TYPE !== $post_type) {
        return;
    }

    $years = ssf_site_get_newsletter_years(false);
    $selected = isset($_GET['ssf_newsletter_year']) ? sanitize_text_field(wp_unslash($_GET['ssf_newsletter_year'])) : '';
    ?>
    <select name="ssf_newsletter_year">
        <option value=""><?php esc_html_e('Alla år', 'ssf-site'); ?></option>
        <?php foreach ($years as $year) : ?>
            <option value="<?php echo esc_attr($year); ?>" <?php selected($selected, $year); ?>><?php echo esc_html($year); ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}
add_action('restrict_manage_posts', 'ssf_site_newsletter_year_filter');

function ssf_site_get_latest_newsletter(): ?WP_Post
{
    $newsletters = ssf_site_get_newsletters('', 0);
    return $newsletters ? $newsletters[0] : null;
}

function ssf_site_get_newsletter_years(bool $published_only = true): array
{
    $cache_key = $published_only ? 'ssf_newsletter_years_public' : 'ssf_newsletter_years_all';
    $cached = wp_cache_get($cache_key, 'ssf_site');
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;
    $statuses = $published_only ? array('publish') : array('publish', 'draft', 'pending', 'private', 'future');
    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $values = array_merge(array(SSF_SITE_NEWSLETTER_POST_TYPE), $statuses, array(SSF_SITE_NEWSLETTER_YEAR_META));
    $query = "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND p.post_status IN ({$placeholders}) AND pm.meta_key = %s AND pm.meta_value <> '' ORDER BY pm.meta_value DESC";
    $years = array_map(
        'strval',
        $wpdb->get_col(
            call_user_func_array(array($wpdb, 'prepare'), array_merge(array($query), $values))
        )
    );

    wp_cache_set($cache_key, $years, 'ssf_site', 300);
    return $years;
}

function ssf_site_get_newsletters(string $year = '', int $exclude_id = 0): array
{
    $args = array(
        'post_type' => SSF_SITE_NEWSLETTER_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'no_found_rows' => true,
        'meta_query' => array(),
    );
    if ($exclude_id) {
        $args['post__not_in'] = array($exclude_id);
    }
    if (ssf_site_is_valid_newsletter_year($year)) {
        $args['meta_query'][] = array('key' => SSF_SITE_NEWSLETTER_YEAR_META, 'value' => $year);
    }

    $posts = get_posts($args);
    usort($posts, 'ssf_site_compare_newsletters');
    return $posts;
}

function ssf_site_group_newsletters_by_year(array $newsletters): array
{
    $grouped = array();
    foreach ($newsletters as $newsletter) {
        if (! $newsletter instanceof WP_Post) {
            continue;
        }
        $data = ssf_site_newsletter_data($newsletter->ID);
        if (! $data['year']) {
            continue;
        }
        $grouped[$data['year']][] = $newsletter;
    }
    krsort($grouped, SORT_NUMERIC);
    return $grouped;
}

function ssf_site_compare_newsletters(WP_Post $a, WP_Post $b): int
{
    $a_data = ssf_site_newsletter_data($a->ID);
    $b_data = ssf_site_newsletter_data($b->ID);
    $year_compare = ((int) $b_data['year']) <=> ((int) $a_data['year']);
    if (0 !== $year_compare) {
        return $year_compare;
    }
    if ($a_data['date_precision'] !== $b_data['date_precision']) {
        return 'full_date' === $a_data['date_precision'] ? -1 : 1;
    }
    if ('full_date' === $a_data['date_precision']) {
        $date_compare = strcmp((string) $b_data['date'], (string) $a_data['date']);
        if (0 !== $date_compare) {
            return $date_compare;
        }
    }
    $issue_compare = ssf_site_newsletter_issue_sort_value((string) $b_data['issue']) <=> ssf_site_newsletter_issue_sort_value((string) $a_data['issue']);
    if (0 !== $issue_compare) {
        return $issue_compare;
    }
    return strcmp(get_the_title($b), get_the_title($a));
}

function ssf_site_newsletter_issue_sort_value(string $issue): int
{
    return preg_match('/\d+/', $issue, $matches) ? (int) $matches[0] : 0;
}

function ssf_site_newsletter_data(int $post_id): array
{
    $pdf_id = (int) get_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META, true);
    $pdf_size = (int) get_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_SIZE_META, true);
    $date_precision = (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_PRECISION_META, true);
    $date_precision = in_array($date_precision, array('full_date', 'year_only'), true) ? $date_precision : 'full_date';
    return array(
        'series' => (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_SERIES_META, true),
        'issue' => (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_ISSUE_META, true),
        'date' => (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_META, true),
        'date_precision' => $date_precision,
        'year' => (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_YEAR_META, true),
        'pdf_id' => $pdf_id,
        'pdf_url' => $pdf_id && ssf_site_is_pdf_attachment($pdf_id) ? (string) wp_get_attachment_url($pdf_id) : '',
        'pdf_size' => $pdf_id ? size_format($pdf_size ?: ssf_site_newsletter_pdf_size_bytes($pdf_id)) : '',
    );
}

function ssf_site_newsletter_pdf_size(int $attachment_id): string
{
    $bytes = ssf_site_newsletter_pdf_size_bytes($attachment_id);
    return $bytes ? size_format($bytes) : '';
}

function ssf_site_newsletter_pdf_size_bytes(int $attachment_id): int
{
    $file = get_attached_file($attachment_id);
    return $file && file_exists($file) ? (int) filesize($file) : 0;
}

function ssf_site_newsletter_formatted_date(int $post_id): string
{
    $date = (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_META, true);
    if (! ssf_site_is_valid_newsletter_date($date)) {
        return '';
    }
    return wp_date(get_option('date_format'), strtotime($date . ' 12:00:00'));
}

function ssf_site_newsletter_display_date(int $post_id): string
{
    $data = ssf_site_newsletter_data($post_id);
    if ('year_only' === $data['date_precision']) {
        return $data['year'];
    }
    return ssf_site_newsletter_formatted_date($post_id);
}

function ssf_site_render_newsletter_card(WP_Post $post, bool $latest = false): string
{
    $data = ssf_site_newsletter_data($post->ID);
    $excerpt = get_the_excerpt($post);
    $classes = $latest ? 'ssf-newsletter-card ssf-newsletter-card--latest' : 'ssf-newsletter-card';
    if (! has_post_thumbnail($post)) {
        $classes .= ' ssf-newsletter-card--without-image';
    }
    $title = get_the_title($post);
    ob_start();
    ?>
    <article class="<?php echo esc_attr($classes); ?>">
        <?php if (has_post_thumbnail($post)) : ?>
            <a class="ssf-newsletter-card__image" href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo get_the_post_thumbnail($post, 'medium_large', array('loading' => 'lazy')); ?></a>
        <?php else : ?>
            <div class="ssf-newsletter-card__pdf-icon" aria-hidden="true">PDF</div>
        <?php endif; ?>
        <div class="ssf-newsletter-card__content">
            <?php if ($latest) : ?><p class="ssf-eyebrow"><?php esc_html_e('Senaste numret', 'ssf-site'); ?></p><?php endif; ?>
            <?php if ($data['series'] || $data['issue']) : ?><p class="ssf-newsletter-card__issue"><?php echo esc_html(trim($data['series'] . ($data['issue'] ? ' nr ' . $data['issue'] : ''))); ?></p><?php endif; ?>
            <h2><a href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo esc_html($title); ?></a></h2>
            <?php if ($data['year']) : ?><p class="ssf-newsletter-card__date"><?php echo esc_html(ssf_site_newsletter_display_date($post->ID)); ?><?php if ($data['pdf_size']) : ?> <span>PDF · <?php echo esc_html($data['pdf_size']); ?></span><?php endif; ?></p><?php endif; ?>
            <?php if ($excerpt) : ?><p><?php echo esc_html(wp_trim_words($excerpt, 28)); ?></p><?php endif; ?>
            <div class="ssf-actions ssf-actions--compact">
                <?php echo ssf_site_button(sprintf(__('Läs %s', 'ssf-site'), $title), get_permalink($post)); ?>
                <?php if ($data['pdf_url']) : ?><a class="ssf-button ssf-button--ghost" href="<?php echo esc_url($data['pdf_url']); ?>" download><?php printf(esc_html__('Ladda ner %s (PDF)', 'ssf-site'), esc_html($title)); ?></a><?php endif; ?>
            </div>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

function ssf_site_latest_newsletter_shortcode(): string
{
    $newsletter = ssf_site_get_latest_newsletter();
    return $newsletter ? ssf_site_render_newsletter_card($newsletter, true) : '';
}
add_shortcode('ssf_latest_newsletter', 'ssf_site_latest_newsletter_shortcode');

function ssf_site_render_newsletter_import_page(): void
{
    if (! current_user_can('manage_ssf_newsletters')) {
        return;
    }

    $result = get_transient('ssf_site_newsletter_import_result_' . get_current_user_id());
    if ($result) {
        delete_transient('ssf_site_newsletter_import_result_' . get_current_user_id());
    }
    ?>
    <div class="wrap ssf-newsletter-import">
        <h1><?php esc_html_e('Importera äldre nummer', 'ssf-site'); ?></h1>
        <?php ssf_site_render_newsletter_admin_tabs('import'); ?>
        <?php if (is_array($result)) : ?>
            <div class="notice notice-success"><p><?php printf(esc_html__('Import klar. %1$d PDF valda, %2$d importerade, %3$d dubletter, %4$d fel.', 'ssf-site'), (int) $result['selected'], (int) $result['imported'], (int) $result['duplicates'], (int) $result['errors']); ?></p></div>
        <?php endif; ?>
        <p><?php esc_html_e('Välj flera PDF-filer från mediabiblioteket. Filnamnet används bara för förslag; korrigera alltid titel, år och nummer innan import.', 'ssf-site'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ssf-newsletter-import-form">
            <input type="hidden" name="action" value="ssf_import_newsletters">
            <?php wp_nonce_field('ssf_import_newsletters'); ?>
            <p><button type="button" class="button" data-ssf-select-newsletter-import><?php esc_html_e('Välj flera PDF', 'ssf-site'); ?></button></p>
            <div class="ssf-newsletter-import-table" data-ssf-newsletter-import-table></div>
            <?php submit_button(__('Importera', 'ssf-site')); ?>
        </form>
        <?php ssf_site_render_newsletter_import_log(); ?>
    </div>
    <?php
}

function ssf_site_handle_newsletter_import(): void
{
    if (! current_user_can('manage_ssf_newsletters') || ! check_admin_referer('ssf_import_newsletters')) {
        wp_die('Du saknar behörighet att importera nyhetsbrev.');
    }

    $items = isset($_POST['newsletter_import']) && is_array($_POST['newsletter_import']) ? wp_unslash($_POST['newsletter_import']) : array();
    $result = array('selected' => count($items), 'imported' => 0, 'duplicates' => 0, 'errors' => 0);

    foreach ($items as $item) {
        $pdf_id = isset($item['pdf_id']) ? absint($item['pdf_id']) : 0;
        $title = isset($item['title']) ? sanitize_text_field($item['title']) : '';
        $series = isset($item['series']) ? sanitize_text_field($item['series']) : '';
        $issue = isset($item['issue']) ? sanitize_text_field($item['issue']) : '';
        $year = isset($item['year']) ? sanitize_text_field($item['year']) : '';

        if (! $pdf_id || ! ssf_site_is_pdf_attachment($pdf_id) || ! $title || ! ssf_site_is_valid_newsletter_year($year)) {
            $result['errors']++;
            continue;
        }
        if (ssf_site_newsletter_duplicates(0, $issue, $year, $pdf_id)) {
            $result['duplicates']++;
            continue;
        }

        $post_id = wp_insert_post(array('post_type' => SSF_SITE_NEWSLETTER_POST_TYPE, 'post_status' => 'draft', 'post_title' => $title), true);
        if (is_wp_error($post_id)) {
            $result['errors']++;
            continue;
        }

        update_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_SERIES_META, $series);
        update_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_ISSUE_META, $issue);
        update_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_DATE_META, $year . '-01-01');
        update_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_DATE_PRECISION_META, 'year_only');
        update_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_YEAR_META, $year);
        update_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_PDF_META, $pdf_id);
        update_post_meta((int) $post_id, SSF_SITE_NEWSLETTER_PDF_SIZE_META, ssf_site_newsletter_pdf_size_bytes($pdf_id));
        $result['imported']++;
    }

    ssf_site_clear_newsletter_cache();
    ssf_site_add_newsletter_import_log($result);
    set_transient('ssf_site_newsletter_import_result_' . get_current_user_id(), $result, 60);
    wp_safe_redirect(admin_url('admin.php?page=ssf-newsletter-import'));
    exit;
}
add_action('admin_post_ssf_import_newsletters', 'ssf_site_handle_newsletter_import');

function ssf_site_parse_newsletter_filename(string $filename): array
{
    $base = preg_replace('/\.pdf$/i', '', sanitize_file_name($filename));
    $title = trim(str_replace(array('-', '_'), ' ', (string) $base));
    $year = preg_match('/(19|20)\d{2}/', (string) $base, $year_match) ? $year_match[0] : '';
    $issue = '';
    if ($year && preg_match('/(?:^|[-_\s])(?:nr|no)?[-_\s]*0?(\d{1,2})(?:$|[-_\s])/i', (string) str_replace($year, '', (string) $base), $issue_match)) {
        $issue = (string) ((int) $issue_match[1]);
    }
    $series = false !== stripos((string) $base, 'fordevind') || false !== stripos((string) $base, 'fördevind') ? 'Fördevind' : '';

    return array(
        'title' => $series ? trim($series . ($issue ? ' nr ' . $issue : '') . ($year ? ' - ' . $year : '')) : ucwords($title),
        'series' => $series,
        'issue' => $issue,
        'year' => $year,
    );
}

function ssf_site_render_newsletter_import_log(): void
{
    $entries = (array) get_option(SSF_SITE_NEWSLETTER_IMPORT_LOG_OPTION, array());
    if (! $entries) {
        return;
    }
    ?>
    <h2><?php esc_html_e('Senaste importer', 'ssf-site'); ?></h2>
    <table class="widefat striped" style="max-width:760px"><thead><tr><th><?php esc_html_e('Tid', 'ssf-site'); ?></th><th><?php esc_html_e('Användare', 'ssf-site'); ?></th><th><?php esc_html_e('Resultat', 'ssf-site'); ?></th></tr></thead><tbody>
    <?php foreach (array_slice($entries, 0, 10) as $entry) : ?><tr><td><?php echo esc_html((string) ($entry['timestamp'] ?? '')); ?></td><td><?php echo esc_html((string) ($entry['user'] ?? '')); ?></td><td><?php printf(esc_html__('%1$d valda, %2$d importerade, %3$d dubletter, %4$d fel', 'ssf-site'), (int) ($entry['selected'] ?? 0), (int) ($entry['imported'] ?? 0), (int) ($entry['duplicates'] ?? 0), (int) ($entry['errors'] ?? 0)); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php
}

function ssf_site_add_newsletter_import_log(array $result): void
{
    $user = wp_get_current_user();
    $entries = (array) get_option(SSF_SITE_NEWSLETTER_IMPORT_LOG_OPTION, array());
    array_unshift($entries, array(
        'timestamp' => current_time('Y-m-d H:i:s', true),
        'user_id' => get_current_user_id(),
        'user' => $user && $user->exists() ? $user->user_login : 'okänd',
        'selected' => (int) $result['selected'],
        'imported' => (int) $result['imported'],
        'duplicates' => (int) $result['duplicates'],
        'errors' => (int) $result['errors'],
    ));
    update_option(SSF_SITE_NEWSLETTER_IMPORT_LOG_OPTION, array_slice($entries, 0, 100), false);
}

function ssf_site_clear_newsletter_cache(): void
{
    wp_cache_delete('ssf_newsletter_years_public', 'ssf_site');
    wp_cache_delete('ssf_newsletter_years_all', 'ssf_site');
}
