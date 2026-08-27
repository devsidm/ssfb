<?php
/**
 * Newsletter post type, administration, and shared presentation helpers.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

const SSF_SITE_NEWSLETTER_POST_TYPE = 'ssf_newsletter';
const SSF_SITE_NEWSLETTER_ISSUE_META = '_ssf_newsletter_issue';
const SSF_SITE_NEWSLETTER_DATE_META = '_ssf_newsletter_date';
const SSF_SITE_NEWSLETTER_YEAR_META = '_ssf_newsletter_year';
const SSF_SITE_NEWSLETTER_PDF_META = '_ssf_newsletter_pdf_id';
const SSF_SITE_NEWSLETTER_PDF_SIZE_META = '_ssf_newsletter_pdf_size';

function ssf_site_register_newsletter_post_type(): void
{
    register_post_type(
        SSF_SITE_NEWSLETTER_POST_TYPE,
        array(
            'labels' => array(
                'name'               => __('Nyhetsbrev', 'ssf-site'),
                'singular_name'      => __('Nyhetsbrev', 'ssf-site'),
                'add_new'            => __('Lägg till nytt', 'ssf-site'),
                'add_new_item'       => __('Lägg till nyhetsbrev', 'ssf-site'),
                'edit_item'          => __('Redigera nyhetsbrev', 'ssf-site'),
                'new_item'           => __('Nytt nyhetsbrev', 'ssf-site'),
                'view_item'          => __('Visa nyhetsbrev', 'ssf-site'),
                'search_items'       => __('Sök nyhetsbrev', 'ssf-site'),
                'not_found'          => __('Inga nyhetsbrev hittades.', 'ssf-site'),
                'all_items'          => __('Alla nyhetsbrev', 'ssf-site'),
                'menu_name'          => __('Nyhetsbrev', 'ssf-site'),
                'item_published'     => __('Nyhetsbrevet är publicerat.', 'ssf-site'),
                'item_updated'       => __('Nyhetsbrevet är uppdaterat.', 'ssf-site'),
            ),
            'public'             => true,
            'show_in_rest'       => true,
            'has_archive'        => 'nyhetsbrev',
            'rewrite'            => array('slug' => 'nyhetsbrev', 'with_front' => false),
            'menu_icon'          => 'dashicons-media-document',
            'supports'           => array('title', 'editor', 'excerpt', 'thumbnail', 'revisions'),
            'capability_type'    => array('ssf_newsletter', 'ssf_newsletters'),
            'map_meta_cap'       => true,
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

function ssf_site_newsletter_settings(): array
{
    return wp_parse_args(
        (array) get_option('ssf_site_newsletter_settings', array()),
        array(
            'archive_intro' => 'Här hittar du Sveriges Segelfartygsförbunds senaste nyhetsbrev samt tidigare utgåvor.',
        )
    );
}

function ssf_site_register_newsletter_settings(): void
{
    register_setting(
        'ssf_site_newsletter_settings',
        'ssf_site_newsletter_settings',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'ssf_site_sanitize_newsletter_settings',
            'default'           => ssf_site_newsletter_settings(),
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

function ssf_site_add_newsletter_settings_page(): void
{
    add_submenu_page(
        'edit.php?post_type=' . SSF_SITE_NEWSLETTER_POST_TYPE,
        __('Inställningar för nyhetsbrev', 'ssf-site'),
        __('Inställningar', 'ssf-site'),
        'manage_ssf_newsletters',
        'ssf-newsletter-settings',
        'ssf_site_render_newsletter_settings_page'
    );
}
add_action('admin_menu', 'ssf_site_add_newsletter_settings_page');

function ssf_site_render_newsletter_settings_page(): void
{
    if (! current_user_can('manage_ssf_newsletters')) {
        return;
    }

    $settings = ssf_site_newsletter_settings();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Inställningar för nyhetsbrev', 'ssf-site'); ?></h1>
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
    add_meta_box(
        'ssf-newsletter-details',
        __('Nyhetsbrevets uppgifter', 'ssf-site'),
        'ssf_site_render_newsletter_meta_box',
        SSF_SITE_NEWSLETTER_POST_TYPE,
        'side',
        'high'
    );
}
add_action('add_meta_boxes_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_add_newsletter_meta_boxes');

function ssf_site_render_newsletter_meta_box(WP_Post $post): void
{
    $issue = (string) get_post_meta($post->ID, SSF_SITE_NEWSLETTER_ISSUE_META, true);
    $date = (string) get_post_meta($post->ID, SSF_SITE_NEWSLETTER_DATE_META, true);
    $pdf_id = (int) get_post_meta($post->ID, SSF_SITE_NEWSLETTER_PDF_META, true);
    $pdf_name = $pdf_id ? get_the_title($pdf_id) : '';
    $duplicates = ssf_site_newsletter_duplicates($post->ID, $issue, $date, $pdf_id);
    wp_nonce_field('ssf_site_save_newsletter', 'ssf_site_newsletter_nonce');
    ?>
    <p><label for="ssf-newsletter-issue"><strong><?php esc_html_e('Utgåva', 'ssf-site'); ?></strong></label><br>
        <input class="widefat" id="ssf-newsletter-issue" name="ssf_newsletter_issue" value="<?php echo esc_attr($issue); ?>" placeholder="<?php esc_attr_e('Exempel: Augusti 2026', 'ssf-site'); ?>"></p>
    <p><label for="ssf-newsletter-date"><strong><?php esc_html_e('Publiceringsdatum', 'ssf-site'); ?></strong></label><br>
        <input class="widefat" id="ssf-newsletter-date" type="date" name="ssf_newsletter_date" value="<?php echo esc_attr($date); ?>"></p>
    <p><label for="ssf-newsletter-excerpt"><strong><?php esc_html_e('Kort beskrivning', 'ssf-site'); ?></strong></label><br>
        <textarea class="widefat" id="ssf-newsletter-excerpt" name="ssf_newsletter_excerpt" rows="4"><?php echo esc_textarea($post->post_excerpt); ?></textarea></p>
    <p>
        <strong><?php esc_html_e('PDF-fil', 'ssf-site'); ?></strong><br>
        <span class="description" data-ssf-newsletter-pdf-name><?php echo esc_html($pdf_name ?: __('Ingen PDF vald.', 'ssf-site')); ?></span>
        <input type="hidden" name="ssf_newsletter_pdf_id" value="<?php echo esc_attr((string) $pdf_id); ?>" data-ssf-newsletter-pdf-id>
    </p>
    <p>
        <button type="button" class="button" data-ssf-select-newsletter-pdf><?php esc_html_e('Välj eller ladda upp PDF', 'ssf-site'); ?></button>
        <button type="button" class="button-link-delete" data-ssf-remove-newsletter-pdf <?php disabled(! $pdf_id); ?>><?php esc_html_e('Ta bort PDF', 'ssf-site'); ?></button>
    </p>
    <p class="description"><?php esc_html_e('År skapas automatiskt från publiceringsdatumet. Omslagsbild väljs i den vanliga rutan Utvald bild.', 'ssf-site'); ?></p>
    <?php if ($duplicates) : ?>
        <div class="notice notice-warning inline"><p><?php echo esc_html(implode(' ', $duplicates)); ?></p></div>
    <?php endif; ?>
    <?php
}

function ssf_site_enqueue_newsletter_admin_assets(string $hook): void
{
    $screen = get_current_screen();
    if (! $screen || SSF_SITE_NEWSLETTER_POST_TYPE !== $screen->post_type || ! in_array($hook, array('post.php', 'post-new.php'), true)) {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script(
        'ssf-newsletter-admin',
        SSF_SITE_URL . 'assets/js/ssf-newsletters-admin.js',
        array('jquery'),
        SSF_SITE_VERSION,
        true
    );
}
add_action('admin_enqueue_scripts', 'ssf_site_enqueue_newsletter_admin_assets');

function ssf_site_save_newsletter(int $post_id, WP_Post $post): void
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (! isset($_POST['ssf_site_newsletter_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_site_newsletter_nonce'])), 'ssf_site_save_newsletter')) {
        return;
    }

    if (! current_user_can('edit_post', $post_id)) {
        return;
    }

    $issue = isset($_POST['ssf_newsletter_issue']) ? sanitize_text_field(wp_unslash($_POST['ssf_newsletter_issue'])) : '';
    $date = isset($_POST['ssf_newsletter_date']) ? sanitize_text_field(wp_unslash($_POST['ssf_newsletter_date'])) : '';
    $pdf_id = isset($_POST['ssf_newsletter_pdf_id']) ? absint($_POST['ssf_newsletter_pdf_id']) : 0;
    $excerpt = isset($_POST['ssf_newsletter_excerpt']) ? sanitize_textarea_field(wp_unslash($_POST['ssf_newsletter_excerpt'])) : '';

    if ($date && ! ssf_site_is_valid_newsletter_date($date)) {
        $date = '';
    }

    update_post_meta($post_id, SSF_SITE_NEWSLETTER_ISSUE_META, $issue);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_META, $date);
    update_post_meta($post_id, SSF_SITE_NEWSLETTER_YEAR_META, $date ? substr($date, 0, 4) : '');

    if ($pdf_id && ssf_site_is_pdf_attachment($pdf_id)) {
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META, $pdf_id);
        update_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_SIZE_META, ssf_site_newsletter_pdf_size_bytes($pdf_id));
    } else {
        delete_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META);
        delete_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_SIZE_META);
    }

    if ($excerpt !== $post->post_excerpt) {
        remove_action('save_post_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_save_newsletter', 10);
        wp_update_post(array('ID' => $post_id, 'post_excerpt' => $excerpt));
        add_action('save_post_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_save_newsletter', 10, 2);
    }
}
add_action('save_post_' . SSF_SITE_NEWSLETTER_POST_TYPE, 'ssf_site_save_newsletter', 10, 2);

function ssf_site_is_valid_newsletter_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function ssf_site_is_pdf_attachment(int $attachment_id): bool
{
    $file = get_attached_file($attachment_id);
    $filetype = wp_check_filetype($file);
    return 'application/pdf' === get_post_mime_type($attachment_id) && 'pdf' === strtolower((string) $filetype['ext']);
}

function ssf_site_newsletter_duplicates(int $post_id, string $issue, string $date, int $pdf_id): array
{
    $warnings = array();
    $year = $date ? substr($date, 0, 4) : '';

    if ($issue && $year) {
        $same_issue = get_posts(
            array(
                'post_type'      => SSF_SITE_NEWSLETTER_POST_TYPE,
                'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => 1,
                'post__not_in'   => array($post_id),
                'meta_query'     => array(
                    'relation' => 'AND',
                    array('key' => SSF_SITE_NEWSLETTER_ISSUE_META, 'value' => $issue),
                    array('key' => SSF_SITE_NEWSLETTER_YEAR_META, 'value' => $year),
                ),
            )
        );
        if ($same_issue) {
            $warnings[] = __('En annan utgåva med samma utgåva och år finns redan. Du kan fortsätta om det är avsiktligt.', 'ssf-site');
        }
    }

    if ($pdf_id) {
        $same_pdf = get_posts(
            array(
                'post_type'      => SSF_SITE_NEWSLETTER_POST_TYPE,
                'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => 1,
                'post__not_in'   => array($post_id),
                'meta_key'       => SSF_SITE_NEWSLETTER_PDF_META,
                'meta_value'     => $pdf_id,
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
        'cb'               => $columns['cb'],
        'title'            => __('Titel', 'ssf-site'),
        'ssf_issue'        => __('Utgåva', 'ssf-site'),
        'ssf_date'         => __('Datum', 'ssf-site'),
        'ssf_year'         => __('År', 'ssf-site'),
        'ssf_pdf'          => __('PDF', 'ssf-site'),
        'date'             => __('Status', 'ssf-site'),
    );
}
add_filter('manage_' . SSF_SITE_NEWSLETTER_POST_TYPE . '_posts_columns', 'ssf_site_newsletter_columns');

function ssf_site_render_newsletter_column(string $column, int $post_id): void
{
    if ('ssf_issue' === $column) {
        echo esc_html((string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_ISSUE_META, true));
        return;
    }

    if ('ssf_date' === $column) {
        echo esc_html(ssf_site_newsletter_formatted_date($post_id));
        return;
    }

    if ('ssf_year' === $column) {
        echo esc_html((string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_YEAR_META, true));
        return;
    }

    if ('ssf_pdf' === $column) {
        $pdf_id = (int) get_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META, true);
        if ($pdf_id) {
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
    }

    $year = isset($_GET['ssf_newsletter_year']) ? sanitize_text_field(wp_unslash($_GET['ssf_newsletter_year'])) : '';
    if (preg_match('/^\\d{4}$/', $year)) {
        $query->set('meta_query', array(array('key' => SSF_SITE_NEWSLETTER_YEAR_META, 'value' => $year)));
    }
}
add_action('pre_get_posts', 'ssf_site_newsletter_admin_order');

function ssf_site_newsletter_year_filter(string $post_type): void
{
    if (SSF_SITE_NEWSLETTER_POST_TYPE !== $post_type) {
        return;
    }

    global $wpdb;
    $years = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value DESC",
            SSF_SITE_NEWSLETTER_YEAR_META
        )
    );
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
    $newsletters = get_posts(
        array(
            'post_type'      => SSF_SITE_NEWSLETTER_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => SSF_SITE_NEWSLETTER_DATE_META,
            'orderby'        => 'meta_value',
            'order'          => 'DESC',
        )
    );

    return $newsletters ? $newsletters[0] : null;
}

function ssf_site_get_newsletter_years(): array
{
    global $wpdb;
    return array_map(
        'strval',
        $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key = %s AND pm.meta_value <> '' ORDER BY pm.meta_value DESC",
                SSF_SITE_NEWSLETTER_POST_TYPE,
                SSF_SITE_NEWSLETTER_YEAR_META
            )
        )
    );
}

function ssf_site_get_newsletters(string $year = '', int $exclude_id = 0): array
{
    $args = array(
        'post_type'      => SSF_SITE_NEWSLETTER_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => SSF_SITE_NEWSLETTER_DATE_META,
        'orderby'        => 'meta_value',
        'order'          => 'DESC',
    );
    if ($exclude_id) {
        $args['post__not_in'] = array($exclude_id);
    }
    if (preg_match('/^\\d{4}$/', $year)) {
        $args['meta_query'] = array(array('key' => SSF_SITE_NEWSLETTER_YEAR_META, 'value' => $year));
    }

    return get_posts($args);
}

function ssf_site_newsletter_data(int $post_id): array
{
    $pdf_id = (int) get_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_META, true);
    $pdf_size = (int) get_post_meta($post_id, SSF_SITE_NEWSLETTER_PDF_SIZE_META, true);
    return array(
        'issue'   => (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_ISSUE_META, true),
        'date'    => (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_DATE_META, true),
        'year'    => (string) get_post_meta($post_id, SSF_SITE_NEWSLETTER_YEAR_META, true),
        'pdf_id'  => $pdf_id,
        'pdf_url' => $pdf_id ? (string) wp_get_attachment_url($pdf_id) : '',
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

function ssf_site_render_newsletter_card(WP_Post $post, bool $latest = false): string
{
    $data = ssf_site_newsletter_data($post->ID);
    $excerpt = get_the_excerpt($post);
    $classes = $latest ? 'ssf-newsletter-card ssf-newsletter-card--latest' : 'ssf-newsletter-card';
    if (! has_post_thumbnail($post)) {
        $classes .= ' ssf-newsletter-card--without-image';
    }
    ob_start();
    ?>
    <article class="<?php echo esc_attr($classes); ?>">
        <?php if (has_post_thumbnail($post)) : ?>
            <a class="ssf-newsletter-card__image" href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo get_the_post_thumbnail($post, 'medium_large', array('loading' => 'lazy')); ?></a>
        <?php endif; ?>
        <div class="ssf-newsletter-card__content">
            <?php if ($latest) : ?><p class="ssf-eyebrow"><?php esc_html_e('Senaste nyhetsbrevet', 'ssf-site'); ?></p><?php endif; ?>
            <?php if ($data['issue']) : ?><p class="ssf-newsletter-card__issue"><?php echo esc_html($data['issue']); ?></p><?php endif; ?>
            <h2><a href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></h2>
            <?php if ($data['date']) : ?><p class="ssf-newsletter-card__date"><?php printf(esc_html__('Publicerat %s', 'ssf-site'), esc_html(ssf_site_newsletter_formatted_date($post->ID))); ?></p><?php endif; ?>
            <?php if ($excerpt) : ?><p><?php echo esc_html(wp_trim_words($excerpt, 28)); ?></p><?php endif; ?>
            <div class="ssf-actions ssf-actions--compact">
                <?php echo ssf_site_button(__('Läs nyhetsbrevet', 'ssf-site'), get_permalink($post)); ?>
                <?php if ($data['pdf_url']) : ?><a class="ssf-button ssf-button--ghost" href="<?php echo esc_url($data['pdf_url']); ?>" download><?php esc_html_e('Ladda ner PDF', 'ssf-site'); ?><?php if ($data['pdf_size']) : ?> <span class="screen-reader-text">(<?php echo esc_html($data['pdf_size']); ?>)</span><?php endif; ?></a><?php endif; ?>
            </div>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

function ssf_site_latest_newsletter_shortcode(): string
{
    $newsletter = ssf_site_get_latest_newsletter();
    if (! $newsletter) {
        return '';
    }

    return ssf_site_render_newsletter_card($newsletter, true);
}
add_shortcode('ssf_latest_newsletter', 'ssf_site_latest_newsletter_shortcode');
