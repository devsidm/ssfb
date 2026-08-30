<?php
/**
 * WordPress administration for SSF documents.
 *
 * @package SSF_Stadgar
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Stadgar_Admin
{
    private SSF_Stadgar_Document $documents;
    private SSF_Stadgar_Extractor $extractor;

    public function __construct(SSF_Stadgar_Document $documents, SSF_Stadgar_Extractor $extractor)
    {
        $this->documents = $documents;
        $this->extractor = $extractor;

        add_action('add_meta_boxes_' . SSF_Stadgar_Document::POST_TYPE, array($this, 'add_meta_boxes'));
        add_action('save_post_' . SSF_Stadgar_Document::POST_TYPE, array($this, 'save_document'), 10, 3);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_post_ssf_stadgar_save_settings', array($this, 'save_settings'));
        add_filter('manage_' . SSF_Stadgar_Document::POST_TYPE . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . SSF_Stadgar_Document::POST_TYPE . '_posts_custom_column', array($this, 'column_content'), 10, 2);
        add_action('wp_ajax_ssf_stadgar_extract_document', array($this, 'extract_document'));
    }

    public function add_meta_boxes(): void
    {
        add_meta_box('ssf_document_details', 'Dokumentuppgifter', array($this, 'render_details'), SSF_Stadgar_Document::POST_TYPE, 'normal', 'high');
        add_meta_box('ssf_document_pdf_outline', 'PDF och snabböversikt', array($this, 'render_pdf_outline'), SSF_Stadgar_Document::POST_TYPE, 'normal', 'default');
        add_meta_box('ssf_document_related', 'Relaterade dokument', array($this, 'render_related'), SSF_Stadgar_Document::POST_TYPE, 'side', 'default');
    }

    public function add_settings_page(): void
    {
        if (class_exists('SSF_Admin_Navigation')) {
            add_submenu_page(
                SSF_Admin_Navigation::CONTENT,
                'Stadgar & dokument',
                'Stadgar & dokument',
                'edit_posts',
                'edit.php?post_type=' . SSF_Stadgar_Document::POST_TYPE,
                '',
                60
            );
            add_submenu_page(
                null,
                'Inställningar för stadgarsidan',
                'Inställningar',
                'manage_options',
                'ssf-stadgar-settings',
                array($this, 'render_settings_page')
            );
            return;
        }

        add_submenu_page(
            'ssf',
            'Inställningar för stadgarsidan',
            'Inställningar',
            'manage_options',
            'ssf-stadgar-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Du saknar behörighet att ändra inställningar.');
        }

        $intro = (string) get_option('ssf_stadgar_intro', 'På denna sida hittar du Sveriges Segelfartygsförbunds gällande stadgar, tidigare versioner och relaterade dokument.');
        $current_note = (string) get_option('ssf_stadgar_current_note', 'Detta är den version av stadgarna som gäller just nu.');
        ?>
        <div class="wrap">
            <h1>Inställningar för stadgarsidan</h1>
            <?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>Inställningarna är sparade.</p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ssf_stadgar_save_settings'); ?>
                <input type="hidden" name="action" value="ssf_stadgar_save_settings">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ssf-stadgar-intro">Ingress</label></th>
                        <td><textarea id="ssf-stadgar-intro" name="settings[intro]" rows="3" class="large-text"><?php echo esc_textarea($intro); ?></textarea><p class="description">Visas direkt under sidans rubrik.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ssf-stadgar-current-note">Text för gällande version</label></th>
                        <td><textarea id="ssf-stadgar-current-note" name="settings[current_note]" rows="2" class="large-text"><?php echo esc_textarea($current_note); ?></textarea><p class="description">Visas i kortet för den gällande versionen.</p></td>
                    </tr>
                </table>
                <?php submit_button('Spara inställningar'); ?>
            </form>
        </div>
        <?php
    }

    public function save_settings(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_stadgar_save_settings')) {
            wp_die('Du saknar behörighet att ändra inställningar.');
        }

        $settings = (array) wp_unslash($_POST['settings'] ?? array());
        update_option('ssf_stadgar_intro', sanitize_textarea_field($settings['intro'] ?? ''), false);
        update_option('ssf_stadgar_current_note', sanitize_textarea_field($settings['current_note'] ?? ''), false);

        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=ssf-stadgar-settings')));
        exit;
    }

    public function render_details(WP_Post $post): void
    {
        $data = $this->documents->data($post->ID);
        wp_nonce_field('ssf_save_document_' . $post->ID, 'ssf_document_nonce');
        ?>
        <div class="ssf-document-admin-grid">
            <label>Dokumenttyp
                <select name="ssf_document_type">
                    <?php foreach (SSF_Stadgar_Document::types() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($data['type'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Version
                <input type="text" name="ssf_document_version" value="<?php echo esc_attr($data['version']); ?>" placeholder="Exempel: 2026-10-18">
            </label>
            <label>Antagningsdatum
                <input type="date" name="ssf_document_adopted_date" value="<?php echo esc_attr($data['adopted_date']); ?>">
            </label>
            <label>Antagen av
                <input type="text" name="ssf_document_adopted_by" value="<?php echo esc_attr($data['adopted_by']); ?>" placeholder="Exempel: SSF:s årsmöte">
            </label>
            <label>Visningsordning
                <input type="number" name="ssf_document_sort" min="0" step="1" value="<?php echo esc_attr((string) $data['sort']); ?>">
            </label>
            <label>Dokumentstatus
                <select name="ssf_document_publication_status">
                    <option value="draft" <?php selected($post->post_status, 'draft'); ?>>Utkast</option>
                    <option value="publish" <?php selected($post->post_status, 'publish'); ?>>Publicerad</option>
                    <option value="<?php echo esc_attr(SSF_Stadgar_Document::ARCHIVED_STATUS); ?>" <?php selected($post->post_status, SSF_Stadgar_Document::ARCHIVED_STATUS); ?>>Arkiverad</option>
                </select>
            </label>
        </div>
        <p class="ssf-document-admin-check">
            <label><input type="checkbox" name="ssf_document_current" value="1" <?php checked($data['current']); ?>> Detta är gällande version av stadgarna</label>
        </p>
        <p class="description">En gällande version kan bara vara publicerad. När en publicerad stadga markeras som gällande arkiveras den tidigare gällande versionen automatiskt.</p>
        <div class="ssf-document-admin-grid ssf-document-admin-grid--wide">
            <label>Kort beskrivning
                <textarea name="ssf_document_summary" rows="3" placeholder="Kort sammanfattning som visas på stadgarsidan."><?php echo esc_textarea($data['summary']); ?></textarea>
            </label>
            <label>Vad ändrades i denna version?
                <textarea name="ssf_document_change_note" rows="3" placeholder="Valfri versionsanteckning för historiken."><?php echo esc_textarea($data['change_note']); ?></textarea>
            </label>
        </div>
        <?php
    }

    public function render_pdf_outline(WP_Post $post): void
    {
        $data = $this->documents->data($post->ID);
        $pdf_url = $data['pdf_id'] ? wp_get_attachment_url($data['pdf_id']) : '';
        $nonce = wp_create_nonce('ssf_stadgar_extract_' . $post->ID);
        $extracted_text = $data['extracted_text'];
        ?>
        <div class="ssf-document-admin-pdf" data-document-id="<?php echo esc_attr((string) $post->ID); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="ssf_document_pdf_id" class="ssf-document-pdf-id" value="<?php echo esc_attr((string) $data['pdf_id']); ?>">
            <p>
                <button type="button" class="button ssf-document-select-pdf">Välj PDF</button>
                <button type="button" class="button ssf-document-analyse-pdf" <?php disabled(! $data['pdf_id']); ?>>Analysera PDF</button>
                <span class="ssf-document-pdf-name">
                    <?php if ($pdf_url) : ?><a href="<?php echo esc_url($pdf_url); ?>" target="_blank" rel="noopener">Öppna vald PDF</a><?php else : ?>Ingen PDF vald<?php endif; ?>
                </span>
            </p>
            <p class="description">Analysen försöker skapa en preliminär paragraföversikt. Kontrollera alltid resultatet. Om PDF:en inte kan läsas fungerar den manuella redigeringen nedan ändå.</p>
            <div class="ssf-document-analysis-result" aria-live="polite"></div>
            <details class="ssf-document-extracted-text"<?php echo $extracted_text ? ' open' : ''; ?>>
                <summary>Extraherat textinnehåll för granskning</summary>
                <p class="description">Texten är ett arbetsunderlag från PDF:en. Den publiceras inte automatiskt och ersätter aldrig webbtexten i redigeraren.</p>
                <textarea class="large-text code" rows="12" readonly aria-label="Extraherat textinnehåll från PDF"><?php echo esc_textarea($extracted_text); ?></textarea>
            </details>
        </div>
        <label for="ssf-document-outline"><strong>Snabböversikt</strong></label>
        <textarea id="ssf-document-outline" name="ssf_document_outline" class="large-text code ssf-document-outline" rows="12" placeholder="§ 1 Namn och ändamål | 1-namn-och-andamal&#10;§ 2 Medlemskap | 2-medlemskap"><?php echo esc_textarea($this->documents->outline_as_text($post->ID)); ?></textarea>
        <p class="description">En rad per paragraf eller rubrik. Texten före <code>|</code> visas för besökaren. Delen efter skapar den stabila ankarlänken. Lämna ankaret tomt så skapas det automatiskt från rubriken.</p>
        <p class="description">Skriv eller klistra in den fullständiga läsbara versionen i den vanliga redigeraren ovan. Använd rubriker för varje paragraf, så kopplas snabböversikten till rätt del av sidan.</p>
        <?php
    }

    public function render_related(WP_Post $post): void
    {
        $selected = $this->documents->data($post->ID)['related_ids'];
        $documents = get_posts(
            array(
                'post_type'      => SSF_Stadgar_Document::POST_TYPE,
                'post_status'    => array('draft', 'publish', SSF_Stadgar_Document::ARCHIVED_STATUS),
                'posts_per_page' => -1,
                'post__not_in'   => array($post->ID),
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
        ?>
        <label for="ssf-document-related"><strong>Visa tillsammans med dokumentet</strong></label>
        <select id="ssf-document-related" name="ssf_document_related_ids[]" multiple size="9" class="widefat">
            <?php foreach ($documents as $document) : ?>
                <?php $data = $this->documents->data($document->ID); ?>
                <option value="<?php echo esc_attr((string) $document->ID); ?>" <?php selected(in_array($document->ID, $selected, true)); ?>>
                    <?php echo esc_html($document->post_title . ' - ' . $this->documents->type_label($data['type'])); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">Endast publicerade relaterade dokument visas på den publika sidan.</p>
        <?php
    }

    public function save_document(int $post_id, WP_Post $post, bool $update): void
    {
        if (
            ! isset($_POST['ssf_document_nonce']) ||
            ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_document_nonce'])), 'ssf_save_document_' . $post_id) ||
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ||
            ! current_user_can('edit_post', $post_id)
        ) {
            return;
        }

        $type = sanitize_key(wp_unslash($_POST['ssf_document_type'] ?? 'ovrigt'));
        if (! array_key_exists($type, SSF_Stadgar_Document::types())) {
            $type = 'ovrigt';
        }

        $previous_pdf_id = (int) get_post_meta($post_id, '_ssf_document_pdf_id', true);
        $pdf_id = absint($_POST['ssf_document_pdf_id'] ?? 0);
        if ($pdf_id && 'application/pdf' !== get_post_mime_type($pdf_id)) {
            $pdf_id = 0;
        }

        update_post_meta($post_id, '_ssf_document_type', $type);
        update_post_meta($post_id, '_ssf_document_version', sanitize_text_field(wp_unslash($_POST['ssf_document_version'] ?? '')));
        update_post_meta($post_id, '_ssf_document_adopted_date', $this->sanitize_date((string) wp_unslash($_POST['ssf_document_adopted_date'] ?? '')));
        update_post_meta($post_id, '_ssf_document_adopted_by', sanitize_text_field(wp_unslash($_POST['ssf_document_adopted_by'] ?? '')));
        update_post_meta($post_id, '_ssf_document_summary', sanitize_textarea_field(wp_unslash($_POST['ssf_document_summary'] ?? '')));
        update_post_meta($post_id, '_ssf_document_change_note', sanitize_textarea_field(wp_unslash($_POST['ssf_document_change_note'] ?? '')));
        update_post_meta($post_id, '_ssf_document_pdf_id', $pdf_id);
        update_post_meta($post_id, '_ssf_document_current', ! empty($_POST['ssf_document_current']) ? '1' : '0');
        update_post_meta($post_id, '_ssf_document_sort', max(0, (int) ($_POST['ssf_document_sort'] ?? 0)));
        update_post_meta($post_id, '_ssf_document_related_ids', array_values(array_filter(array_map('absint', (array) ($_POST['ssf_document_related_ids'] ?? array())))));

        $raw_outline = (string) wp_unslash($_POST['ssf_document_outline'] ?? '');
        $outline = $this->documents->save_outline($post_id, $raw_outline);

        if ($pdf_id && $pdf_id !== $previous_pdf_id && ! $outline) {
            $analysis = $this->extractor->extract($pdf_id);
            if (! empty($analysis['text'])) {
                update_post_meta($post_id, '_ssf_document_extracted_text', sanitize_textarea_field($analysis['text']));
            }
            if (! empty($analysis['outline'])) {
                update_post_meta($post_id, '_ssf_document_outline', wp_json_encode($analysis['outline'], JSON_UNESCAPED_UNICODE));
            }
        }

        $status = sanitize_key(wp_unslash($_POST['ssf_document_publication_status'] ?? $post->post_status));
        $this->documents->set_status($post_id, $status);

        if (! empty($_POST['ssf_document_current']) && 'publish' === get_post_status($post_id)) {
            $this->documents->enforce_current($post_id);
        }
    }

    public function extract_document(): void
    {
        $document_id = absint($_POST['document_id'] ?? 0);
        if (! $document_id || ! current_user_can('edit_post', $document_id) || ! check_ajax_referer('ssf_stadgar_extract_' . $document_id, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Du saknar behörighet att analysera dokumentet.'), 403);
        }

        $pdf_id = (int) get_post_meta($document_id, '_ssf_document_pdf_id', true);
        $analysis = $this->extractor->extract($pdf_id);
        $extracted_text = '';
        if (! empty($analysis['text'])) {
            $extracted_text = sanitize_textarea_field($analysis['text']);
            update_post_meta($document_id, '_ssf_document_extracted_text', $extracted_text);
        }

        $outline_text = '';
        foreach ((array) ($analysis['outline'] ?? array()) as $item) {
            $outline_text .= ($outline_text ? "\n" : '') . $item['title'] . ' | ' . $item['anchor'];
        }

        wp_send_json_success(
            array(
                'message'      => $analysis['message'],
                'outline_text' => $outline_text,
                'extracted_text' => $extracted_text,
            )
        );
    }

    public function enqueue_assets(string $hook): void
    {
        $screen = get_current_screen();
        if (! $screen || SSF_Stadgar_Document::POST_TYPE !== $screen->post_type) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('ssf-stadgar-admin', SSF_STADGAR_URL . 'assets/css/ssf-stadgar-admin.css', array(), SSF_STADGAR_VERSION);
        wp_enqueue_script('ssf-stadgar-admin', SSF_STADGAR_URL . 'assets/js/ssf-stadgar-admin.js', array('jquery'), SSF_STADGAR_VERSION, true);
        wp_localize_script(
            'ssf-stadgar-admin',
            'ssfStadgarAdmin',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'confirmCurrent' => 'Vill du göra denna version till gällande stadgar? Nuvarande gällande version flyttas då till versionshistoriken när du publicerar dokumentet.',
            )
        );
    }

    public function columns(array $columns): array
    {
        return array(
            'cb'             => $columns['cb'],
            'title'          => 'Dokument',
            'ssf_type'       => 'Typ',
            'ssf_version'    => 'Version',
            'ssf_adopted'    => 'Antaget',
            'ssf_current'    => 'Gällande',
            'ssf_pdf'        => 'PDF',
            'date'           => $columns['date'],
        );
    }

    public function column_content(string $column, int $post_id): void
    {
        $data = $this->documents->data($post_id);
        if ('ssf_type' === $column) {
            echo esc_html($this->documents->type_label($data['type']));
        }
        if ('ssf_version' === $column) {
            echo esc_html($data['version'] ?: '-');
        }
        if ('ssf_adopted' === $column) {
            echo esc_html($data['adopted_date'] ? wp_date('j F Y', strtotime($data['adopted_date'])) : '-');
        }
        if ('ssf_current' === $column) {
            echo $data['current'] ? '<strong>Ja</strong>' : 'Nej';
        }
        if ('ssf_pdf' === $column) {
            $url = $this->documents->pdf_url($post_id);
            echo $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Öppna PDF</a>' : '-';
        }
    }

    private function sanitize_date(string $value): string
    {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }
}
