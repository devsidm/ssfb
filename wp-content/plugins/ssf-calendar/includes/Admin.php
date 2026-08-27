<?php

namespace SSF\Calendar;

if (! defined('ABSPATH')) {
    exit;
}

final class Admin
{
    private EventRepository $events;

    public function __construct(EventRepository $events)
    {
        $this->events = $events;
        add_action('add_meta_boxes_' . EventPostType::POST_TYPE, array($this, 'add_meta_box'));
        add_action('save_post_' . EventPostType::POST_TYPE, array($this, 'save'), 10, 2);
        add_filter('wp_insert_post_data', array($this, 'validate_publish'), 10, 2);
        add_filter('manage_' . EventPostType::POST_TYPE . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . EventPostType::POST_TYPE . '_posts_custom_column', array($this, 'column'), 10, 2);
        add_action('admin_notices', array($this, 'notice'));
        add_action('admin_menu', array($this, 'menu'));
    }

    public function add_meta_box(): void
    {
        add_meta_box('ssf-calendar-event-details', __('Eventuppgifter', 'ssf-calendar'), array($this, 'render_meta_box'), EventPostType::POST_TYPE, 'normal', 'high');
    }

    public function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('ssf_calendar_event', 'ssf_calendar_event_nonce');
        $data = $this->data($post->ID);
        ?>
        <p><label><strong><?php esc_html_e('Startdatum', 'ssf-calendar'); ?> *</strong><br><input type="date" name="ssf_calendar_start_date" value="<?php echo esc_attr($data['start_date']); ?>" required></label></p>
        <p><label><strong><?php esc_html_e('Starttid', 'ssf-calendar'); ?></strong><br><input type="time" name="ssf_calendar_start_time" value="<?php echo esc_attr($data['start_time']); ?>"></label></p>
        <p><label><strong><?php esc_html_e('Slutdatum', 'ssf-calendar'); ?></strong><br><input type="date" name="ssf_calendar_end_date" value="<?php echo esc_attr($data['end_date']); ?>"></label></p>
        <p><label><strong><?php esc_html_e('Sluttid', 'ssf-calendar'); ?></strong><br><input type="time" name="ssf_calendar_end_time" value="<?php echo esc_attr($data['end_time']); ?>"></label></p>
        <p><label><strong><?php esc_html_e('Plats', 'ssf-calendar'); ?></strong><br><input class="widefat" name="ssf_calendar_location" value="<?php echo esc_attr($data['location']); ?>"></label></p>
        <p><label><strong><?php esc_html_e('Eventlänk', 'ssf-calendar'); ?></strong><br><input class="widefat" type="url" name="ssf_calendar_event_url" value="<?php echo esc_attr($data['event_url']); ?>" placeholder="https://"><br><span class="description"><?php esc_html_e('Länken visas på eventsidan. Kalenderkortet leder alltid till den egna eventsidan.', 'ssf-calendar'); ?></span></label></p>
        <p><label><strong><?php esc_html_e('Typ', 'ssf-calendar'); ?></strong><br><select name="ssf_calendar_event_type"><?php foreach ($this->types() as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($data['event_type'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></p>
        <p class="description"><?php esc_html_e('Använd den vanliga WordPress-redigeraren för beskrivningen och Utvald bild för eventbilden.', 'ssf-calendar'); ?></p>
        <?php
    }

    public function save(int $post_id, \WP_Post $post): void
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ! current_user_can('edit_post', $post_id) || ! isset($_POST['ssf_calendar_event_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_calendar_event_nonce'])), 'ssf_calendar_event')) {
            return;
        }
        $start_date = $this->date(sanitize_text_field(wp_unslash($_POST['ssf_calendar_start_date'] ?? '')));
        $end_date = $this->date(sanitize_text_field(wp_unslash($_POST['ssf_calendar_end_date'] ?? '')));
        if ($end_date && $start_date && $end_date < $start_date) {
            $end_date = $start_date;
        }
        $type = sanitize_key(wp_unslash($_POST['ssf_calendar_event_type'] ?? 'event'));
        if (! isset($this->types()[$type])) {
            $type = 'event';
        }
        $values = array(
            'start_date' => $start_date,
            'start_time' => $this->time(sanitize_text_field(wp_unslash($_POST['ssf_calendar_start_time'] ?? ''))),
            'end_date' => $end_date,
            'end_time' => $this->time(sanitize_text_field(wp_unslash($_POST['ssf_calendar_end_time'] ?? ''))),
            'location' => sanitize_text_field(wp_unslash($_POST['ssf_calendar_location'] ?? '')),
            'event_url' => esc_url_raw(wp_unslash($_POST['ssf_calendar_event_url'] ?? '')),
            'event_type' => $type,
        );
        foreach ($values as $key => $value) {
            update_post_meta($post_id, '_ssf_calendar_' . $key, $value);
        }
        update_post_meta($post_id, '_ssf_calendar_event_source', 'manual');
        update_post_meta($post_id, '_ssf_calendar_event_source_id', '');
        do_action('ssf_calendar_after_event_save', $post_id, $values, $post);
    }

    public function validate_publish(array $data, array $postarr): array
    {
        if (EventPostType::POST_TYPE !== ($data['post_type'] ?? '') || 'publish' !== ($data['post_status'] ?? '') || ! is_admin() || ! isset($_POST['ssf_calendar_event_nonce'])) {
            return $data;
        }
        $date = $this->date(sanitize_text_field(wp_unslash($_POST['ssf_calendar_start_date'] ?? '')));
        $content = trim(wp_strip_all_tags((string) ($data['post_content'] ?? '')));
        if (! $date || ! trim((string) ($data['post_title'] ?? '')) || ! $content) {
            $data['post_status'] = 'draft';
            add_filter('redirect_post_location', static function (string $location): string {
                return add_query_arg('ssf_calendar_event_error', 'required', $location);
            });
        }
        return $data;
    }

    public function columns(array $columns): array
    {
        return array(
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => __('Titel', 'ssf-calendar'),
            'ssf_calendar_date' => __('Datum', 'ssf-calendar'),
            'ssf_calendar_location' => __('Plats', 'ssf-calendar'),
            'ssf_calendar_type' => __('Typ', 'ssf-calendar'),
            'ssf_calendar_source' => __('Källa', 'ssf-calendar'),
            'ssf_calendar_status' => __('Status', 'ssf-calendar'),
            'date' => __('Senast ändrad', 'ssf-calendar'),
        );
    }

    public function column(string $column, int $post_id): void
    {
        $data = $this->data($post_id);
        if ('ssf_calendar_date' === $column) {
            echo esc_html($data['start_date'] ? $this->events->date_label(array('start_date' => $data['start_date'], 'end_date' => $data['end_date'])) : '—');
        } elseif ('ssf_calendar_location' === $column) {
            echo esc_html($data['location'] ?: '—');
        } elseif ('ssf_calendar_type' === $column) {
            echo esc_html($this->types()[$data['event_type']] ?? $data['event_type']);
        } elseif ('ssf_calendar_source' === $column) {
            esc_html_e('Manuellt event', 'ssf-calendar');
        } elseif ('ssf_calendar_status' === $column) {
            $status = get_post_status($post_id);
            $labels = array('publish' => __('Publicerad', 'ssf-calendar'), 'draft' => __('Utkast', 'ssf-calendar'), 'private' => __('Privat', 'ssf-calendar'));
            echo esc_html($labels[$status] ?? $status);
        }
    }

    public function menu(): void
    {
        add_menu_page(__('Kalender', 'ssf-calendar'), __('Kalender', 'ssf-calendar'), 'edit_posts', 'ssf-calendar-events', array($this, 'overview'), 'dashicons-calendar-alt', 26);
        add_submenu_page('ssf-calendar-events', __('Alla event', 'ssf-calendar'), __('Alla event', 'ssf-calendar'), 'edit_posts', 'ssf-calendar-events', array($this, 'overview'));
        add_submenu_page('ssf-calendar-events', __('Lägg till event', 'ssf-calendar'), __('Lägg till event', 'ssf-calendar'), 'edit_posts', 'post-new.php?post_type=' . EventPostType::POST_TYPE);
        add_submenu_page('ssf-calendar-events', __('Kalenderinställningar', 'ssf-calendar'), __('Inställningar', 'ssf-calendar'), 'edit_posts', 'ssf-calendar-settings', array($this, 'settings_page'));
    }

    public function overview(): void
    {
        if (! current_user_can('edit_posts')) {
            return;
        }
        $manual_events = get_posts(array('post_type' => EventPostType::POST_TYPE, 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => -1, 'meta_key' => '_ssf_calendar_start_date', 'orderby' => 'meta_value', 'order' => 'ASC'));
        $annual_meetings = post_type_exists('ssf_annual_meeting') ? get_posts(array('post_type' => 'ssf_annual_meeting', 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => -1, 'meta_key' => '_ssf_am_start_at', 'orderby' => 'meta_value_num', 'order' => 'ASC')) : array();
        ?>
        <div class="wrap"><h1 class="wp-heading-inline"><?php esc_html_e('Kalender', 'ssf-calendar'); ?></h1> <a class="page-title-action" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . EventPostType::POST_TYPE)); ?>"><?php esc_html_e('Lägg till event', 'ssf-calendar'); ?></a><hr class="wp-header-end"><p><?php esc_html_e('Manuella event administreras här. Årsmötet läses direkt från Årsmötesmodulen och kan därför inte bli en dubblett.', 'ssf-calendar'); ?></p>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Titel', 'ssf-calendar'); ?></th><th><?php esc_html_e('Datum', 'ssf-calendar'); ?></th><th><?php esc_html_e('Plats', 'ssf-calendar'); ?></th><th><?php esc_html_e('Typ', 'ssf-calendar'); ?></th><th><?php esc_html_e('Status', 'ssf-calendar'); ?></th><th><?php esc_html_e('Källa', 'ssf-calendar'); ?></th></tr></thead><tbody>
        <?php foreach ($manual_events as $event_post) : $event = $this->events->manual_event($event_post); ?><tr><td><a href="<?php echo esc_url(get_edit_post_link($event_post)); ?>"><?php echo esc_html($event['title']); ?></a></td><td><?php echo esc_html($this->events->date_label($event)); ?></td><td><?php echo esc_html($event['location'] ?: '—'); ?></td><td><?php echo esc_html($this->types()[$event['type']] ?? $event['type']); ?></td><td><?php echo esc_html($this->status_label($event_post->post_status)); ?></td><td><?php esc_html_e('Manuellt event', 'ssf-calendar'); ?></td></tr><?php endforeach; ?>
        <?php foreach ($annual_meetings as $annual) : $start = (int) get_post_meta($annual->ID, '_ssf_am_start_at', true); $end = (int) get_post_meta($annual->ID, '_ssf_am_end_at', true); $annual_event = array('start_date' => $start ? wp_date('Y-m-d', $start, wp_timezone()) : '', 'end_date' => $end ? wp_date('Y-m-d', $end, wp_timezone()) : ''); ?><tr><td><a href="<?php echo esc_url(get_edit_post_link($annual)); ?>"><?php echo esc_html(get_the_title($annual)); ?></a></td><td><?php echo esc_html($annual_event['start_date'] ? $this->events->date_label($annual_event) : '—'); ?></td><td><?php echo esc_html((string) get_post_meta($annual->ID, '_ssf_am_location', true) ?: '—'); ?></td><td><?php esc_html_e('Årsmöte', 'ssf-calendar'); ?></td><td><?php echo esc_html($this->status_label($annual->post_status)); ?></td><td><?php esc_html_e('Årsmötesmodulen', 'ssf-calendar'); ?></td></tr><?php endforeach; ?>
        <?php if (! $manual_events && ! $annual_meetings) : ?><tr><td colspan="6"><?php esc_html_e('Inga event finns ännu.', 'ssf-calendar'); ?></td></tr><?php endif; ?>
        </tbody></table></div>
        <?php
    }

    public function settings_page(): void
    {
        if (! current_user_can('edit_posts')) {
            return;
        }
        $page_id = (int) get_option('ssf_calendar_page_id', 0);
        ?>
        <div class="wrap"><h1><?php esc_html_e('Kalender', 'ssf-calendar'); ?></h1><p><?php esc_html_e('Skapa manuella event under Kalender. Den publika kalendern uppdateras automatiskt.', 'ssf-calendar'); ?></p><table class="widefat striped" style="max-width:760px"><tbody><tr><th><?php esc_html_e('Publik sida', 'ssf-calendar'); ?></th><td><?php if ($page_id) : ?><a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html(get_permalink($page_id)); ?></a><?php else : ?><?php esc_html_e('Sidan skapas när pluginet aktiveras.', 'ssf-calendar'); ?><?php endif; ?></td></tr><tr><th><?php esc_html_e('Årsmöten', 'ssf-calendar'); ?></th><td><?php esc_html_e('Publicerade årsmöten visas automatiskt i kalendern. Redigera alltid datum och information i Årsmöten, inte i Kalender.', 'ssf-calendar'); ?></td></tr></tbody></table></div>
        <?php
    }

    public function notice(): void
    {
        if (! isset($_GET['ssf_calendar_event_error']) || 'required' !== sanitize_key(wp_unslash($_GET['ssf_calendar_event_error']))) {
            return;
        }
        ?><div class="notice notice-error"><p><?php esc_html_e('Eventet sparades som utkast. Rubrik, startdatum och beskrivning krävs för publicering.', 'ssf-calendar'); ?></p></div><?php
    }

    private function data(int $post_id): array
    {
        return array(
            'start_date' => (string) get_post_meta($post_id, '_ssf_calendar_start_date', true),
            'start_time' => (string) get_post_meta($post_id, '_ssf_calendar_start_time', true),
            'end_date' => (string) get_post_meta($post_id, '_ssf_calendar_end_date', true),
            'end_time' => (string) get_post_meta($post_id, '_ssf_calendar_end_time', true),
            'location' => (string) get_post_meta($post_id, '_ssf_calendar_location', true),
            'event_url' => (string) get_post_meta($post_id, '_ssf_calendar_event_url', true),
            'event_type' => (string) get_post_meta($post_id, '_ssf_calendar_event_type', true) ?: 'event',
        );
    }

    private function types(): array
    {
        return array('event' => __('Event', 'ssf-calendar'), 'seminar' => __('Seminarium', 'ssf-calendar'), 'meeting' => __('Möte', 'ssf-calendar'), 'other' => __('Övrigt', 'ssf-calendar'));
    }

    private function status_label(string $status): string
    {
        $labels = array('publish' => __('Publicerad', 'ssf-calendar'), 'draft' => __('Utkast', 'ssf-calendar'), 'private' => __('Privat', 'ssf-calendar'));
        return $labels[$status] ?? $status;
    }

    private function date(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function time(string $value): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $value) ? $value : '';
    }
}
