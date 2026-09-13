<?php
/**
 * Application data, status transitions and status-link tokens.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsprocess_Application
{
    public const POST_TYPE = 'ssf_application';
    private const ASPIRANT_REVIEW_HOOK = 'ssf_medlemsprocess_review_aspirants';

    public function __construct()
    {
        add_action(self::ASPIRANT_REVIEW_HOOK, array(__CLASS__, 'review_due_aspirants'));
    }

    public function register_post_type(): void
    {
        $show_in_menu = class_exists('SSF_Admin_Navigation') ? SSF_Admin_Navigation::MEMBERSHIP : true;

        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => 'Ansökningar',
                'singular_name' => 'Ansökan',
                'menu_name' => 'Ansökningar',
                'edit_item' => 'Granska ansökan',
                'all_items' => 'Alla ansökningar',
                'search_items' => 'Sök ansökningar',
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => $show_in_menu,
            'menu_icon' => 'dashicons-clipboard',
            'supports' => array('title', 'editor', 'author', 'revisions'),
            'capability_type' => array('ssf_application', 'ssf_applications'),
            'map_meta_cap' => true,
        ));
        if (! wp_next_scheduled(self::ASPIRANT_REVIEW_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::ASPIRANT_REVIEW_HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::ASPIRANT_REVIEW_HOOK);
    }

    public static function statuses(): array
    {
        return array_merge(self::workflow_statuses(), array(
            'draft' => array('label' => 'Utkast (äldre ärende)', 'public' => 'Ansökan har påbörjats.', 'step' => 0, 'legacy' => true),
            'submitted' => array('label' => 'Inskickad (äldre ärende)', 'public' => 'Din ansökan har skickats in.', 'step' => 1, 'legacy' => true),
            'completion_submitted' => array('label' => 'Komplettering inskickad (äldre ärende)', 'public' => 'Din komplettering har skickats till SSF.', 'step' => 3, 'legacy' => true),
            'inspection_completed' => array('label' => 'Inspektion genomförd (äldre ärende)', 'public' => 'Inspektionsunderlaget är klart.', 'step' => 4, 'legacy' => true),
            'approved' => array('label' => 'Godkänd (äldre ärende)', 'public' => 'Din ansökan har godkänts av Sveriges Segelfartygsförbund.', 'step' => 6, 'legacy' => true),
            'paused' => array('label' => 'Vilande (äldre ärende)', 'public' => 'Ärendet är tillfälligt pausat.', 'step' => 0, 'legacy' => true),
            'archived' => array('label' => 'Arkiverad (äldre ärende)', 'public' => 'Ärendet är avslutat och arkiverat.', 'step' => 6, 'legacy' => true),
        ));
    }

    public static function workflow_statuses(): array
    {
        return array(
            'received' => array('label' => 'Inkommen', 'public' => 'SSF har tagit emot din ansökan.', 'step' => 1),
            'under_review' => array('label' => 'Under granskning', 'public' => 'SSF går igenom uppgifterna i din ansökan.', 'step' => 2),
            'needs_completion' => array('label' => 'Begär komplettering', 'public' => 'SSF behöver ytterligare uppgifter från dig.', 'step' => 3),
            'awaiting_completion' => array('label' => 'Väntar på komplettering', 'public' => 'SSF väntar på din komplettering.', 'step' => 3),
            'inspection_planned' => array('label' => 'Inspektion ska bokas', 'public' => 'En inspektion behöver bokas.', 'step' => 4),
            'inspection_booked' => array('label' => 'Inspektion bokad', 'public' => 'En tid har bokats för fortsatt granskning.', 'step' => 4),
            'awaiting_decision' => array('label' => 'Under slutbedömning', 'public' => 'Ärendet är komplett och slutbedöms av SSF.', 'step' => 5),
            'approved_aspirant' => array('label' => 'Godkänd som aspirant', 'public' => 'Din ansökan har godkänts som aspirant.', 'step' => 6),
            'rejected' => array('label' => 'Avslagen', 'public' => 'SSF har fattat beslut om din ansökan.', 'step' => 6),
        );
    }

    public static function membership_statuses(): array
    {
        return array(
            'not_member' => 'Ej medlem',
            'aspirant' => 'Aspirant',
            'follow_up' => 'Uppföljning',
            'member_ship' => 'Medlemsfartyg',
            'closed' => 'Avslutad',
        );
    }

    public static function membership_status(int $application_id): string
    {
        $status = (string) get_post_meta($application_id, '_ssf_membership_status', true);
        return isset(self::membership_statuses()[$status]) ? $status : 'not_member';
    }

    public static function membership_status_label(string $status): string
    {
        return self::membership_statuses()[$status] ?? $status;
    }

    public static function status(int $application_id): string
    {
        $status = (string) get_post_meta($application_id, '_ssf_process_status', true);
        return isset(self::statuses()[$status]) ? $status : 'submitted';
    }

    public static function status_label(string $status): string
    {
        return self::statuses()[$status]['label'] ?? $status;
    }

    public static function data(int $application_id): array
    {
        $data = (array) get_post_meta($application_id, '_ssf_application_data', true);
        $ship_id = (int) get_post_meta($application_id, '_ssf_linked_ship_id', true);
        if ($ship_id && class_exists('SSF_Medlemsfartyg_Profile')) {
            $data = array_merge(SSF_Medlemsfartyg_Profile::legacy_application_data($ship_id), $data);
        }
        $route = (string) get_post_meta($application_id, '_ssf_application_route', true);
        if ($route) {
            $data['application_route'] = $route;
            $data['application_path'] = class_exists('SSF_Medlemsfartyg_Profile') ? SSF_Medlemsfartyg_Profile::route_label($route) : $route;
        }
        return $data;
    }

    public static function application_number(): string
    {
        $number = (int) get_option('ssf_medlemsprocess_sequence', 0) + 1;
        update_option('ssf_medlemsprocess_sequence', $number, false);
        return sprintf('SSF-%s-%04d', wp_date('Y'), $number);
    }

    public static function create(array $data, array $attachments = array()): array
    {
        $number = self::application_number();
        $profile_data = (array) ($data['vessel_profile'] ?? array());
        unset($data['vessel_profile']);
        $route = sanitize_key((string) ($data['application_route'] ?? ''));
        $ship_name = sanitize_text_field($profile_data['post_title'] ?? $data['ship_name'] ?? 'Namnlöst fartyg');
        $data['ship_name'] = $ship_name;
        $data['application_path'] = class_exists('SSF_Medlemsfartyg_Profile') ? SSF_Medlemsfartyg_Profile::route_label($route) : $route;
        $application_id = wp_insert_post(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => $number . ' - ' . $ship_name,
            'post_content' => wp_kses_post($data['ship_description'] ?? ''),
        ), true);
        if (is_wp_error($application_id)) {
            return array('id' => 0, 'token' => '');
        }

        update_post_meta($application_id, '_ssf_application_number', $number);
        update_post_meta($application_id, '_ssf_application_data', $data);
        update_post_meta($application_id, '_ssf_application_route', $route);
        update_post_meta($application_id, '_ssf_application_vessel_snapshot', $profile_data);
        update_post_meta($application_id, '_ssf_application_files', array_map('intval', $attachments));
        update_post_meta($application_id, '_ssf_process_status', 'received');
        update_post_meta($application_id, '_ssf_membership_status', 'not_member');
        update_post_meta($application_id, '_ssf_submitted_at', current_time('mysql'));
        if ($profile_data && class_exists('SSF_Medlemsfartyg_Profile')) {
            $ship_id = SSF_Medlemsfartyg_Profile::create_for_application((int) $application_id, $route, $profile_data, $data);
            if ($ship_id) {
                self::add_history((int) $application_id, 'ship_created', 'Fartygsprofil skapades som utkast och kopplades till ansökan.', false, array('ship_id' => $ship_id));
            }
        }
        self::add_history($application_id, 'submitted', 'Ansökan skickades in.', true);
        return array('id' => (int) $application_id, 'token' => self::issue_token((int) $application_id));
    }

    public static function issue_token(int $application_id): string
    {
        $token = wp_generate_password(48, false, false);
        $settings = SSF_Medlemsprocess_Plugin::settings();
        $old_hash = (string) get_post_meta($application_id, '_ssf_status_token_hash', true);
        $old_expires = (int) get_post_meta($application_id, '_ssf_status_token_expires', true);
        if ($old_hash && $old_expires >= time()) {
            $history = (array) get_post_meta($application_id, '_ssf_status_token_history', true);
            $history[] = array('hash' => $old_hash, 'expires' => $old_expires);
            update_post_meta($application_id, '_ssf_status_token_history', array_slice($history, -10));
        }
        update_post_meta($application_id, '_ssf_status_token_hash', wp_hash_password($token));
        update_post_meta($application_id, '_ssf_status_token_expires', time() + (DAY_IN_SECONDS * max(1, (int) $settings['token_days'])));
        update_post_meta($application_id, '_ssf_status_token_revoked', '0');
        return $token;
    }

    public static function find_by_token(string $token): int
    {
        if (strlen($token) < 24) {
            return 0;
        }
        $ids = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => 500,
            'fields' => 'ids',
            'meta_key' => '_ssf_status_token_hash',
        ));
        foreach ($ids as $application_id) {
            $hash = (string) get_post_meta($application_id, '_ssf_status_token_hash', true);
            $expires = (int) get_post_meta($application_id, '_ssf_status_token_expires', true);
            if ('1' !== get_post_meta($application_id, '_ssf_status_token_revoked', true) && $expires >= time() && $hash && wp_check_password($token, $hash, $application_id)) {
                return (int) $application_id;
            }
            if ('1' !== get_post_meta($application_id, '_ssf_status_token_revoked', true)) {
                foreach ((array) get_post_meta($application_id, '_ssf_status_token_history', true) as $previous) {
                    if ((int) ($previous['expires'] ?? 0) >= time() && ! empty($previous['hash']) && wp_check_password($token, (string) $previous['hash'], $application_id)) {
                        return (int) $application_id;
                    }
                }
            }
        }
        return 0;
    }

    public static function status_link(string $token): string
    {
        return SSF_Medlemsprocess_Plugin::page_url('ansokan_status', array('token' => rawurlencode($token)));
    }

    public static function admin_url(int $application_id): string
    {
        $post = get_post($application_id);
        if (! $post || self::POST_TYPE !== $post->post_type) {
            return '';
        }

        $url = get_edit_post_link($application_id, '');
        return $url ? (string) $url : admin_url('post.php?post=' . (int) $application_id . '&action=edit');
    }

    public static function add_history(int $application_id, string $type, string $message, bool $public = false, array $extra = array()): void
    {
        $history = (array) get_post_meta($application_id, '_ssf_application_history', true);
        $extra['source'] = sanitize_key((string) ($extra['source'] ?? (is_admin() ? 'wordpress_admin' : 'system')));
        $history[] = array_merge(array(
            'time' => current_time('mysql'),
            'changed_at' => current_time('mysql'),
            'author' => get_current_user_id(),
            'actor_if_known' => get_current_user_id(),
            'type' => sanitize_key($type),
            'message' => sanitize_textarea_field($message),
            'public' => $public,
            'source' => $extra['source'],
        ), $extra);
        update_post_meta($application_id, '_ssf_application_history', array_slice($history, -250));
        update_post_meta($application_id, '_ssf_last_activity', current_time('mysql'));
    }

    public static function transition(int $application_id, string $status, string $message = '', bool $notify = true, string $source = 'wordpress_admin', string $decision_date = ''): bool
    {
        if (! isset(self::workflow_statuses()[$status])) {
            return false;
        }
        $old_status = self::status($application_id);
        if ($old_status === $status) {
            if ('approved_aspirant' === $status && 'not_member' === self::membership_status($application_id) && self::valid_date($decision_date)) {
                self::start_aspirant_period($application_id, $decision_date, $source);
                if ($notify) {
                    SSF_Medlemsprocess_Plugin::instance()->emails->send_status_email($application_id, $status, $message);
                }
            }
            if ($message) {
                self::add_history($application_id, 'message', $message, true);
            }
            return true;
        }
        if (! self::can_transition($old_status, $status)) {
            self::add_history($application_id, 'workflow_warning', sprintf('Ogiltig statusövergång från %s till %s avvisades.', self::status_label($old_status), self::status_label($status)), false, array('source' => $source, 'from_status' => $old_status, 'to_status' => $status));
            return false;
        }
        if ('approved_aspirant' === $status && ! self::valid_date($decision_date)) {
            update_post_meta($application_id, '_ssf_decision_date_required', '1');
            self::add_history($application_id, 'workflow_warning', 'Godkännande som aspirant väntar eftersom beslutsdatum saknas.', false, array('source' => $source, 'from_status' => $old_status, 'to_status' => $status));
            return false;
        }
        update_post_meta($application_id, '_ssf_process_status', $status);
        update_post_meta($application_id, '_ssf_status_changed_at', current_time('mysql'));
        self::add_history($application_id, 'status', sprintf('Status ändrad från %s till %s.', self::status_label($old_status), self::status_label($status)), false, array('source' => $source, 'from_status' => $old_status, 'to_status' => $status, 'public_comment' => $message));
        if ($message) {
            self::add_history($application_id, 'message', $message, true, array('source' => $source));
        }
        if ('approved_aspirant' === $status) {
            self::start_aspirant_period($application_id, $decision_date, $source);
        } elseif ('rejected' === $status) {
            self::set_membership_status($application_id, 'closed', $source);
        }
        if ($notify) {
            SSF_Medlemsprocess_Plugin::instance()->emails->send_status_email($application_id, $status, $message);
        }
        if ('needs_completion' === $status) {
            update_post_meta($application_id, '_ssf_process_status', 'awaiting_completion');
            self::add_history($application_id, 'status', 'Status ändrad från Begär komplettering till Väntar på komplettering.', false, array('source' => 'system', 'from_status' => 'needs_completion', 'to_status' => 'awaiting_completion'));
        }
        return true;
    }

    public static function set_membership_status(int $application_id, string $status, string $source = 'wordpress_admin'): bool
    {
        if (! isset(self::membership_statuses()[$status])) {
            return false;
        }
        $old = self::membership_status($application_id);
        if ($old === $status) {
            return true;
        }
        $allowed = array(
            'not_member' => array('aspirant', 'closed'),
            'aspirant' => array('follow_up', 'closed'),
            'follow_up' => array('member_ship', 'closed'),
            'member_ship' => array('closed'),
            'closed' => array(),
        );
        if (! in_array($status, $allowed[$old] ?? array(), true)) {
            return false;
        }
        update_post_meta($application_id, '_ssf_membership_status', $status);
        self::add_history($application_id, 'membership_status', sprintf('Medlemsstatus ändrad från %s till %s.', self::membership_status_label($old), self::membership_status_label($status)), false, array('source' => $source, 'from_status' => $old, 'to_status' => $status));
        return true;
    }

    public static function review_due_aspirants(): void
    {
        $ids = get_posts(array('post_type' => self::POST_TYPE, 'post_status' => 'private', 'fields' => 'ids', 'posts_per_page' => 200, 'meta_query' => array(
            array('key' => '_ssf_membership_status', 'value' => 'aspirant'),
            array('key' => '_ssf_aspirant_review_due_at', 'value' => wp_date('Y-m-d'), 'compare' => '<=', 'type' => 'DATE'),
        )));
        foreach ($ids as $application_id) {
            if (self::set_membership_status((int) $application_id, 'follow_up', 'system')) {
                SSF_Medlemsprocess_Plugin::instance()->sharepoint->push_status((int) $application_id);
            }
        }
    }

    private static function start_aspirant_period(int $application_id, string $decision_date, string $source): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $decision_date, wp_timezone());
        if (! $date) {
            return;
        }
        $review = $date->modify('+1 year');
        update_post_meta($application_id, '_ssf_decision_date', $date->format('Y-m-d'));
        update_post_meta($application_id, '_ssf_aspirant_started_at', $date->format('Y-m-d'));
        update_post_meta($application_id, '_ssf_aspirant_review_due_at', $review->format('Y-m-d'));
        delete_post_meta($application_id, '_ssf_decision_date_required');
        self::set_membership_status($application_id, 'aspirant', $source);
    }

    private static function can_transition(string $from, string $to): bool
    {
        return in_array($to, self::allowed_transitions($from), true);
    }

    public static function allowed_transitions(string $from): array
    {
        $allowed = array(
            'received' => array('under_review', 'rejected'),
            'under_review' => array('needs_completion', 'inspection_planned', 'awaiting_decision', 'rejected'),
            'needs_completion' => array('awaiting_completion'),
            'awaiting_completion' => array('under_review', 'rejected'),
            'inspection_planned' => array('inspection_booked', 'needs_completion', 'awaiting_decision', 'rejected'),
            'inspection_booked' => array('needs_completion', 'awaiting_decision', 'rejected'),
            'awaiting_decision' => array('needs_completion', 'approved_aspirant', 'rejected'),
            'approved_aspirant' => array(),
            'rejected' => array(),
        );
        if (! isset(self::workflow_statuses()[$from])) {
            return array_keys(self::workflow_statuses());
        }
        return $allowed[$from] ?? array();
    }

    private static function valid_date(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    public static function create_member_ship(int $application_id): int
    {
        $existing = (int) get_post_meta($application_id, '_ssf_linked_ship_id', true);
        if ($existing && get_post($existing)) {
            update_post_meta($existing, '_ssf_public_visibility', 'review');
            update_post_meta($existing, '_ssf_review_status', 'Medlemskap godkänt - publicering återstår');
            return $existing;
        }
        if (! post_type_exists('medlemsfartyg')) {
            return 0;
        }
        $data = self::data($application_id);
        $ship_id = wp_insert_post(array(
            'post_type' => 'medlemsfartyg',
            'post_status' => 'draft',
            'post_title' => sanitize_text_field($data['ship_name'] ?? get_the_title($application_id)),
            'post_content' => wp_kses_post($data['ship_description'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($data['ship_short_description'] ?? ''),
        ), true);
        if (is_wp_error($ship_id)) {
            return 0;
        }
        $map = array(
            '_ssf_home_port' => 'ship_home_port', '_ssf_registry_number' => 'ship_registry_number',
            '_ssf_rig' => 'ship_rig', '_ssf_build_year' => 'ship_build_year', '_ssf_shipyard' => 'ship_shipyard',
            '_ssf_length' => 'ship_length', '_ssf_beam' => 'ship_beam', '_ssf_draft' => 'ship_draft',
            '_ssf_contact_name' => 'applicant_name', '_ssf_email' => 'applicant_email', '_ssf_phone' => 'applicant_phone',
            '_ssf_organization' => 'applicant_organization', '_ssf_website' => 'applicant_website',
            '_ssf_short_presentation' => 'ship_short_description', '_ssf_history' => 'ship_history', '_ssf_today' => 'ship_current_use',
            '_ssf_show_in_archive' => null,
        );
        foreach ($map as $meta_key => $data_key) {
            update_post_meta($ship_id, $meta_key, null === $data_key ? '1' : (string) ($data[$data_key] ?? ''));
        }
        update_post_meta($ship_id, '_ssf_public_visibility', 'review');
        update_post_meta($ship_id, '_ssf_review_status', 'Medlemskap godkänt - publicering återstår');
        update_post_meta($ship_id, '_ssf_source_application_id', $application_id);
        update_post_meta($ship_id, '_ssf_application_route', (string) get_post_meta($application_id, '_ssf_application_route', true));
        if (! empty($data['ship_type'])) {
            wp_set_object_terms($ship_id, sanitize_text_field($data['ship_type']), 'fartygstyp');
        }
        update_post_meta($application_id, '_ssf_linked_ship_id', (int) $ship_id);
        self::add_history($application_id, 'ship_created', 'Medlemsfartygsprofil skapades.', false, array('ship_id' => (int) $ship_id));
        return (int) $ship_id;
    }
}
