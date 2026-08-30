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
    }

    public static function statuses(): array
    {
        return array(
            'draft' => array('label' => 'Utkast', 'public' => 'Ansökan har påbörjats.', 'step' => 0),
            'submitted' => array('label' => 'Inskickad', 'public' => 'Din ansökan har skickats in.', 'step' => 1),
            'received' => array('label' => 'Mottagen', 'public' => 'SSF har tagit emot din ansökan.', 'step' => 1),
            'under_review' => array('label' => 'Under granskning', 'public' => 'SSF går igenom uppgifterna i din ansökan.', 'step' => 2),
            'needs_completion' => array('label' => 'Komplettering krävs', 'public' => 'SSF behöver ytterligare uppgifter från dig.', 'step' => 3),
            'completion_submitted' => array('label' => 'Komplettering inskickad', 'public' => 'Din komplettering har skickats till SSF.', 'step' => 3),
            'inspection_planned' => array('label' => 'Inspektion planeras', 'public' => 'SSF planerar nästa steg i granskningen.', 'step' => 4),
            'inspection_booked' => array('label' => 'Inspektion bokad', 'public' => 'En tid har bokats för fortsatt granskning.', 'step' => 4),
            'inspection_completed' => array('label' => 'Inspektion genomförd', 'public' => 'Inspektionsunderlaget är klart.', 'step' => 4),
            'awaiting_decision' => array('label' => 'Väntar på beslut', 'public' => 'Ärendet är komplett och väntar på beslut.', 'step' => 5),
            'approved_aspirant' => array('label' => 'Godkänd som aspirant', 'public' => 'Din ansökan har godkänts som aspirant.', 'step' => 6),
            'approved' => array('label' => 'Godkänd', 'public' => 'Din ansökan har godkänts av Sveriges Segelfartygsförbund.', 'step' => 6),
            'rejected' => array('label' => 'Avslagen', 'public' => 'SSF har fattat beslut om din ansökan.', 'step' => 6),
            'paused' => array('label' => 'Vilande', 'public' => 'Ärendet är tillfälligt pausat.', 'step' => 0),
            'archived' => array('label' => 'Arkiverad', 'public' => 'Ärendet är avslutat och arkiverat.', 'step' => 6),
        );
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
        return (array) get_post_meta($application_id, '_ssf_application_data', true);
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
        $ship_name = sanitize_text_field($data['ship_name'] ?? 'Namnlöst fartyg');
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
        update_post_meta($application_id, '_ssf_application_files', array_map('intval', $attachments));
        update_post_meta($application_id, '_ssf_process_status', 'submitted');
        update_post_meta($application_id, '_ssf_submitted_at', current_time('mysql'));
        self::add_history($application_id, 'submitted', 'Ansökan skickades in.', true);
        return array('id' => (int) $application_id, 'token' => self::issue_token((int) $application_id));
    }

    public static function issue_token(int $application_id): string
    {
        $token = wp_generate_password(48, false, false);
        $settings = SSF_Medlemsprocess_Plugin::settings();
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
        }
        return 0;
    }

    public static function status_link(string $token): string
    {
        return SSF_Medlemsprocess_Plugin::page_url('ansokan_status', array('token' => rawurlencode($token)));
    }

    public static function add_history(int $application_id, string $type, string $message, bool $public = false, array $extra = array()): void
    {
        $history = (array) get_post_meta($application_id, '_ssf_application_history', true);
        $history[] = array_merge(array(
            'time' => current_time('mysql'),
            'author' => get_current_user_id(),
            'type' => sanitize_key($type),
            'message' => sanitize_textarea_field($message),
            'public' => $public,
        ), $extra);
        update_post_meta($application_id, '_ssf_application_history', array_slice($history, -250));
        update_post_meta($application_id, '_ssf_last_activity', current_time('mysql'));
    }

    public static function transition(int $application_id, string $status, string $message = '', bool $notify = true): bool
    {
        if (! isset(self::statuses()[$status])) {
            return false;
        }
        $old_status = self::status($application_id);
        if ($old_status === $status) {
            if ($message) {
                self::add_history($application_id, 'message', $message, true);
            }
            return true;
        }
        update_post_meta($application_id, '_ssf_process_status', $status);
        update_post_meta($application_id, '_ssf_status_changed_at', current_time('mysql'));
        self::add_history($application_id, 'status', sprintf('Status ändrad från %s till %s.', self::status_label($old_status), self::status_label($status)), false);
        if ($message) {
            self::add_history($application_id, 'message', $message, true);
        }
        if ($notify) {
            SSF_Medlemsprocess_Plugin::instance()->emails->send_status_email($application_id, $status, $message);
        }
        return true;
    }

    public static function create_member_ship(int $application_id): int
    {
        $existing = (int) get_post_meta($application_id, '_ssf_linked_ship_id', true);
        if ($existing && get_post($existing)) {
            return $existing;
        }
        if (! post_type_exists('medlemsfartyg')) {
            return 0;
        }
        $data = self::data($application_id);
        $ship_id = wp_insert_post(array(
            'post_type' => 'medlemsfartyg',
            'post_status' => 'publish',
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
        if (! empty($data['ship_type'])) {
            wp_set_object_terms($ship_id, sanitize_text_field($data['ship_type']), 'fartygstyp');
        }
        update_post_meta($application_id, '_ssf_linked_ship_id', (int) $ship_id);
        self::add_history($application_id, 'ship_created', 'Medlemsfartygsprofil skapades.', false, array('ship_id' => (int) $ship_id));
        return (int) $ship_id;
    }
}
