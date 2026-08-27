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
    private Admin $admin;

    public function __construct()
    {
        $this->registrations = new RegistrationPostType();
        $this->registration_service = new RegistrationService($this, new RegistrationMailer(), new RegistrationExport(), new SharePoint(new GraphClient(new Authentication())));
        $this->admin = new Admin($this, $this->registration_service);
        new Frontend($this, $this->registration_service);

        add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'add_meta_box'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
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
            'supports' => array('title', 'editor', 'revisions'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));

        $this->registrations->register();
        if (! wp_next_scheduled('ssf_member_portal_annual_meeting_retention')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ssf_member_portal_annual_meeting_retention');
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
            'post_title' => 'Anmälan till årsmöte',
            'post_content' => '[ssf_member_portal_annual_meeting_registration]',
        ));
        update_option('ssf_member_portal_annual_meeting_registration_page_id', $registration_id, false);
    }

    public function register_admin_menu(string $parent): void
    {
        $this->admin->register_menu($parent);
    }

    public function add_meta_box(): void
    {
        add_meta_box('ssf-member-portal-meeting', __('Årsmötesuppgifter', 'ssf-member-portal'), array($this, 'render_meta_box'), self::POST_TYPE, 'normal', 'high');
    }

    public function enqueue_admin_assets(string $hook): void
    {
        $screen = get_current_screen();
        if (! $screen || self::POST_TYPE !== $screen->post_type || ! in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        wp_enqueue_script('ssf-member-portal-annual-meeting-admin', SSF_MEMBER_PORTAL_URL . 'assets/js/annual-meetings-admin.js', array(), SSF_MEMBER_PORTAL_VERSION, true);
    }

    public function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('ssf_member_portal_meeting', 'ssf_member_portal_meeting_nonce');
        $data = $this->data($post->ID);
        $program = $data['program'] ?: array($this->program_row());
        $questions = $data['questions'] ?: array($this->question_row());
        ?>
        <div class="ssf-am-admin">
            <h2><?php esc_html_e('Grunduppgifter', 'ssf-member-portal'); ?></h2>
            <p><label><strong><?php esc_html_e('År', 'ssf-member-portal'); ?></strong><br><input name="ssf_meeting_year" type="number" min="2000" max="2100" value="<?php echo esc_attr($data['year']); ?>"></label></p>
            <p><label><strong><?php esc_html_e('Start', 'ssf-member-portal'); ?></strong><br><input name="ssf_meeting_start_at" type="datetime-local" value="<?php echo esc_attr($this->input_date($data['start_at'])); ?>"></label></p>
            <p><label><strong><?php esc_html_e('Slut', 'ssf-member-portal'); ?></strong><br><input name="ssf_meeting_end_at" type="datetime-local" value="<?php echo esc_attr($this->input_date($data['end_at'])); ?>"></label></p>
            <p><label><strong><?php esc_html_e('Plats', 'ssf-member-portal'); ?></strong><br><input class="widefat" name="ssf_meeting_location" value="<?php echo esc_attr($data['location']); ?>"></label></p>
            <p><label><strong><?php esc_html_e('Adress', 'ssf-member-portal'); ?></strong><br><textarea class="widefat" rows="2" name="ssf_meeting_address"><?php echo esc_textarea($data['address']); ?></textarea></label></p>
            <p><label><strong><?php esc_html_e('Kort introduktion', 'ssf-member-portal'); ?></strong><br><textarea class="widefat" rows="3" name="ssf_meeting_intro"><?php echo esc_textarea($data['intro']); ?></textarea></label></p>
            <p><label><strong><?php esc_html_e('Sista anmälningsdag', 'ssf-member-portal'); ?></strong><br><input name="ssf_meeting_registration_closes_at" type="datetime-local" value="<?php echo esc_attr($this->input_date($data['registration_closes_at'])); ?>"></label></p>
            <p><label><strong><?php esc_html_e('Kontaktperson', 'ssf-member-portal'); ?></strong><br><input name="ssf_meeting_contact_name" value="<?php echo esc_attr($data['contact_name']); ?>"></label></p>
            <p><label><strong><?php esc_html_e('Kontakt-e-post', 'ssf-member-portal'); ?></strong><br><input type="email" name="ssf_meeting_contact_email" value="<?php echo esc_attr($data['contact_email']); ?>"></label></p>
            <p><label><input type="checkbox" name="ssf_meeting_active" value="1" <?php checked((int) get_option('ssf_member_portal_active_meeting_id', 0), $post->ID); ?>> <?php esc_html_e('Detta är det aktiva årsmötet som visas publikt', 'ssf-member-portal'); ?></label></p>

            <h2><?php esc_html_e('Motionsperiod', 'ssf-member-portal'); ?></h2>
            <p><label><strong><?php esc_html_e('Motioner öppnar', 'ssf-member-portal'); ?></strong><br><input name="ssf_motion_opens_at" type="datetime-local" value="<?php echo esc_attr($this->input_date($data['motion_opens_at'])); ?>"></label></p>
            <p><label><strong><?php esc_html_e('Motioner stänger', 'ssf-member-portal'); ?></strong><br><input name="ssf_motion_closes_at" type="datetime-local" value="<?php echo esc_attr($this->input_date($data['motion_closes_at'])); ?>"></label><br><span class="description"><?php esc_html_e('Motionsfunktionen behåller sin befintliga period och SharePoint-mapp.', 'ssf-member-portal'); ?></span></p>

            <h2><?php esc_html_e('Anmälan och kalender', 'ssf-member-portal'); ?></h2>
            <p><label><input type="checkbox" name="ssf_meeting_registration_open" value="1" <?php checked($data['registration_open']); ?>> <?php esc_html_e('Anmälan är öppen', 'ssf-member-portal'); ?></label></p>
            <p><label><input type="checkbox" name="ssf_meeting_allow_guest" value="1" <?php checked($data['allow_guest']); ?>> <?php esc_html_e('Tillåt annan eller inbjuden deltagare', 'ssf-member-portal'); ?></label></p>
            <p><label><input type="checkbox" name="ssf_meeting_allow_edits" value="1" <?php checked($data['allow_edits']); ?>> <?php esc_html_e('Tillåt ändring och avbokning fram till sista anmälningsdag', 'ssf-member-portal'); ?></label></p>
            <p><label><strong><?php esc_html_e('Max antal deltagare', 'ssf-member-portal'); ?></strong><br><input name="ssf_meeting_capacity" type="number" min="0" value="<?php echo esc_attr($data['capacity']); ?>"><br><span class="description"><?php esc_html_e('Lämna 0 för obegränsat.', 'ssf-member-portal'); ?></span></label></p>
            <p><label><input type="checkbox" name="ssf_meeting_waitlist" value="1" <?php checked($data['waitlist']); ?>> <?php esc_html_e('Använd reservlista när mötet är fullt', 'ssf-member-portal'); ?></label></p>
            <p><label><strong><?php esc_html_e('Kalendertitel', 'ssf-member-portal'); ?></strong><br><input class="widefat" name="ssf_meeting_calendar_title" value="<?php echo esc_attr($data['calendar_title']); ?>"></label></p>
            <p><label><strong><?php esc_html_e('Kalenderbeskrivning', 'ssf-member-portal'); ?></strong><br><textarea class="widefat" rows="3" name="ssf_meeting_calendar_description"><?php echo esc_textarea($data['calendar_description']); ?></textarea></label></p>

            <h2><?php esc_html_e('Program', 'ssf-member-portal'); ?></h2>
            <p class="description"><?php esc_html_e('Markera Fråga deltagaren för de programpunkter som ska visas i anmälan. Markera Påverkar mat för måltider.', 'ssf-member-portal'); ?></p>
            <table class="widefat striped ssf-am-repeater" data-ssf-repeater="program"><thead><tr><th><?php esc_html_e('Datum/tid', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Programpunkt', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Val', 'ssf-member-portal'); ?></th><th></th></tr></thead><tbody>
                <?php foreach ($program as $index => $item) : $this->render_program_row((int) $index, $item); endforeach; ?>
            </tbody></table>
            <p><button class="button" type="button" data-ssf-add-row="program"><?php esc_html_e('Lägg till programpunkt', 'ssf-member-portal'); ?></button></p>

            <h2><?php esc_html_e('Mat och specialkost', 'ssf-member-portal'); ?></h2>
            <p><label><strong><?php esc_html_e('Alternativ, ett per rad', 'ssf-member-portal'); ?></strong><br><textarea class="widefat" rows="5" name="ssf_meeting_food_options"><?php echo esc_textarea(implode("\n", $data['food_options'])); ?></textarea></label><br><span class="description"><?php esc_html_e('Ange bara det arrangören behöver för maten, inte medicinska diagnoser.', 'ssf-member-portal'); ?></span></p>

            <h2><?php esc_html_e('Extra frågor', 'ssf-member-portal'); ?></h2>
            <table class="widefat striped ssf-am-repeater" data-ssf-repeater="question"><thead><tr><th><?php esc_html_e('Fråga', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Typ och alternativ', 'ssf-member-portal'); ?></th><th><?php esc_html_e('Inställningar', 'ssf-member-portal'); ?></th><th></th></tr></thead><tbody>
                <?php foreach ($questions as $index => $item) : $this->render_question_row((int) $index, $item); endforeach; ?>
            </tbody></table>
            <p><button class="button" type="button" data-ssf-add-row="question"><?php esc_html_e('Lägg till fråga', 'ssf-member-portal'); ?></button></p>

            <h2><?php esc_html_e('Administration och integritet', 'ssf-member-portal'); ?></h2>
            <p><label><strong><?php esc_html_e('SharePoint-år', 'ssf-member-portal'); ?></strong><br><input type="number" min="2000" max="2100" name="ssf_meeting_sharepoint_year" value="<?php echo esc_attr($data['sharepoint_year']); ?>"></label><br><code><?php echo esc_html('Årsmöten/' . ($data['sharepoint_year'] ?: 'YYYY') . '/Anmälningar/'); ?></code></p>
            <p><label><strong><?php esc_html_e('Mottagare för notifiering', 'ssf-member-portal'); ?></strong><br><input type="email" class="regular-text" name="ssf_meeting_notification_email" value="<?php echo esc_attr($data['notification_email']); ?>"></label></p>
            <p><label><input type="checkbox" name="ssf_meeting_notify_each" value="1" <?php checked($data['notify_each']); ?>> <?php esc_html_e('Maila mottagaren vid varje ny anmälan', 'ssf-member-portal'); ?></label></p>
            <p><label><strong><?php esc_html_e('Gallra personuppgifter efter', 'ssf-member-portal'); ?></strong><br><input type="number" min="1" max="60" name="ssf_meeting_retention_months" value="<?php echo esc_attr($data['retention_months']); ?>"> <?php esc_html_e('månader efter mötets slut', 'ssf-member-portal'); ?></label></p>
        </div>
        <script type="text/html" id="tmpl-ssf-am-program-row"><?php $this->render_program_row('__INDEX__', $this->program_row()); ?></script>
        <script type="text/html" id="tmpl-ssf-am-question-row"><?php $this->render_question_row('__INDEX__', $this->question_row()); ?></script>
        <?php
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
        if ($opens_at && $closes_at && $closes_at <= $opens_at) {
            $closes_at = 0;
        }
        $values = array(
            'year' => $year,
            'meeting_date' => $start_at ? wp_date('Y-m-d', $start_at, wp_timezone()) : $legacy_meeting_date,
            'start_at' => $start_at,
            'end_at' => $end_at,
            'location' => sanitize_text_field(wp_unslash($_POST['ssf_meeting_location'] ?? '')),
            'address' => sanitize_textarea_field(wp_unslash($_POST['ssf_meeting_address'] ?? '')),
            'intro' => sanitize_textarea_field(wp_unslash($_POST['ssf_meeting_intro'] ?? '')),
            'registration_closes_at' => $this->timestamp(sanitize_text_field(wp_unslash($_POST['ssf_meeting_registration_closes_at'] ?? ''))),
            'contact_name' => sanitize_text_field(wp_unslash($_POST['ssf_meeting_contact_name'] ?? '')),
            'contact_email' => sanitize_email(wp_unslash($_POST['ssf_meeting_contact_email'] ?? '')),
            'registration_open' => ! empty($_POST['ssf_meeting_registration_open']) ? 1 : 0,
            'allow_guest' => ! empty($_POST['ssf_meeting_allow_guest']) ? 1 : 0,
            'allow_edits' => ! empty($_POST['ssf_meeting_allow_edits']) ? 1 : 0,
            'capacity' => max(0, absint($_POST['ssf_meeting_capacity'] ?? 0)),
            'waitlist' => ! empty($_POST['ssf_meeting_waitlist']) ? 1 : 0,
            'calendar_title' => sanitize_text_field(wp_unslash($_POST['ssf_meeting_calendar_title'] ?? '')),
            'calendar_description' => sanitize_textarea_field(wp_unslash($_POST['ssf_meeting_calendar_description'] ?? '')),
            'program' => $this->sanitize_program((array) wp_unslash($_POST['ssf_meeting_program'] ?? array())),
            'food_options' => $this->lines(sanitize_textarea_field(wp_unslash($_POST['ssf_meeting_food_options'] ?? ''))),
            'questions' => $this->sanitize_questions((array) wp_unslash($_POST['ssf_meeting_questions'] ?? array())),
            'sharepoint_year' => min(2100, max(2000, absint($_POST['ssf_meeting_sharepoint_year'] ?? $year))),
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
        return array(
            'id' => $meeting_id,
            'year' => (int) $meta('year', $legacy_year),
            'meeting_date' => (string) $meta('meeting_date', $legacy_date),
            'motion_opens_at' => (int) get_post_meta($meeting_id, '_ssf_mp_motion_opens_at', true),
            'motion_closes_at' => (int) get_post_meta($meeting_id, '_ssf_mp_motion_closes_at', true),
            'sharepoint_folder' => (string) get_post_meta($meeting_id, '_ssf_mp_sharepoint_folder', true),
            'start_at' => (int) $meta('start_at', 0),
            'end_at' => (int) $meta('end_at', 0),
            'location' => (string) $meta('location', ''),
            'address' => (string) $meta('address', ''),
            'intro' => (string) $meta('intro', ''),
            'registration_closes_at' => (int) $meta('registration_closes_at', 0),
            'contact_name' => (string) $meta('contact_name', ''),
            'contact_email' => (string) $meta('contact_email', ''),
            'registration_open' => (bool) $meta('registration_open', 0),
            'allow_guest' => (bool) $meta('allow_guest', 0),
            'allow_edits' => (bool) $meta('allow_edits', 1),
            'capacity' => (int) $meta('capacity', 0),
            'waitlist' => (bool) $meta('waitlist', 0),
            'calendar_title' => (string) $meta('calendar_title', ''),
            'calendar_description' => (string) $meta('calendar_description', ''),
            'program' => (array) $meta('program', array()),
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
        return get_posts(array('post_type' => self::POST_TYPE, 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC'));
    }

    public function registration_url(array $args = array()): string
    {
        $page_id = (int) get_option('ssf_member_portal_annual_meeting_registration_page_id');
        return add_query_arg($args, $page_id ? get_permalink($page_id) : home_url('/arsmote/anmalan/'));
    }

    public function meeting_url(): string
    {
        $page_id = (int) get_option('ssf_member_portal_annual_meeting_page_id');
        return $page_id ? get_permalink($page_id) : home_url('/arsmote/');
    }

    public function is_registration_open(array $meeting): bool
    {
        return ! empty($meeting['registration_open']) && (! $meeting['registration_closes_at'] || time() <= (int) $meeting['registration_closes_at']);
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
        return array('key' => '', 'date' => '', 'start' => '', 'end' => '', 'title' => '', 'description' => '', 'location' => '', 'ask' => 1, 'optional' => 1, 'food' => 0, 'closed' => 0, 'capacity' => 0);
    }

    private function question_row(): array
    {
        return array('key' => '', 'title' => '', 'help' => '', 'type' => 'text', 'options' => array(), 'required' => 0, 'visible' => 1, 'order' => 0);
    }

    private function sanitize_program(array $rows): array
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
            $program[] = array(
                'key' => $key,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['date'] ?? '')) ? (string) $row['date'] : '',
                'start' => preg_match('/^\d{2}:\d{2}$/', (string) ($row['start'] ?? '')) ? (string) $row['start'] : '',
                'end' => preg_match('/^\d{2}:\d{2}$/', (string) ($row['end'] ?? '')) ? (string) $row['end'] : '',
                'title' => $title,
                'description' => sanitize_textarea_field($row['description'] ?? ''),
                'location' => sanitize_text_field($row['location'] ?? ''),
                'ask' => ! empty($row['ask']) ? 1 : 0,
                'optional' => ! empty($row['optional']) ? 1 : 0,
                'food' => ! empty($row['food']) ? 1 : 0,
                'closed' => ! empty($row['closed']) ? 1 : 0,
                'capacity' => max(0, absint($row['capacity'] ?? 0)),
            );
        }
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
