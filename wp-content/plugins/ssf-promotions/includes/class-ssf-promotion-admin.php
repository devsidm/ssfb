<?php

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Promotion_Admin
{
    private SSF_Promotion_Repository $repository;
    private SSF_Promotion_Relations $relations;
    private SSF_Promotion_Renderer $renderer;
    private bool $date_error = false;

    public function __construct(SSF_Promotion_Repository $repository, SSF_Promotion_Relations $relations, SSF_Promotion_Renderer $renderer)
    {
        $this->repository = $repository;
        $this->relations = $relations;
        $this->renderer = $renderer;

        add_action('admin_menu', array($this, 'register_menu'), 35);
        add_action('add_meta_boxes_' . SSF_Promotion_Repository::POST_TYPE, array($this, 'add_meta_boxes'));
        add_action('add_meta_boxes_ssf_annual_meeting', array($this, 'add_annual_meeting_box'));
        add_action('save_post_' . SSF_Promotion_Repository::POST_TYPE, array($this, 'save'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_notices', array($this, 'notices'));
        add_action('admin_post_ssf_duplicate_promotion', array($this, 'duplicate'));
        add_filter('manage_' . SSF_Promotion_Repository::POST_TYPE . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . SSF_Promotion_Repository::POST_TYPE . '_posts_custom_column', array($this, 'column'), 10, 2);
        add_filter('post_row_actions', array($this, 'row_actions'), 10, 2);
        add_filter('views_edit-' . SSF_Promotion_Repository::POST_TYPE, array($this, 'views'));
        add_action('pre_get_posts', array($this, 'filter_list'));
        add_action('all_admin_notices', array($this, 'active_summary'));
        add_filter('use_block_editor_for_post_type', array($this, 'disable_block_editor'), 10, 2);
        add_filter('enter_title_here', array($this, 'title_placeholder'), 10, 2);
        add_filter('default_title', array($this, 'default_title'), 10, 2);
        add_filter('default_excerpt', array($this, 'default_excerpt'), 10, 2);
        add_action('ssf_admin_overview_cards', array($this, 'render_overview_card'));
        add_action('ssf_admin_content_cards', array($this, 'render_content_card'));
    }

    public function register_menu(): void
    {
        if (! current_user_can(SSF_Promotions::CAPABILITY)) {
            return;
        }
        if (class_exists('SSF_Admin_Navigation')) {
            add_submenu_page(
                SSF_Admin_Navigation::CONTENT,
                __('Aktuellt', 'ssf-promotions'),
                __('Aktuellt', 'ssf-promotions'),
                SSF_Promotions::CAPABILITY,
                'edit.php?post_type=' . SSF_Promotion_Repository::POST_TYPE,
                '',
                30
            );
            return;
        }
        add_menu_page(
            __('SSF Aktuellt', 'ssf-promotions'),
            __('Aktuellt', 'ssf-promotions'),
            SSF_Promotions::CAPABILITY,
            'edit.php?post_type=' . SSF_Promotion_Repository::POST_TYPE,
            '',
            'dashicons-megaphone',
            24
        );
    }

    public function add_meta_boxes(WP_Post $post): void
    {
        remove_meta_box('postexcerpt', SSF_Promotion_Repository::POST_TYPE, 'normal');
        add_meta_box('ssf-promotion-content', __('Budskap', 'ssf-promotions'), array($this, 'render_fields'), SSF_Promotion_Repository::POST_TYPE, 'normal', 'high');
        add_meta_box('ssf-promotion-preview', __('Förhandsgranskning', 'ssf-promotions'), array($this, 'render_preview'), SSF_Promotion_Repository::POST_TYPE, 'side', 'high');
        add_meta_box('ssf-promotion-status', __('Visning', 'ssf-promotions'), array($this, 'render_status'), SSF_Promotion_Repository::POST_TYPE, 'side', 'default');
    }

    public function add_annual_meeting_box(WP_Post $post): void
    {
        if (! current_user_can(SSF_Promotions::CAPABILITY)) {
            return;
        }
        add_meta_box('ssf-promotion-annual-action', __('Aktuellt på startsidan', 'ssf-promotions'), array($this, 'render_annual_meeting_box'), 'ssf_annual_meeting', 'side', 'default');
    }

    public function render_fields(WP_Post $post): void
    {
        wp_nonce_field('ssf_save_promotion', 'ssf_promotion_nonce');
        $data = $this->form_data($post);
        $providers = $this->relations->providers();
        ?>
        <div class="ssf-promotion-editor" data-ssf-promotion-editor>
            <div class="ssf-promotion-field ssf-promotion-field--wide">
                <label for="ssf-promotion-excerpt"><?php esc_html_e('Kort text', 'ssf-promotions'); ?></label>
                <textarea id="ssf-promotion-excerpt" name="excerpt" rows="3" maxlength="320" placeholder="<?php esc_attr_e('Vad händer och vad behöver besökaren göra?', 'ssf-promotions'); ?>" data-preview-text><?php echo esc_textarea($post->post_excerpt); ?></textarea>
                <p class="description"><?php esc_html_e('Skriv högst två korta meningar.', 'ssf-promotions'); ?></p>
            </div>

            <div class="ssf-promotion-field">
                <label for="ssf-promotion-type"><?php esc_html_e('Typ', 'ssf-promotions'); ?></label>
                <select id="ssf-promotion-type" name="ssf_promotion_type" data-preview-type>
                    <?php foreach ($this->type_labels() as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($data['type'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                </select>
            </div>

            <div class="ssf-promotion-field">
                <label for="ssf-promotion-priority"><?php esc_html_e('Prioritet', 'ssf-promotions'); ?></label>
                <select id="ssf-promotion-priority" name="ssf_promotion_priority" data-preview-priority>
                    <?php foreach ($this->priority_labels() as $key => $label) : ?><option value="<?php echo esc_attr((string) $key); ?>" <?php selected($data['priority'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                </select>
            </div>

            <fieldset class="ssf-promotion-field ssf-promotion-field--wide">
                <legend><?php esc_html_e('Länk', 'ssf-promotions'); ?></legend>
                <div class="ssf-promotion-grid">
                    <div class="ssf-promotion-field">
                        <label for="ssf-promotion-related-type"><?php esc_html_e('Relaterat innehåll', 'ssf-promotions'); ?></label>
                        <select id="ssf-promotion-related-type" name="ssf_promotion_related_type" data-related-type>
                            <option value=""><?php esc_html_e('Ingen koppling / egen länk', 'ssf-promotions'); ?></option>
                            <?php foreach ($providers as $key => $provider) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($data['related_type'], $key); ?>><?php echo esc_html($provider->label()); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php foreach ($providers as $key => $provider) : ?>
                        <div class="ssf-promotion-field" data-related-provider="<?php echo esc_attr($key); ?>">
                            <label for="ssf-related-<?php echo esc_attr($key); ?>"><?php echo esc_html($provider->label()); ?></label>
                            <select id="ssf-related-<?php echo esc_attr($key); ?>" name="ssf_promotion_related_ids[<?php echo esc_attr($key); ?>]">
                                <option value="0"><?php esc_html_e('Välj innehåll', 'ssf-promotions'); ?></option>
                                <?php foreach ($provider->options() as $id => $label) : ?><option value="<?php echo esc_attr((string) $id); ?>" <?php selected($data['related_type'] === $key ? $data['related_id'] : 0, $id); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                    <div class="ssf-promotion-field" data-annual-anchor>
                        <label for="ssf-promotion-anchor"><?php esc_html_e('Länka till del', 'ssf-promotions'); ?></label>
                        <select id="ssf-promotion-anchor" name="ssf_promotion_anchor">
                            <?php $annual = $this->relations->provider('annual_meeting'); ?>
                            <?php foreach ($annual ? $annual->anchors() : array() as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($data['anchor'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ssf-promotion-field" data-manual-url>
                        <label for="ssf-promotion-url"><?php esc_html_e('Egen länk', 'ssf-promotions'); ?></label>
                        <input id="ssf-promotion-url" name="ssf_promotion_url" type="url" value="<?php echo esc_attr($data['manual_url']); ?>" placeholder="https://">
                    </div>
                </div>
                <?php if ($data['relation_missing']) : ?><p class="ssf-promotion-warning"><?php esc_html_e('Relaterat innehåll saknas. Den egna länken används om den finns.', 'ssf-promotions'); ?></p><?php endif; ?>
            </fieldset>

            <div class="ssf-promotion-field">
                <label for="ssf-promotion-cta"><?php esc_html_e('Text på knapp', 'ssf-promotions'); ?></label>
                <input id="ssf-promotion-cta" name="ssf_promotion_cta_text" type="text" value="<?php echo esc_attr($data['cta_text']); ?>" list="ssf-promotion-cta-options" placeholder="<?php esc_attr_e('Läs mer', 'ssf-promotions'); ?>" data-preview-cta>
                <datalist id="ssf-promotion-cta-options"><option value="Läs mer"><option value="Anmäl dig"><option value="Till årsmötet"><option value="Lämna motion"><option value="Läs nyhetsbrev"><option value="Visa program"><option value="Ladda ner"></datalist>
            </div>

            <div class="ssf-promotion-field">
                <label for="ssf-promotion-layout"><?php esc_html_e('Utseende', 'ssf-promotions'); ?></label>
                <select id="ssf-promotion-layout" name="ssf_promotion_layout" data-preview-layout>
                    <option value="banner" <?php selected($data['layout'], 'banner'); ?>><?php esc_html_e('Banner', 'ssf-promotions'); ?></option>
                    <option value="card" <?php selected($data['layout'], 'card'); ?>><?php esc_html_e('Kort', 'ssf-promotions'); ?></option>
                </select>
            </div>
        </div>
        <?php
    }

    public function render_status(WP_Post $post): void
    {
        $data = $this->form_data($post);
        ?>
        <div class="ssf-promotion-sidebar">
            <p><strong><?php esc_html_e('Beräknad status', 'ssf-promotions'); ?></strong><br><span class="ssf-promotion-state ssf-promotion-state--<?php echo esc_attr($this->repository->status($post->ID)); ?>"><?php echo esc_html($this->repository->status_label($this->repository->status($post->ID))); ?></span></p>
            <p><label for="ssf-promotion-start"><strong><?php esc_html_e('Visas från', 'ssf-promotions'); ?></strong></label><br><input id="ssf-promotion-start" name="ssf_promotion_start" type="datetime-local" value="<?php echo esc_attr($this->format_datetime($data['start'])); ?>"></p>
            <p><label for="ssf-promotion-end"><strong><?php esc_html_e('Visas till', 'ssf-promotions'); ?></strong></label><br><input id="ssf-promotion-end" name="ssf_promotion_end" type="datetime-local" value="<?php echo esc_attr($this->format_datetime($data['end'])); ?>"></p>
            <p class="description"><?php esc_html_e('Tom start betyder direkt. Tomt slut betyder tills vidare.', 'ssf-promotions'); ?></p>
            <fieldset><legend><strong><?php esc_html_e('Placering', 'ssf-promotions'); ?></strong></legend>
                <?php foreach (array('home' => __('Startsida', 'ssf-promotions'), 'annual' => __('Årsmötessida', 'ssf-promotions'), 'all' => __('Alla sidor', 'ssf-promotions')) as $key => $label) : ?>
                    <label class="ssf-promotion-checkbox"><input type="checkbox" name="ssf_promotion_locations[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $data['locations'], true)); ?>> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
            </fieldset>
            <p><label class="ssf-promotion-checkbox"><input type="checkbox" name="ssf_promotion_show_countdown" value="1" <?php checked($data['show_countdown']); ?>> <?php esc_html_e('Visa återstående tid', 'ssf-promotions'); ?></label></p>
            <p><label class="ssf-promotion-checkbox"><input type="checkbox" name="ssf_promotion_archived" value="1" <?php checked($data['archived']); ?>> <?php esc_html_e('Arkivera budskapet', 'ssf-promotions'); ?></label></p>
            <?php if ($data['needs_review']) : ?><div class="notice notice-warning inline"><p><?php esc_html_e('Detta är en kopia. Kontrollera datum och relaterat innehåll innan publicering.', 'ssf-promotions'); ?></p></div><?php endif; ?>
        </div>
        <?php
    }

    public function render_preview(WP_Post $post): void
    {
        $data = $this->form_data($post);
        echo '<div class="ssf-promotion-admin-preview" data-promotion-preview>';
        echo $this->renderer->render_preview(array(
            'title' => $post->post_title,
            'text' => $post->post_excerpt,
            'type' => $data['type'],
            'priority' => $data['priority'],
            'layout' => $data['layout'],
            'cta_text' => $data['cta_text'],
        )); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }

    public function render_annual_meeting_box(WP_Post $post): void
    {
        $url = add_query_arg(array(
            'post_type' => SSF_Promotion_Repository::POST_TYPE,
            'ssf_related_type' => 'annual_meeting',
            'ssf_related_id' => $post->ID,
        ), admin_url('post-new.php'));
        ?>
        <p><?php esc_html_e('Skapa ett tidsstyrt budskap som länkar direkt till detta årsmöte.', 'ssf-promotions'); ?></p>
        <p><a class="button button-primary" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Skapa startsidesbudskap', 'ssf-promotions'); ?></a></p>
        <?php
    }

    public function save(int $post_id, WP_Post $post): void
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ! current_user_can(SSF_Promotions::CAPABILITY) || ! isset($_POST['ssf_promotion_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_promotion_nonce'])), 'ssf_save_promotion')) {
            return;
        }

        $types = array_keys($this->type_labels());
        $type = sanitize_key(wp_unslash($_POST['ssf_promotion_type'] ?? 'information'));
        $type = in_array($type, $types, true) ? $type : 'information';
        $priorities = array_keys($this->priority_labels());
        $priority = (int) ($_POST['ssf_promotion_priority'] ?? 50);
        $priority = in_array($priority, $priorities, true) ? $priority : 50;
        $related_type = sanitize_key(wp_unslash($_POST['ssf_promotion_related_type'] ?? ''));
        $provider = $this->relations->provider($related_type);
        $related_ids = isset($_POST['ssf_promotion_related_ids']) ? (array) wp_unslash($_POST['ssf_promotion_related_ids']) : array();
        $related_id = $provider ? absint($related_ids[$related_type] ?? 0) : 0;
        $anchor = $provider ? sanitize_key(wp_unslash($_POST['ssf_promotion_anchor'] ?? '')) : '';
        $start = $this->parse_datetime(sanitize_text_field(wp_unslash($_POST['ssf_promotion_start'] ?? '')));
        $end = $this->parse_datetime(sanitize_text_field(wp_unslash($_POST['ssf_promotion_end'] ?? '')));
        if ($start && $end && $end <= $start) {
            $end = 0;
            $this->date_error = true;
            set_transient('ssf_promotions_notice_' . get_current_user_id(), 'date', MINUTE_IN_SECONDS);
        }
        $locations = array_values(array_intersect(array('home', 'annual', 'all'), array_map('sanitize_key', (array) wp_unslash($_POST['ssf_promotion_locations'] ?? array()))));
        if (! $locations) {
            $locations = array('home');
        }

        $values = array(
            '_ssf_promotion_type' => $type,
            '_ssf_promotion_priority' => $priority,
            '_ssf_promotion_start' => $start,
            '_ssf_promotion_end' => $end,
            '_ssf_promotion_cta_text' => sanitize_text_field(wp_unslash($_POST['ssf_promotion_cta_text'] ?? '')),
            '_ssf_promotion_url' => esc_url_raw(wp_unslash($_POST['ssf_promotion_url'] ?? '')),
            '_ssf_promotion_related_type' => $related_type,
            '_ssf_promotion_related_id' => $related_id,
            '_ssf_promotion_anchor' => $anchor,
            '_ssf_promotion_layout' => in_array(($_POST['ssf_promotion_layout'] ?? ''), array('banner', 'card'), true) ? sanitize_key(wp_unslash($_POST['ssf_promotion_layout'])) : 'banner',
            '_ssf_promotion_locations' => $locations,
            '_ssf_promotion_show_countdown' => ! empty($_POST['ssf_promotion_show_countdown']) ? 1 : 0,
            '_ssf_promotion_archived' => ! empty($_POST['ssf_promotion_archived']) ? 1 : 0,
        );

        foreach ($values as $key => $value) {
            if (in_array($key, array('_ssf_promotion_start', '_ssf_promotion_end'), true) && ! $value) {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }
        delete_post_meta($post_id, '_ssf_promotion_needs_review');
    }

    public function columns(array $columns): array
    {
        return array(
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => __('Titel', 'ssf-promotions'),
            'ssf_promotion_type' => __('Typ', 'ssf-promotions'),
            'ssf_promotion_priority' => __('Prioritet', 'ssf-promotions'),
            'ssf_promotion_start' => __('Visas från', 'ssf-promotions'),
            'ssf_promotion_end' => __('Visas till', 'ssf-promotions'),
            'ssf_promotion_locations' => __('Placering', 'ssf-promotions'),
            'ssf_promotion_status' => __('Status', 'ssf-promotions'),
        );
    }

    public function column(string $column, int $post_id): void
    {
        $data = $this->repository->data($post_id);
        switch ($column) {
            case 'ssf_promotion_type':
                echo esc_html($this->type_labels()[$data['type']] ?? $data['type']);
                break;
            case 'ssf_promotion_priority':
                echo esc_html($this->priority_labels()[$data['priority']] ?? (string) $data['priority']);
                break;
            case 'ssf_promotion_start':
                echo esc_html($data['start'] ? wp_date('j M Y H:i', $data['start'], wp_timezone()) : __('Direkt', 'ssf-promotions'));
                break;
            case 'ssf_promotion_end':
                echo esc_html($data['end'] ? wp_date('j M Y H:i', $data['end'], wp_timezone()) : __('Tills vidare', 'ssf-promotions'));
                break;
            case 'ssf_promotion_locations':
                $labels = array('home' => __('Startsida', 'ssf-promotions'), 'annual' => __('Årsmötessida', 'ssf-promotions'), 'all' => __('Alla sidor', 'ssf-promotions'));
                echo esc_html(implode(', ', array_map(static function (string $location) use ($labels): string { return $labels[$location] ?? $location; }, $data['locations'])));
                break;
            case 'ssf_promotion_status':
                $status = $this->repository->status($post_id);
                echo '<span class="ssf-promotion-state ssf-promotion-state--' . esc_attr($status) . '">' . esc_html($this->repository->status_label($status)) . '</span>';
                break;
        }
    }

    public function row_actions(array $actions, WP_Post $post): array
    {
        if (SSF_Promotion_Repository::POST_TYPE !== $post->post_type || ! current_user_can(SSF_Promotions::CAPABILITY)) {
            return $actions;
        }
        $url = wp_nonce_url(add_query_arg(array('action' => 'ssf_duplicate_promotion', 'post' => $post->ID), admin_url('admin-post.php')), 'ssf_duplicate_promotion_' . $post->ID);
        $actions['ssf_duplicate'] = '<a href="' . esc_url($url) . '">' . esc_html__('Duplicera', 'ssf-promotions') . '</a>';
        return $actions;
    }

    public function duplicate(): void
    {
        $post_id = absint($_GET['post'] ?? 0);
        if (! $post_id || ! current_user_can(SSF_Promotions::CAPABILITY) || ! check_admin_referer('ssf_duplicate_promotion_' . $post_id)) {
            wp_die(esc_html__('Du saknar behörighet att duplicera budskapet.', 'ssf-promotions'));
        }
        $source = get_post($post_id);
        if (! $source || SSF_Promotion_Repository::POST_TYPE !== $source->post_type) {
            wp_die(esc_html__('Budskapet kunde inte hittas.', 'ssf-promotions'));
        }
        $new_id = wp_insert_post(array(
            'post_type' => SSF_Promotion_Repository::POST_TYPE,
            'post_status' => 'draft',
            'post_title' => sprintf(__('%s (kopia)', 'ssf-promotions'), $source->post_title),
            'post_excerpt' => $source->post_excerpt,
        ), true);
        if (is_wp_error($new_id)) {
            wp_die(esc_html($new_id->get_error_message()));
        }
        foreach ($this->meta_keys() as $key) {
            $value = get_post_meta($post_id, $key, true);
            if ('' !== $value) {
                update_post_meta($new_id, $key, $value);
            }
        }
        update_post_meta($new_id, '_ssf_promotion_needs_review', 1);
        $this->repository->invalidate_cache();
        wp_safe_redirect(admin_url('post.php?post=' . $new_id . '&action=edit&ssf_promotion_duplicated=1'));
        exit;
    }

    public function views(array $views): array
    {
        $current = sanitize_key(wp_unslash($_GET['ssf_promotion_state'] ?? ''));
        foreach (array('active' => __('Aktiva', 'ssf-promotions'), 'scheduled' => __('Schemalagda', 'ssf-promotions'), 'expired' => __('Utgångna', 'ssf-promotions'), 'archived' => __('Arkiverade', 'ssf-promotions')) as $state => $label) {
            $url = add_query_arg(array('post_type' => SSF_Promotion_Repository::POST_TYPE, 'ssf_promotion_state' => $state), admin_url('edit.php'));
            $views['ssf_' . $state] = '<a href="' . esc_url($url) . '"' . ($current === $state ? ' class="current" aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        return $views;
    }

    public function filter_list(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query() || SSF_Promotion_Repository::POST_TYPE !== $query->get('post_type')) {
            return;
        }
        $state = sanitize_key(wp_unslash($_GET['ssf_promotion_state'] ?? ''));
        if (! $state) {
            return;
        }
        $now = current_datetime()->getTimestamp();
        if ('archived' === $state) {
            $query->set('post_status', array('publish', 'draft'));
            $query->set('meta_query', array(array('key' => '_ssf_promotion_archived', 'value' => '1')));
        } elseif ('expired' === $state) {
            $query->set('post_status', 'publish');
            $query->set('meta_query', array(
                array('key' => '_ssf_promotion_end', 'value' => $now, 'compare' => '<', 'type' => 'NUMERIC'),
                array('relation' => 'OR', array('key' => '_ssf_promotion_archived', 'compare' => 'NOT EXISTS'), array('key' => '_ssf_promotion_archived', 'value' => '1', 'compare' => '!=')),
            ));
        } elseif ('scheduled' === $state) {
            $query->set('post_status', 'publish');
            $query->set('meta_query', array(
                array('key' => '_ssf_promotion_start', 'value' => $now, 'compare' => '>', 'type' => 'NUMERIC'),
                array('relation' => 'OR', array('key' => '_ssf_promotion_archived', 'compare' => 'NOT EXISTS'), array('key' => '_ssf_promotion_archived', 'value' => '1', 'compare' => '!=')),
            ));
        } elseif ('active' === $state) {
            $active_ids = array_values(array_unique(array_merge($this->repository->active_ids('home'), $this->repository->active_ids('annual'))));
            $query->set('post__in', $active_ids ?: array(0));
            $query->set('orderby', 'post__in');
        }
    }

    public function active_summary(): void
    {
        $screen = get_current_screen();
        if (! $screen || 'edit-' . SSF_Promotion_Repository::POST_TYPE !== $screen->id || ! current_user_can(SSF_Promotions::CAPABILITY)) {
            return;
        }
        $active = $this->repository->active(array('location' => 'home', 'max' => 3));
        ?>
        <div class="ssf-promotion-summary">
            <div><strong><?php esc_html_e('Aktiva just nu', 'ssf-promotions'); ?></strong><span><?php echo esc_html(sprintf(_n('%d budskap på startsidan', '%d budskap på startsidan', count($active), 'ssf-promotions'), count($active))); ?></span></div>
            <?php if ($active) : ?><ul><?php foreach ($active as $post) : ?><li><a href="<?php echo esc_url(get_edit_post_link($post)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></li><?php endforeach; ?></ul><?php else : ?><p><?php esc_html_e('Inget aktivt budskap visas på startsidan.', 'ssf-promotions'); ?></p><?php endif; ?>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . SSF_Promotion_Repository::POST_TYPE)); ?>"><?php esc_html_e('Lägg till budskap', 'ssf-promotions'); ?></a>
        </div>
        <?php
    }

    public function notices(): void
    {
        if ('date' === get_transient('ssf_promotions_notice_' . get_current_user_id())) {
            delete_transient('ssf_promotions_notice_' . get_current_user_id());
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Sluttiden var före starttiden och sparades därför inte. Kontrollera visningsperioden.', 'ssf-promotions') . '</p></div>';
        }
        if (! empty($_GET['ssf_promotion_duplicated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Budskapet duplicerades som utkast. Kontrollera datum och relaterat innehåll.', 'ssf-promotions') . '</p></div>';
        }
    }

    public function render_overview_card(): void
    {
        if (! current_user_can(SSF_Promotions::CAPABILITY)) {
            return;
        }
        $active = $this->repository->active(array('location' => 'home', 'max' => 3));
        ?>
        <section class="ssf-admin-card"><h2><?php esc_html_e('Aktuellt', 'ssf-promotions'); ?></h2><p><?php echo esc_html(sprintf(_n('%d aktivt budskap', '%d aktiva budskap', count($active), 'ssf-promotions'), count($active))); ?></p>
            <?php if ($active) : ?><ul><?php foreach ($active as $post) : ?><li><?php echo esc_html(get_the_title($post)); ?></li><?php endforeach; ?></ul><?php endif; ?>
            <p><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . SSF_Promotion_Repository::POST_TYPE)); ?>"><?php esc_html_e('Hantera', 'ssf-promotions'); ?></a></p></section>
        <?php
    }

    public function render_content_card(): void
    {
        if (! current_user_can(SSF_Promotions::CAPABILITY)) {
            return;
        }
        $counts = wp_count_posts(SSF_Promotion_Repository::POST_TYPE);
        $total = $counts ? array_sum((array) $counts) : 0;
        ?>
        <section class="ssf-admin-card"><h2><?php esc_html_e('Aktuellt', 'ssf-promotions'); ?></h2><p><?php echo esc_html(sprintf(_n('%d sparat budskap', '%d sparade budskap', $total, 'ssf-promotions'), $total)); ?></p><p><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . SSF_Promotion_Repository::POST_TYPE)); ?>"><?php esc_html_e('Öppna', 'ssf-promotions'); ?></a></p></section>
        <?php
    }

    public function enqueue_assets(string $hook): void
    {
        $screen = get_current_screen();
        if (! $screen || ! in_array($screen->post_type, array(SSF_Promotion_Repository::POST_TYPE, 'ssf_annual_meeting'), true)) {
            return;
        }
        wp_enqueue_style('ssf-promotions-admin', SSF_PROMOTIONS_URL . 'assets/css/admin.css', array(), SSF_PROMOTIONS_VERSION);
        wp_enqueue_style('ssf-promotions-preview', SSF_PROMOTIONS_URL . 'assets/css/promotions.css', array(), SSF_PROMOTIONS_VERSION);
        if (SSF_Promotion_Repository::POST_TYPE === $screen->post_type && in_array($hook, array('post.php', 'post-new.php'), true)) {
            wp_enqueue_script('ssf-promotions-admin', SSF_PROMOTIONS_URL . 'assets/js/admin.js', array(), SSF_PROMOTIONS_VERSION, true);
        }
    }

    public function disable_block_editor(bool $use_block_editor, string $post_type): bool
    {
        return SSF_Promotion_Repository::POST_TYPE === $post_type ? false : $use_block_editor;
    }

    public function title_placeholder(string $placeholder, WP_Post $post): string
    {
        return SSF_Promotion_Repository::POST_TYPE === $post->post_type ? __('Rubrik, till exempel ”Anmälan till middagen är öppen”', 'ssf-promotions') : $placeholder;
    }

    public function default_title(string $title, WP_Post $post): string
    {
        $meeting = $this->prefill_meeting($post);
        return $meeting ? sprintf(__('Anmälan till %s är öppen', 'ssf-promotions'), get_the_title($meeting)) : $title;
    }

    public function default_excerpt(string $excerpt, WP_Post $post): string
    {
        return $this->prefill_meeting($post) ? __('Läs kallelsen och anmäl dig till middagen och aktiviteter under dag 2.', 'ssf-promotions') : $excerpt;
    }

    private function form_data(WP_Post $post): array
    {
        $data = $this->repository->data($post->ID);
        $meeting = $this->prefill_meeting($post);
        if ($meeting && ! $data['related_id']) {
            $relation = $this->relations->resolve('annual_meeting', $meeting->ID, 'dinner');
            $data['type'] = 'annual_meeting';
            $data['priority'] = 80;
            $data['related_type'] = 'annual_meeting';
            $data['related_id'] = $meeting->ID;
            $data['anchor'] = 'dinner';
            $data['cta_text'] = __('Till årsmötet', 'ssf-promotions');
            $data['start'] = (int) ($relation['start'] ?? 0);
            $data['end'] = (int) ($relation['end'] ?? 0);
        }
        return $data;
    }

    private function prefill_meeting(WP_Post $post): ?WP_Post
    {
        if (SSF_Promotion_Repository::POST_TYPE !== $post->post_type || 'annual_meeting' !== sanitize_key(wp_unslash($_GET['ssf_related_type'] ?? ''))) {
            return null;
        }
        $meeting = get_post(absint($_GET['ssf_related_id'] ?? 0));
        return $meeting && 'ssf_annual_meeting' === $meeting->post_type ? $meeting : null;
    }

    private function type_labels(): array
    {
        return array(
            'annual_meeting' => __('Årsmöte', 'ssf-promotions'),
            'motions' => __('Motioner', 'ssf-promotions'),
            'newsletter' => __('Nyhetsbrev', 'ssf-promotions'),
            'event' => __('Evenemang', 'ssf-promotions'),
            'membership' => __('Medlemsinformation', 'ssf-promotions'),
            'security' => __('Säkerhetsinformation', 'ssf-promotions'),
            'campaign' => __('Kampanj', 'ssf-promotions'),
            'information' => __('Information', 'ssf-promotions'),
        );
    }

    private function priority_labels(): array
    {
        return array(10 => __('Låg', 'ssf-promotions'), 50 => __('Normal', 'ssf-promotions'), 80 => __('Hög', 'ssf-promotions'), 100 => __('Kritisk', 'ssf-promotions'));
    }

    private function format_datetime(int $timestamp): string
    {
        return $timestamp ? wp_date('Y-m-d\TH:i', $timestamp, wp_timezone()) : '';
    }

    private function parse_datetime(string $value): int
    {
        if (! $value) {
            return 0;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date instanceof DateTimeImmutable || (is_array($errors) && (! empty($errors['warning_count']) || ! empty($errors['error_count']))) || $date->format('Y-m-d\TH:i') !== $value) {
            return 0;
        }
        return $date->getTimestamp();
    }

    private function meta_keys(): array
    {
        return array(
            '_ssf_promotion_type', '_ssf_promotion_priority', '_ssf_promotion_start', '_ssf_promotion_end', '_ssf_promotion_cta_text', '_ssf_promotion_url',
            '_ssf_promotion_related_type', '_ssf_promotion_related_id', '_ssf_promotion_anchor', '_ssf_promotion_layout', '_ssf_promotion_locations',
            '_ssf_promotion_show_countdown', '_ssf_promotion_archived',
        );
    }
}
