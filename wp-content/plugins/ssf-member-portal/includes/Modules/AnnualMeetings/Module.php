<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

use SSF\MemberPortal\Integrations\Microsoft365\Authentication;
use SSF\MemberPortal\Integrations\Microsoft365\GraphClient;
use SSF\MemberPortal\Integrations\Microsoft365\SharePoint;

if (! defined('ABSPATH')) {
    exit;
}

final class Module
{
    public const POST_TYPE = 'ssf_annual_meeting';

    private RegistrationPostType $registrations;
    private RegistrationService $registration_service;
    private CalendarService $calendar;
    private Admin $admin;
    private Editor $editor;

    public function __construct()
    {
        $this->registrations = new RegistrationPostType();
        $this->calendar = new CalendarService($this);
        $this->registration_service = new RegistrationService($this, $this->calendar, new RegistrationMailer(), new RegistrationExport(), new SharePoint(new GraphClient(new Authentication())));
        $this->admin = new Admin($this, $this->registration_service);
        $this->editor = new Editor($this, $this->registration_service);
        new Frontend($this, $this->registration_service, $this->calendar);

        add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'add_meta_box'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'admin_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'admin_column'), 10, 2);
    }

    public function register(): void
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Årsmöten', 'ssf-member-portal'),
                'singular_name' => __('Årsmöte', 'ssf-member-portal'),
                'add_new_item' => __('Lägg till årsmöte', 'ssf-member-portal'),
                'edit_item' => __('Redigera årsmöte', 'ssf-member-portal'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title', 'editor', 'thumbnail', 'revisions'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));

        $this->registrations->register();
        if (! wp_next_scheduled('ssf_member_portal_annual_meeting_retention')) {
            wp_schedule_event(current_datetime()->getTimestamp() + HOUR_IN_SECONDS, 'daily', 'ssf_member_portal_annual_meeting_retention');
        }
    }

    public static function install_pages(): void
    {
        $meeting = get_page_by_path('arsmote');
        $meeting_id = $meeting ? (int) $meeting->ID : (int) wp_insert_post(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'arsmote',
            'post_title' => 'Årsmöte',
            'post_content' => '[ssf_member_portal_annual_meeting]',
        ));
        update_option('ssf_member_portal_annual_meeting_page_id', $meeting_id, false);

        $registration = get_page_by_path('arsmote/anmalan');
        $registration_id = $registration ? (int) $registration->ID : (int) wp_insert_post(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $meeting_id,
            'post_name' => 'anmalan',
            'post_title' => 'Anmälan till middag och aktiviteter',
            'post_content' => '[ssf_member_portal_annual_meeting_registration]',
        ));
        update_option('ssf_member_portal_annual_meeting_registration_page_id', $registration_id, false);

        $archive = get_page_by_path('arsmoten');
        $archive_id = $archive ? (int) $archive->ID : (int) wp_insert_post(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'arsmoten',
            'post_title' => 'Årsmöten',
            'post_content' => '[ssf_member_portal_annual_meetings]',
        ));
        update_option('ssf_member_portal_annual_meetings_archive_page_id', $archive_id, false);
    }

    public function register_admin_menu(string $parent): void
    {
        $this->admin->register_menu($parent);
    }

    public function render_dashboard(): void
    {
        $this->admin->overview();
    }

    public function add_meta_box(): void
    {
        add_meta_box('ssf-member-portal-meeting', __('Redigera årsmötet', 'ssf-member-portal'), array($this, 'render_meta_box'), self::POST_TYPE, 'normal', 'high');
        add_meta_box('ssf-member-portal-meeting-status', __('Årsmötesöversikt', 'ssf-member-portal'), array($this, 'render_status_meta_box'), self::POST_TYPE, 'side', 'high');
    }

    public function enqueue_admin_assets(string $hook): void
    {
        $screen = get_current_screen();
        if (! $screen || self::POST_TYPE !== $screen->post_type || ! in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        wp_enqueue_script('ssf-member-portal-annual-meeting-admin', SSF_MEMBER_PORTAL_URL . 'assets/js/annual-meetings-admin.js', array(), SSF_MEMBER_PORTAL_VERSION, true);
        wp_enqueue_style('ssf-member-portal-annual-meeting-admin', SSF_MEMBER_PORTAL_URL . 'assets/css/annual-meetings-admin.css', array(), SSF_MEMBER_PORTAL_VERSION);
        wp_enqueue_media();
    }

    public function render_meta_box(\WP_Post $post): void
    {
        $this->editor->render($post);
    }

    public function render_status_meta_box(\WP_Post $post): void
    {
        $meeting = $this->data($post->ID);
        $registrations = count(get_posts(array('post_type' => RegistrationPostType::POST_TYPE, 'post_status' => 'private', 'post_parent' => $post->ID, 'fields' => 'ids', 'posts_per_page' => -1)));
        $motions = post_type_exists('ssf_motion') ? count(get_posts(array('post_type' => 'ssf_motion', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_ssf_mp_annual_meeting_id', 'meta_value' => $post->ID))) : 0;
        $registration_state = $this->registration_service->registration_state($meeting, $post);
        $warnings = array();
        if (! $meeting['start_at']) {
            $warnings[] = __('Datum saknas.', 'ssf-member-portal');
        }
        if (! $meeting['location']) {
            $warnings[] = __('Plats saknas.', 'ssf-member-portal');
        }
        if ($meeting['registration_open'] && ! $meeting['registration_closes_at']) {
            $warnings[] = __('Anmälan saknar deadline.', 'ssf-member-portal');
        }
        if (! $meeting['meeting_start_at']) {
            $warnings[] = __('Själva årsmötets starttid saknas.', 'ssf-member-portal');
        }
        if ($this->module_enabled($meeting, 'invitation') && empty($meeting['invitation']['text']) && empty($meeting['invitation']['pdf_id'])) {
            $warnings[] = __('Kallelsen är aktiv men saknar innehåll.', 'ssf-member-portal');
        }
        if ($this->module_enabled($meeting, 'dinner') && empty($meeting['dinner']['start_at'])) {
            $warnings[] = __('Middagen är aktiv men starttid saknas.', 'ssf-member-portal');
        }
        if ($this->module_enabled($meeting, 'dinner') && empty($meeting['dinner']['deadline'])) {
            $warnings[] = __('Middagen är aktiv men deadline saknas.', 'ssf-member-portal');
        }
        if ($this->module_enabled($meeting, 'day2')) {
            foreach ((array) $meeting['program'] as $item) {
                if (empty($item['date']) || empty($item['start']) || empty($item['end'])) {
                    $warnings[] = __('En programpunkt dag 2 saknar giltigt datum eller tid.', 'ssf-member-portal');
                    break;
                }
            }
        }
        if (! $meeting['motion_opens_at'] || ! $meeting['motion_closes_at']) {
            $warnings[] = __('Motionsperioden är inte komplett.', 'ssf-member-portal');
        }
        if (! has_post_thumbnail($post)) {
            $warnings[] = __('Kalenderbild saknas.', 'ssf-member-portal');
        }
        ?>
        <p><strong><?php echo esc_html($post->post_status === 'publish' ? __('Publicerad', 'ssf-member-portal') : __('Utkast', 'ssf-member-portal')); ?></strong></p>
        <p><strong><?php esc_html_e('Anmälan', 'ssf-member-portal'); ?></strong><br><?php echo esc_html($registration_state['label']); ?><br><span class="description"><?php echo esc_html($registration_state['message']); ?></span></p>
        <?php if (! empty($registration_state['choices'])) : ?><ul><?php foreach ($registration_state['choices'] as $choice) : $state = $registration_state['choice_states'][$choice['key']] ?? array(); ?><li><?php echo esc_html($choice['title'] . ': ' . ($state['label'] ?? '') . ' · ' . (int) ($state['count'] ?? 0) . (! empty($state['capacity']) ? ' / ' . (int) $state['capacity'] : '')); ?></li><?php endforeach; ?></ul><?php endif; ?>
        <p><?php esc_html_e('Anmälningar', 'ssf-member-portal'); ?><br><strong><?php echo esc_html((string) $registrations); ?></strong></p>
        <p><?php esc_html_e('Motioner', 'ssf-member-portal'); ?><br><strong><?php echo esc_html((string) $motions); ?></strong></p>
        <p><?php esc_html_e('Kalender', 'ssf-member-portal'); ?><br><?php echo esc_html($post->post_status === 'publish' && $meeting['start_at'] ? __('Publiceras automatiskt', 'ssf-member-portal') : __('Väntar på publicering', 'ssf-member-portal')); ?></p>
        <p><?php esc_html_e('SharePoint', 'ssf-member-portal'); ?><br><?php echo esc_html(get_post_meta($post->ID, '_ssf_am_sharepoint_excel_synced_at', true) ? __('Synkroniserad', 'ssf-member-portal') : __('Inte synkroniserad ännu', 'ssf-member-portal')); ?></p>
        <?php if ($warnings) : ?><div class="notice notice-warning inline"><p><strong><?php esc_html_e('Att kontrollera', 'ssf-member-portal'); ?></strong></p><ul><?php foreach ($warnings as $warning) : ?><li><?php echo esc_html($warning); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <p><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . RegistrationPostType::POST_TYPE . '&ssf_am_meeting=' . $post->ID)); ?>"><?php esc_html_e('Visa anmälningar', 'ssf-member-portal'); ?></a></p>
        <p><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=ssf_motion&ssf_am_meeting=' . $post->ID)); ?>"><?php esc_html_e('Visa motioner', 'ssf-member-portal'); ?></a></p>
        <?php
    }

    public function admin_columns(array $columns): array
    {
        return array(
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => __('Årsmöte', 'ssf-member-portal'),
            'ssf_am_date' => __('Datum', 'ssf-member-portal'),
            'ssf_am_location' => __('Plats', 'ssf-member-portal'),
            'ssf_am_dinner' => __('Middag', 'ssf-member-portal'),
            'ssf_am_day2' => __('Dag 2', 'ssf-member-portal'),
            'ssf_am_registrations' => __('Anmälningar', 'ssf-member-portal'),
            'ssf_am_motions' => __('Motioner', 'ssf-member-portal'),
            'date' => __('Status och publicering', 'ssf-member-portal'),
        );
    }

    public function admin_column(string $column, int $post_id): void
    {
        $meeting = $this->data($post_id);
        $counts = $this->registration_service->selection_counts($post_id);
        switch ($column) {
            case 'ssf_am_date':
                echo esc_html($meeting['start_at'] ? wp_date('j M Y', (int) $meeting['start_at'], wp_timezone()) : __('Saknas', 'ssf-member-portal'));
                break;
            case 'ssf_am_location':
                echo esc_html($meeting['location'] ?: __('Saknas', 'ssf-member-portal'));
                break;
            case 'ssf_am_dinner':
                echo $this->module_enabled($meeting, 'dinner') ? esc_html(sprintf(__('%d anmälda', 'ssf-member-portal'), (int) ($counts['dinner'] ?? 0))) : esc_html__('Av', 'ssf-member-portal');
                break;
            case 'ssf_am_day2':
                $activities = array_filter((array) $meeting['program'], static function (array $item): bool { return ! empty($item['requires_registration']); });
                echo $this->module_enabled($meeting, 'day2') ? esc_html(sprintf(__('%1$d programpunkter, %2$d aktiviteter', 'ssf-member-portal'), count($meeting['program']), count($activities))) : esc_html__('Av', 'ssf-member-portal');
                break;
            case 'ssf_am_registrations':
                echo esc_html((string) count($this->registration_service->registrations($post_id, array('status' => RegistrationService::REGISTERED))));
                break;
            case 'ssf_am_motions':
                $motion_count = post_type_exists('ssf_motion') ? count(get_posts(array('post_type' => 'ssf_motion', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_ssf_mp_annual_meeting_id', 'meta_value' => $post_id))) : 0;
                echo esc_html((string) $motion_count);
                break;
        }
    }

    public function save(int $post_id, \WP_Post $post): void
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ! current_user_can('edit_post', $post_id) || ! isset($_POST['ssf_member_portal_meeting_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_member_portal_meeting_nonce'])), 'ssf_member_portal_meeting')) {
            return;
        }

        $year = min(2100, max(2000, absint($_POST['ssf_meeting_year'] ?? 0)));
        $start_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_meeting_start_at'] ?? '')));
        $end_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_meeting_end_at'] ?? '')));
        if ($start_at && $end_at && $end_at <= $start_at) {
            $end_at = 0;
        }
        $legacy_meeting_date = (string) get_post_meta($post_id, '_ssf_mp_meeting_date', true);
        $opens_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_motion_opens_at'] ?? '')));
        $closes_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_motion_closes_at'] ?? '')));
        $registration_opens_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_meeting_registration_opens_at'] ?? '')));
        $registration_closes_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_meeting_registration_closes_at'] ?? '')));
        $meeting_start_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_meeting_session_start_at'] ?? '')));
        $meeting_end_at = $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_meeting_session_end_at'] ?? '')));
        if ($opens_at && $closes_at && $closes_at <= $opens_at) {
            $closes_at = 0;
        }
        if ($registration_opens_at && $registration_closes_at && $registration_closes_at <= $registration_opens_at) {
            $registration_closes_at = 0;
        }
        if ($meeting_start_at && $meeting_end_at && $meeting_end_at <= $meeting_start_at) {
            $meeting_end_at = 0;
        }
        $modules = $this->sanitize_modules((array) wp_unslash($_POST['ssf_meeting_modules'] ?? array()));
        $invitation = $this->sanitize_invitation((array) wp_unslash($_POST['ssf_meeting_invitation'] ?? array()));
        $dinner = $this->sanitize_dinner((array) wp_unslash($_POST['ssf_meeting_dinner'] ?? array()));
        $values = array(
            'year' => $year,
            'meeting_date' => $start_at ? wp_date('Y-m-d', $start_at, wp_timezone()) : $legacy_meeting_date,
            'start_at' => $start_at,
            'end_at' => $end_at,
            'meeting_start_at' => $meeting_start_at,
            'meeting_end_at' => $meeting_end_at,
            'location' => sanitize_text_field(wp_unslash($_POST['ssf_meeting_location'] ?? '')),
            'address' => sanitize_textarea_field(wp_unslash($_POST['ssf_meeting_address'] ?? '')),
            'postal_code' => sanitize_text_field(wp_unslash($_POST['ssf_meeting_postal_code'] ?? '')),
            'city' => sanitize_text_field(wp_unslash($_POST['ssf_meeting_city'] ?? '')),
            'maps_url' => esc_url_raw(wp_unslash($_POST['ssf_meeting_maps_url'] ?? '')),
            'intro' => sanitize_textarea_field(wp_unslash($_POST['ssf_meeting_intro'] ?? '')),
            'registration_opens_at' => $registration_opens_at,
            'registration_closes_at' => $registration_closes_at,
            'motion_opens_at' => $opens_at,
            'motion_closes_at' => $closes_at,
            'allow_late_motions' => ! empty($_POST['ssf_meeting_allow_late_motions']) ? 1 : 0,
            'motions_public' => ! empty($_POST['ssf_meeting_motions_public']) ? 1 : 0,
            'motion_instructions' => wp_kses_post(wp_unslash($_POST['ssf_meeting_motion_instructions'] ?? '')),
            'motion_contact_email' => sanitize_email(wp_unslash($_POST['ssf_meeting_motion_contact_email'] ?? '')),
            'contact_name' => sanitize_text_field(wp_unslash($_POST['ssf_meeting_contact_name'] ?? '')),
            'contact_email' => sanitize_email(wp_unslash($_POST['ssf_meeting_contact_email'] ?? '')),
            'registration_open' => ! empty($_POST['ssf_meeting_registration_open']) ? 1 : 0,
            'allow_guest' => ! empty($_POST['ssf_meeting_allow_guest']) ? 1 : 0,
            'allow_edits' => ! empty($_POST['ssf_meeting_allow_edits']) ? 1 : 0,
            'capacity' => max(0, absint($_POST['ssf_meeting_capacity'] ?? 0)),
            'waitlist' => ! empty($_POST['ssf_meeting_waitlist']) ? 1 : 0,
            'calendar_title' => sanitize_text_field(wp_unslash($_POST['ssf_meeting_calendar_title'] ?? '')),
            'calendar_description' => sanitize_textarea_field(wp_unslash($_POST['ssf_meeting_calendar_description'] ?? '')),
            'modules' => $modules,
            'invitation' => $invitation,
            'dinner' => $dinner,
            'program' => $this->sanitize_program((array) wp_unslash($_POST['ssf_meeting_program'] ?? array()), $start_at, $end_at),
            'program_pdf_id' => $this->pdf_attachment_id(absint($_POST['ssf_meeting_program_pdf_id'] ?? 0)),
            'documents' => $this->sanitize_documents((array) wp_unslash($_POST['ssf_meeting_documents'] ?? array())),
            'food_options' => $this->lines(sanitize_textarea_field(wp_unslash($_POST['ssf_meeting_food_options'] ?? ''))),
            'questions' => $this->sanitize_questions((array) wp_unslash($_POST['ssf_meeting_questions'] ?? array())),
            'sharepoint_year' => $year,
            'notification_email' => sanitize_email(wp_unslash($_POST['ssf_meeting_notification_email'] ?? '')),
            'notify_each' => ! empty($_POST['ssf_meeting_notify_each']) ? 1 : 0,
            'retention_months' => min(60, max(1, absint($_POST['ssf_meeting_retention_months'] ?? 12))),
        );
        foreach ($values as $key => $value) {
            update_post_meta($post_id, '_ssf_am_' . $key, $value);
        }

        update_post_meta($post_id, '_ssf_mp_meeting_year', $year);
        update_post_meta($post_id, '_ssf_mp_meeting_date', $values['meeting_date']);
        update_post_meta($post_id, '_ssf_mp_motion_opens_at', $opens_at);
        update_post_meta($post_id, '_ssf_mp_motion_closes_at', $closes_at);
        update_post_meta($post_id, '_ssf_mp_sharepoint_folder', 'Årsmöten/' . $year . '/Motioner/');
        if (! empty($_POST['ssf_meeting_active'])) {
            update_option('ssf_member_portal_active_meeting_id', $post_id, false);
        } elseif ((int) get_option('ssf_member_portal_active_meeting_id', 0) === $post_id) {
            delete_option('ssf_member_portal_active_meeting_id');
        }
    }

    public function data(int $meeting_id): array
    {
        $meta = static function (string $key, $default = '') use ($meeting_id) {
            $value = get_post_meta($meeting_id, '_ssf_am_' . $key, true);
            return '' === $value || null === $value ? $default : $value;
        };
        $legacy_year = (int) get_post_meta($meeting_id, '_ssf_mp_meeting_year', true);
        $legacy_date = (string) get_post_meta($meeting_id, '_ssf_mp_meeting_date', true);
        $program = (array) $meta('program', array());
        $documents = (array) $meta('documents', array());
        $invitation = wp_parse_args((array) $meta('invitation', array()), array('title' => 'Kallelse', 'text' => '', 'publish_at' => 0, 'pdf_id' => 0, 'visible' => 1));
        $dinner = wp_parse_args((array) $meta('dinner', array()), array('title' => 'Middag', 'start_at' => 0, 'end_at' => 0, 'opens_at' => 0, 'location' => '', 'description' => '', 'price' => '', 'deadline' => 0, 'capacity' => 0, 'food_enabled' => 1, 'manual_open' => 0));
        $stored_modules = (array) $meta('modules', array());
        $modules = wp_parse_args($stored_modules, array(
            'invitation' => ! empty($invitation['text']) || ! empty($invitation['pdf_id']),
            'meeting' => 1,
            'dinner' => ! empty($dinner['start_at']),
            'day2' => ! empty($program),
            'motions' => (bool) $meta('motions_public', 1),
            'documents' => ! empty($documents),
            'calendar' => 1,
        ));
        return array(
            'id' => $meeting_id,
            'year' => (int) $meta('year', $legacy_year),
            'meeting_date' => (string) $meta('meeting_date', $legacy_date),
            'motion_opens_at' => (int) $meta('motion_opens_at', (int) get_post_meta($meeting_id, '_ssf_mp_motion_opens_at', true)),
            'motion_closes_at' => (int) $meta('motion_closes_at', (int) get_post_meta($meeting_id, '_ssf_mp_motion_closes_at', true)),
            'sharepoint_folder' => (string) get_post_meta($meeting_id, '_ssf_mp_sharepoint_folder', true),
            'start_at' => (int) $meta('start_at', 0),
            'end_at' => (int) $meta('end_at', 0),
            'meeting_start_at' => (int) $meta('meeting_start_at', 0),
            'meeting_end_at' => (int) $meta('meeting_end_at', 0),
            'location' => (string) $meta('location', ''),
            'address' => (string) $meta('address', ''),
            'postal_code' => (string) $meta('postal_code', ''),
            'city' => (string) $meta('city', ''),
            'maps_url' => (string) $meta('maps_url', ''),
            'intro' => (string) $meta('intro', ''),
            'registration_opens_at' => (int) $meta('registration_opens_at', 0),
            'registration_closes_at' => (int) $meta('registration_closes_at', 0),
            'allow_late_motions' => (bool) $meta('allow_late_motions', 'yes' === get_option('ssf_member_portal_late_override', 'no')),
            'motions_public' => (bool) $meta('motions_public', 1),
            'motion_instructions' => (string) $meta('motion_instructions', ''),
            'motion_contact_email' => (string) $meta('motion_contact_email', ''),
            'contact_name' => (string) $meta('contact_name', ''),
            'contact_email' => (string) $meta('contact_email', ''),
            'registration_open' => (bool) $meta('registration_open', 0),
            'allow_guest' => (bool) $meta('allow_guest', 0),
            'allow_edits' => (bool) $meta('allow_edits', 1),
            'capacity' => (int) $meta('capacity', 0),
            'waitlist' => (bool) $meta('waitlist', 0),
            'calendar_title' => (string) $meta('calendar_title', ''),
            'calendar_description' => (string) $meta('calendar_description', ''),
            'modules' => array_map('boolval', $modules),
            'invitation' => $invitation,
            'dinner' => $dinner,
            'program' => $this->normalise_program($program),
            'program_pdf_id' => (int) $meta('program_pdf_id', 0),
            'documents' => $documents,
            'food_options' => (array) $meta('food_options', array('Vegetariskt', 'Veganskt', 'Glutenfritt', 'Laktosfritt')),
            'questions' => (array) $meta('questions', array()),
            'sharepoint_year' => (int) $meta('sharepoint_year', $legacy_year),
            'notification_email' => (string) $meta('notification_email', 'styrelsen@ssfb.se'),
            'notify_each' => (bool) $meta('notify_each', 0),
            'retention_months' => (int) $meta('retention_months', 12),
        );
    }

    public function active(): ?\WP_Post
    {
        $id = (int) get_option('ssf_member_portal_active_meeting_id', 0);
        $meeting = $id ? get_post($id) : null;
        return $meeting && self::POST_TYPE === $meeting->post_type ? $meeting : null;
    }

    public function all(): array
    {
        return get_posts(array('post_type' => self::POST_TYPE, 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => -1, 'meta_key' => '_ssf_am_start_at', 'orderby' => 'meta_value_num', 'order' => 'DESC'));
    }

    public function registration_url(array $args = array()): string
    {
        $page_id = (int) get_option('ssf_member_portal_annual_meeting_registration_page_id');
        return add_query_arg($args, $page_id ? get_permalink($page_id) : home_url('/arsmote/anmalan/'));
    }

    public function meeting_url(array $args = array()): string
    {
        $page_id = (int) get_option('ssf_member_portal_annual_meeting_page_id');
        return add_query_arg($args, $page_id ? get_permalink($page_id) : home_url('/arsmote/'));
    }

    public function calendar_url(int $meeting_id): string
    {
        return add_query_arg(
            array('action' => 'ssf_member_portal_annual_meeting_calendar_public', 'meeting' => $meeting_id),
            admin_url('admin-post.php')
        );
    }

    public function motion_url(array $args = array()): string
    {
        $page_id = (int) get_option('ssf_member_portal_motion_form_page_id');
        return add_query_arg($args, $page_id ? get_permalink($page_id) : home_url('/lamna-motion/'));
    }

    public function archive_url(): string
    {
        $page_id = (int) get_option('ssf_member_portal_annual_meetings_archive_page_id');
        return $page_id ? get_permalink($page_id) : home_url('/arsmoten/');
    }

    public function is_registration_open(array $meeting): bool
    {
        return ! empty($this->registration_service->registration_state($meeting)['can_register']);
    }

    public function module_enabled(array $meeting, string $module): bool
    {
        return ! empty($meeting['modules'][$module]);
    }

    public function registration_choices(array $meeting): array
    {
        $choices = array();
        if ($this->module_enabled($meeting, 'dinner') && ! empty($meeting['dinner']['start_at'])) {
            $dinner = $meeting['dinner'];
            $choices[] = array(
                'key' => 'dinner',
                'title' => (string) ($dinner['title'] ?: __('Middag', 'ssf-member-portal')),
                'date' => wp_date('Y-m-d', (int) $dinner['start_at'], wp_timezone()),
                'start' => wp_date('H:i', (int) $dinner['start_at'], wp_timezone()),
                'end' => ! empty($dinner['end_at']) ? wp_date('H:i', (int) $dinner['end_at'], wp_timezone()) : '',
                'location' => (string) $dinner['location'],
                'description' => (string) $dinner['description'],
                'capacity' => max(0, (int) $dinner['capacity']),
                'opens_at' => (int) ($dinner['opens_at'] ?? 0),
                'deadline' => (int) $dinner['deadline'],
                'food' => ! empty($dinner['food_enabled']) ? 1 : 0,
                'closed' => 0,
                'manual_open' => ! empty($dinner['manual_open']) ? 1 : 0,
                'starts_at' => (int) $dinner['start_at'],
                'ends_at' => (int) ($dinner['end_at'] ?? 0),
                'price' => (string) $dinner['price'],
                'optional' => 1,
                'visible' => 1,
                'source' => 'dinner',
            );
        }
        if ($this->module_enabled($meeting, 'day2')) {
            foreach ((array) $meeting['program'] as $item) {
                if (empty($item['visible']) || empty($item['requires_registration'])) {
                    continue;
                }
                $starts_at = ! empty($item['date']) && ! empty($item['start']) ? $this->timestamp($item['date'] . 'T' . $item['start']) : 0;
                $ends_at = ! empty($item['date']) && ! empty($item['end']) ? $this->timestamp($item['date'] . 'T' . $item['end']) : 0;
                $choices[] = array_merge($item, array('source' => 'activity', 'starts_at' => $starts_at, 'ends_at' => $ends_at));
            }
        }
        return $choices;
    }

    private function render_program_row($index, array $item): void
    {
        $prefix = 'ssf_meeting_program[' . $index . ']';
        ?>
        <tr><td><input type="hidden" name="<?php echo esc_attr($prefix); ?>[key]" value="<?php echo esc_attr((string) ($item['key'] ?? '')); ?>"><input type="date" name="<?php echo esc_attr($prefix); ?>[date]" value="<?php echo esc_attr((string) ($item['date'] ?? '')); ?>"><br><input type="time" name="<?php echo esc_attr($prefix); ?>[start]" value="<?php echo esc_attr((string) ($item['start'] ?? '')); ?>">–<input type="time" name="<?php echo esc_attr($prefix); ?>[end]" value="<?php echo esc_attr((string) ($item['end'] ?? '')); ?>"></td><td><input class="widefat" name="<?php echo esc_attr($prefix); ?>[title]" value="<?php echo esc_attr((string) ($item['title'] ?? '')); ?>" placeholder="<?php esc_attr_e('Rubrik', 'ssf-member-portal'); ?>"><textarea class="widefat" rows="2" name="<?php echo esc_attr($prefix); ?>[description]" placeholder="<?php esc_attr_e('Beskrivning', 'ssf-member-portal'); ?>"><?php echo esc_textarea((string) ($item['description'] ?? '')); ?></textarea><input class="widefat" name="<?php echo esc_attr($prefix); ?>[location]" value="<?php echo esc_attr((string) ($item['location'] ?? '')); ?>" placeholder="<?php esc_attr_e('Plats', 'ssf-member-portal'); ?>"></td><td><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[ask]" value="1" <?php checked(! empty($item['ask'])); ?>> <?php esc_html_e('Fråga deltagaren', 'ssf-member-portal'); ?></label><br><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[optional]" value="1" <?php checked(! empty($item['optional'])); ?>> <?php esc_html_e('Valbar', 'ssf-member-portal'); ?></label><br><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[food]" value="1" <?php checked(! empty($item['food'])); ?>> <?php esc_html_e('Påverkar mat', 'ssf-member-portal'); ?></label><br><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[closed]" value="1" <?php checked(! empty($item['closed'])); ?>> <?php esc_html_e('Stängd', 'ssf-member-portal'); ?></label><br><input type="number" min="0" name="<?php echo esc_attr($prefix); ?>[capacity]" value="<?php echo esc_attr((string) ($item['capacity'] ?? 0)); ?>" placeholder="<?php esc_attr_e('Platser', 'ssf-member-portal'); ?>"></td><td><button class="button-link-delete" type="button" data-ssf-remove-row><?php esc_html_e('Ta bort', 'ssf-member-portal'); ?></button></td></tr>
        <?php
    }

    private function render_question_row($index, array $item): void
    {
        $prefix = 'ssf_meeting_questions[' . $index . ']';
        ?>
        <tr><td><input type="hidden" name="<?php echo esc_attr($prefix); ?>[key]" value="<?php echo esc_attr((string) ($item['key'] ?? '')); ?>"><input class="widefat" name="<?php echo esc_attr($prefix); ?>[title]" value="<?php echo esc_attr((string) ($item['title'] ?? '')); ?>" placeholder="<?php esc_attr_e('Rubrik', 'ssf-member-portal'); ?>"><textarea class="widefat" rows="2" name="<?php echo esc_attr($prefix); ?>[help]" placeholder="<?php esc_attr_e('Hjälptext', 'ssf-member-portal'); ?>"><?php echo esc_textarea((string) ($item['help'] ?? '')); ?></textarea></td><td><select name="<?php echo esc_attr($prefix); ?>[type]"><?php foreach (array('text' => 'Kort text', 'textarea' => 'Lång text', 'yes_no' => 'Ja/nej', 'checkbox' => 'Checkbox', 'single' => 'Ett val', 'multiple' => 'Flera val', 'date' => 'Datum', 'info' => 'Informationsblock') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected((string) ($item['type'] ?? 'text'), $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><textarea class="widefat" rows="2" name="<?php echo esc_attr($prefix); ?>[options]" placeholder="<?php esc_attr_e('Alternativ, ett per rad', 'ssf-member-portal'); ?>"><?php echo esc_textarea(implode("\n", (array) ($item['options'] ?? array()))); ?></textarea></td><td><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[required]" value="1" <?php checked(! empty($item['required'])); ?>> <?php esc_html_e('Obligatorisk', 'ssf-member-portal'); ?></label><br><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[visible]" value="1" <?php checked(! isset($item['visible']) || ! empty($item['visible'])); ?>> <?php esc_html_e('Synlig', 'ssf-member-portal'); ?></label><br><input type="number" min="0" name="<?php echo esc_attr($prefix); ?>[order]" value="<?php echo esc_attr((string) ($item['order'] ?? 0)); ?>" placeholder="<?php esc_attr_e('Ordning', 'ssf-member-portal'); ?>"></td><td><button class="button-link-delete" type="button" data-ssf-remove-row><?php esc_html_e('Ta bort', 'ssf-member-portal'); ?></button></td></tr>
        <?php
    }

    private function program_row(): array
    {
        return array('key' => '', 'date' => '', 'start' => '', 'end' => '', 'title' => '', 'description' => '', 'location' => '', 'requires_registration' => 0, 'ask' => 0, 'optional' => 1, 'food' => 0, 'closed' => 0, 'manual_open' => 0, 'capacity' => 0, 'opens_at' => 0, 'deadline' => 0, 'price' => '', 'visible' => 1, 'order' => 0);
    }

    private function question_row(): array
    {
        return array('key' => '', 'title' => '', 'help' => '', 'type' => 'text', 'options' => array(), 'required' => 0, 'visible' => 1, 'order' => 0);
    }

    private function sanitize_program(array $rows, int $weekend_start = 0, int $weekend_end = 0): array
    {
        $program = array();
        $used = array();
        foreach ($rows as $row) {
            $title = sanitize_text_field($row['title'] ?? '');
            if (! $title) {
                continue;
            }
            $key = sanitize_key($row['key'] ?? '');
            if (! $key || isset($used[$key])) {
                $key = 'program_' . (count($program) + 1);
            }
            $used[$key] = true;
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['date'] ?? '')) ? (string) $row['date'] : '';
            $start = preg_match('/^\d{2}:\d{2}$/', (string) ($row['start'] ?? '')) ? (string) $row['start'] : '';
            $end = preg_match('/^\d{2}:\d{2}$/', (string) ($row['end'] ?? '')) ? (string) $row['end'] : '';
            if ($start && $end && $end <= $start) {
                $end = '';
            }
            $deadline = $this->timestamp(sanitize_text_field((string) ($row['deadline'] ?? '')));
            $opens_at = $this->timestamp(sanitize_text_field((string) ($row['opens_at'] ?? '')));
            if ($date && $weekend_start && ($date < wp_date('Y-m-d', $weekend_start, wp_timezone()) || ($weekend_end && $date > wp_date('Y-m-d', $weekend_end, wp_timezone())))) {
                $date = '';
            }
            $event_at = $date && $start ? $this->timestamp($date . 'T' . $start) : 0;
            if ($deadline && $event_at && $deadline > $event_at) {
                $deadline = 0;
            }
            if ($opens_at && $deadline && $opens_at > $deadline) {
                $opens_at = 0;
            }
            $requires_registration = ! empty($row['requires_registration']) || ! empty($row['ask']);
            $program[] = array(
                'key' => $key,
                'date' => $date,
                'start' => $start,
                'end' => $end,
                'title' => $title,
                'description' => sanitize_textarea_field($row['description'] ?? ''),
                'location' => sanitize_text_field($row['location'] ?? ''),
                'requires_registration' => $requires_registration ? 1 : 0,
                'ask' => $requires_registration ? 1 : 0,
                'optional' => ! empty($row['optional']) ? 1 : 0,
                'food' => ! empty($row['food']) ? 1 : 0,
                'closed' => ! empty($row['closed']) ? 1 : 0,
                'manual_open' => ! empty($row['manual_open']) ? 1 : 0,
                'capacity' => max(0, absint($row['capacity'] ?? 0)),
                'opens_at' => $opens_at,
                'deadline' => $deadline,
                'price' => sanitize_text_field($row['price'] ?? ''),
                'visible' => ! empty($row['visible']) ? 1 : 0,
                'order' => max(0, absint($row['order'] ?? count($program))),
            );
        }
        usort($program, static function (array $a, array $b): int { return $a['order'] <=> $b['order']; });
        return $program;
    }

    private function sanitize_questions(array $rows): array
    {
        $questions = array();
        $used = array();
        $types = array('text', 'textarea', 'yes_no', 'checkbox', 'single', 'multiple', 'date', 'info');
        foreach ($rows as $index => $row) {
            $title = sanitize_text_field($row['title'] ?? '');
            if (! $title) {
                continue;
            }
            $key = sanitize_key($row['key'] ?? '') ?: sanitize_key($title) ?: 'fraga_' . ($index + 1);
            $base = $key;
            $suffix = 2;
            while (isset($used[$key])) {
                $key = $base . '_' . $suffix++;
            }
            $used[$key] = true;
            $type = sanitize_key($row['type'] ?? 'text');
            $questions[] = array(
                'key' => $key,
                'title' => $title,
                'help' => sanitize_textarea_field($row['help'] ?? ''),
                'type' => in_array($type, $types, true) ? $type : 'text',
                'options' => $this->lines(sanitize_textarea_field($row['options'] ?? '')),
                'required' => ! empty($row['required']) ? 1 : 0,
                'visible' => ! empty($row['visible']) ? 1 : 0,
                'order' => max(0, absint($row['order'] ?? $index)),
            );
        }
        usort($questions, static function (array $a, array $b): int { return $a['order'] <=> $b['order']; });
        return $questions;
    }

    private function sanitize_modules(array $values): array
    {
        $modules = array();
        foreach (array('invitation', 'meeting', 'dinner', 'day2', 'motions', 'documents', 'calendar') as $key) {
            $modules[$key] = ! empty($values[$key]) ? 1 : 0;
        }
        $modules['meeting'] = 1;
        return $modules;
    }

    private function sanitize_invitation(array $value): array
    {
        return array(
            'title' => sanitize_text_field($value['title'] ?? '') ?: __('Kallelse', 'ssf-member-portal'),
            'text' => wp_kses_post($value['text'] ?? ''),
            'publish_at' => $this->timestamp(sanitize_text_field((string) ($value['publish_at'] ?? ''))),
            'pdf_id' => $this->pdf_attachment_id(absint($value['pdf_id'] ?? 0)),
            'visible' => ! empty($value['visible']) ? 1 : 0,
        );
    }

    private function sanitize_dinner(array $value): array
    {
        $start_at = $this->timestamp(sanitize_text_field((string) ($value['start_at'] ?? '')));
        $end_at = $this->timestamp(sanitize_text_field((string) ($value['end_at'] ?? '')));
        $opens_at = $this->timestamp(sanitize_text_field((string) ($value['opens_at'] ?? '')));
        $deadline = $this->timestamp(sanitize_text_field((string) ($value['deadline'] ?? '')));
        if ($start_at && $end_at && $end_at <= $start_at) {
            $end_at = 0;
        }
        if ($start_at && $deadline && $deadline > $start_at) {
            $deadline = 0;
        }
        if ($opens_at && $deadline && $opens_at > $deadline) {
            $opens_at = 0;
        }
        return array(
            'title' => sanitize_text_field($value['title'] ?? '') ?: __('Middag', 'ssf-member-portal'),
            'start_at' => $start_at,
            'end_at' => $end_at,
            'opens_at' => $opens_at,
            'location' => sanitize_text_field($value['location'] ?? ''),
            'description' => sanitize_textarea_field($value['description'] ?? ''),
            'price' => sanitize_text_field($value['price'] ?? ''),
            'deadline' => $deadline,
            'capacity' => max(0, absint($value['capacity'] ?? 0)),
            'food_enabled' => ! empty($value['food_enabled']) ? 1 : 0,
            'manual_open' => ! empty($value['manual_open']) ? 1 : 0,
        );
    }

    private function sanitize_documents(array $rows): array
    {
        $documents = array();
        $types = array('agenda', 'annual_report', 'financial_report', 'budget', 'motions', 'board_response', 'minutes', 'other');
        foreach ($rows as $index => $row) {
            $attachment_id = $this->pdf_attachment_id(absint($row['attachment_id'] ?? 0));
            if (! $attachment_id) {
                continue;
            }
            $type = sanitize_key($row['type'] ?? 'other');
            $documents[] = array(
                'attachment_id' => $attachment_id,
                'title' => sanitize_text_field($row['title'] ?? '') ?: get_the_title($attachment_id),
                'type' => in_array($type, $types, true) ? $type : 'other',
                'visible' => ! empty($row['visible']) ? 1 : 0,
                'order' => max(0, absint($row['order'] ?? $index)),
            );
        }
        usort($documents, static function (array $a, array $b): int { return $a['order'] <=> $b['order']; });
        return $documents;
    }

    private function normalise_program(array $rows): array
    {
        $program = array();
        foreach ($rows as $index => $row) {
            $requires_registration = ! empty($row['requires_registration']) || ! empty($row['ask']);
            $item = wp_parse_args($row, array(
                'key' => 'program_' . ($index + 1),
                'date' => '',
                'start' => '',
                'end' => '',
                'title' => '',
                'description' => '',
                'location' => '',
                'requires_registration' => $requires_registration ? 1 : 0,
                'ask' => $requires_registration ? 1 : 0,
                'optional' => 1,
                'food' => 0,
                'closed' => 0,
                'manual_open' => 0,
                'capacity' => 0,
                'opens_at' => 0,
                'deadline' => 0,
                'price' => '',
                'visible' => 1,
                'order' => $index,
            ));
            $item['requires_registration'] = $requires_registration ? 1 : 0;
            $item['ask'] = $requires_registration ? 1 : 0;
            $item['visible'] = array_key_exists('visible', $row) ? (! empty($row['visible']) ? 1 : 0) : 1;
            $program[] = $item;
        }
        usort($program, static function (array $a, array $b): int { return (int) $a['order'] <=> (int) $b['order']; });
        return $program;
    }

    private function pdf_attachment_id(int $attachment_id): int
    {
        return $attachment_id && 'application/pdf' === get_post_mime_type($attachment_id) ? $attachment_id : 0;
    }

    private function lines(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', preg_split('/\r\n|\r|\n/', $value)))));
    }

    private function timestamp(string $value): int
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, wp_timezone());
        return $date ? $date->getTimestamp() : 0;
    }

    private function input_date(int $timestamp): string
    {
        return $timestamp ? wp_date('Y-m-d\TH:i', $timestamp, wp_timezone()) : '';
    }
}
