<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

use SSF\MemberPortal\Core\Logger;
use SSF\MemberPortal\Integrations\Microsoft365\SharePoint;

if (! defined('ABSPATH')) {
    exit;
}

final class RegistrationService
{
    public const REGISTERED = 'registered';
    public const CANCELLED = 'cancelled';
    public const WAITLIST = 'waitlist';
    public const AVAILABILITY_DISABLED = 'disabled';
    public const AVAILABILITY_NO_CHOICES = 'no_choices';
    public const AVAILABILITY_NOT_STARTED = 'not_started';
    public const AVAILABILITY_OPEN = 'open';
    public const AVAILABILITY_CLOSED = 'closed';
    public const AVAILABILITY_MEETING_PASSED = 'meeting_passed';
    public const AVAILABILITY_SOLD_OUT = 'sold_out';

    private Module $meetings;
    private CalendarService $calendar;
    private RegistrationMailer $mailer;
    private RegistrationExport $export;
    private SharePoint $sharepoint;

    public function __construct(Module $meetings, CalendarService $calendar, RegistrationMailer $mailer, RegistrationExport $export, SharePoint $sharepoint)
    {
        $this->meetings = $meetings;
        $this->calendar = $calendar;
        $this->mailer = $mailer;
        $this->export = $export;
        $this->sharepoint = $sharepoint;
        add_action('ssf_member_portal_sync_meeting_registrations', array($this, 'sync_meeting'), 10, 2);
        add_action('ssf_member_portal_annual_meeting_retention', array($this, 'run_retention'));
    }

    /**
     * WordPress is saved before mail and SharePoint work. Callers receive a WP_Error only for validation or persistence errors.
     */
    public function submit(array $input, ?string $token = null)
    {
        $meeting_post = $this->meetings->active();
        if (! $meeting_post || 'publish' !== $meeting_post->post_status) {
            return new \WP_Error('annual_meeting_missing', __('Det finns ingen aktiv middag eller aktivitet att anmäla sig till.', 'ssf-member-portal'));
        }
        $meeting = $this->meetings->data($meeting_post->ID);
        $existing = $token ? $this->find_by_token($token) : null;
        if ($existing && (int) $existing->post_parent !== (int) $meeting['id']) {
            return new \WP_Error('annual_meeting_token', __('Den personliga länken hör inte till detta årsmöte.', 'ssf-member-portal'));
        }

        $exclude_id = $existing ? (int) $existing->ID : 0;
        $lock = $this->acquire_registration_lock((int) $meeting['id']);
        if (is_wp_error($lock)) {
            return $lock;
        }

        try {
            $availability = $this->registration_state($meeting, $meeting_post, $exclude_id);
            if (! $existing && empty($availability['can_register'])) {
                return $this->availability_error($availability, (int) $meeting['id']);
            }
            if ($existing && (empty($meeting['allow_edits']) || empty($availability['can_register']))) {
                return new \WP_Error('annual_meeting_edits_closed', __('Ändring och avbokning är stängd för denna anmälan.', 'ssf-member-portal'));
            }

            $data = $this->validate($meeting, $input, $exclude_id);
            if (is_wp_error($data)) {
                return $data;
            }

            $status = $this->registration_status($meeting, $data, $exclude_id);
            if (is_wp_error($status)) {
                return $status;
            }

            $now = $this->now();
            $post_data = array(
                'post_type' => RegistrationPostType::POST_TYPE,
                'post_status' => 'private',
                'post_parent' => (int) $meeting['id'],
                'post_title' => trim($data['first_name'] . ' ' . $data['last_name']),
            );
            if ($existing) {
                $post_data['ID'] = $existing->ID;
                $post_id = wp_update_post($post_data, true);
            } else {
                $post_id = wp_insert_post($post_data, true);
            }
            if (is_wp_error($post_id) || ! $post_id) {
                return is_wp_error($post_id) ? $post_id : new \WP_Error('annual_meeting_save', __('Anmälan kunde inte sparas.', 'ssf-member-portal'));
            }

            $is_new = ! $existing;
            if ($is_new) {
                $token = $this->token();
                update_post_meta($post_id, '_ssf_am_token_hash', hash('sha256', $token));
                update_post_meta($post_id, '_ssf_am_registration_id', $this->registration_id($meeting, (int) $post_id));
                update_post_meta($post_id, '_ssf_am_submitted_at', $now);
            }
            foreach ($data as $key => $value) {
                update_post_meta($post_id, '_ssf_am_' . $key, $value);
            }
            update_post_meta($post_id, '_ssf_am_annual_meeting_id', (int) $meeting['id']);
            update_post_meta($post_id, '_ssf_am_status', $status);
            update_post_meta($post_id, '_ssf_am_updated_at', $now);
            update_post_meta($post_id, '_ssf_am_sharepoint_sync_status', 'pending');
            delete_post_meta($post_id, '_ssf_am_sharepoint_last_error');
        } finally {
            $this->release_registration_lock((int) $meeting['id'], (string) $lock);
        }

        $registration = $this->details((int) $post_id, $meeting);
        $manage_url = $this->meetings->registration_url(array('token' => rawurlencode((string) $token)));
        $calendar_url = $this->meetings->calendar_url((int) $meeting['id']);
        $this->mailer->confirmation($meeting, $registration, $manage_url, $calendar_url);
        if ($is_new) {
            $this->mailer->notification($meeting, $registration);
        }
        $this->queue_sync((int) $meeting['id']);

        return array('registration' => $registration, 'token' => $token, 'meeting' => $meeting);
    }

    public function find_by_token(string $token): ?\WP_Post
    {
        $token = trim($token);
        if (strlen($token) < 32 || strlen($token) > 128) {
            return null;
        }
        $registrations = get_posts(array(
            'post_type' => RegistrationPostType::POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => 1,
            'meta_key' => '_ssf_am_token_hash',
            'meta_value' => hash('sha256', $token),
        ));
        return $registrations ? $registrations[0] : null;
    }

    public function details(int $registration_id, ?array $meeting = null): array
    {
        $post = get_post($registration_id);
        $meeting = $meeting ?: ($post ? $this->meetings->data((int) $post->post_parent) : array());
        $get = static function (string $key, $default = '') use ($registration_id) {
            $value = get_post_meta($registration_id, '_ssf_am_' . $key, true);
            return '' === $value || null === $value ? $default : $value;
        };
        $program = (array) $get('program', array());
        $labels = array();
        foreach ($this->meetings->registration_choices($meeting) as $item) {
            if (! empty($program[$item['key']])) {
                $labels[] = (string) $item['title'];
            }
        }
        $relationship = (string) $get('relationship');
        return array(
            'id' => $registration_id,
            'registration_id' => (string) $get('registration_id'),
            'first_name' => (string) $get('first_name'),
            'last_name' => (string) $get('last_name'),
            'email' => (string) $get('email'),
            'phone' => (string) $get('phone'),
            'relationship' => $relationship,
            'relationship_label' => $this->relationship_label($relationship),
            'represented_vessels' => (array) $get('represented_vessels', array()),
            'associated_vessels' => (array) $get('associated_vessels', array()),
            'has_associated_vessels' => (bool) $get('has_associated_vessels', 0),
            'program' => $program,
            'program_labels' => $labels,
            'food' => (array) $get('food', array()),
            'food_note' => (string) $get('food_note'),
            'answers' => (array) $get('answers', array()),
            'status' => (string) $get('status', self::REGISTERED),
            'status_label' => $this->status_label((string) $get('status', self::REGISTERED)),
            'submitted_at' => (int) $get('submitted_at', 0),
            'updated_at' => (int) $get('updated_at', 0),
            'sharepoint_sync_status' => (string) $get('sharepoint_sync_status', 'pending'),
            'sharepoint_last_sync' => (string) $get('sharepoint_last_sync'),
            'sharepoint_last_error' => (string) $get('sharepoint_last_error'),
        );
    }

    public function registrations(int $meeting_id, array $filters = array()): array
    {
        $meta_query = array();
        foreach (array('relationship', 'status', 'sharepoint_sync_status') as $filter) {
            if (! empty($filters[$filter])) {
                $meta_query[] = array('key' => '_ssf_am_' . $filter, 'value' => sanitize_text_field((string) $filters[$filter]));
            }
        }
        return get_posts(array(
            'post_type' => RegistrationPostType::POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => -1,
            'post_parent' => $meeting_id,
            'orderby' => 'meta_value_num',
            'meta_key' => '_ssf_am_submitted_at',
            'order' => 'ASC',
            'meta_query' => $meta_query,
        ));
    }

    public function cancel(\WP_Post $registration, string $token)
    {
        $meeting_post = get_post((int) $registration->post_parent);
        if (! $meeting_post || Module::POST_TYPE !== $meeting_post->post_type) {
            return new \WP_Error('annual_meeting_cancel', __('Den här anmälan kan inte längre avbokas.', 'ssf-member-portal'));
        }
        $meeting = $this->meetings->data((int) $registration->post_parent);
        if (empty($this->registration_state($meeting, $meeting_post, (int) $registration->ID)['can_register']) || empty($meeting['allow_edits']) || ! hash_equals((string) get_post_meta($registration->ID, '_ssf_am_token_hash', true), hash('sha256', $token))) {
            return new \WP_Error('annual_meeting_cancel', __('Den här anmälan kan inte längre avbokas.', 'ssf-member-portal'));
        }
        update_post_meta($registration->ID, '_ssf_am_status', self::CANCELLED);
        update_post_meta($registration->ID, '_ssf_am_updated_at', $this->now());
        update_post_meta($registration->ID, '_ssf_am_sharepoint_sync_status', 'pending');
        $this->queue_sync((int) $meeting['id']);
        return true;
    }

    public function resend_confirmation(int $registration_id): bool
    {
        $post = get_post($registration_id);
        if (! $post || RegistrationPostType::POST_TYPE !== $post->post_type) {
            return false;
        }
        $meeting = $this->meetings->data((int) $post->post_parent);
        $token = $this->token();
        update_post_meta($post->ID, '_ssf_am_token_hash', hash('sha256', $token));
        $registration = $this->details($post->ID, $meeting);
        return $this->mailer->confirmation(
            $meeting,
            $registration,
            $this->meetings->registration_url(array('token' => rawurlencode($token))),
            $this->meetings->calendar_url((int) $meeting['id'])
        );
    }

    public function selection_counts(int $meeting_id, int $exclude_id = 0): array
    {
        $counts = array();
        foreach ($this->registrations($meeting_id, array('status' => self::REGISTERED)) as $registration) {
            if ((int) $registration->ID === $exclude_id) {
                continue;
            }
            foreach ((array) get_post_meta($registration->ID, '_ssf_am_program', true) as $key => $selected) {
                if ($selected) {
                    $counts[(string) $key] = ($counts[(string) $key] ?? 0) + 1;
                }
            }
        }
        return $counts;
    }

    public function registration_state(array $meeting, ?\WP_Post $meeting_post = null, int $exclude_id = 0): array
    {
        $now = $this->now();
        $choices = $this->meetings->registration_choices($meeting);
        $choice_states = array();
        foreach ($choices as $choice) {
            $choice_key = (string) ($choice['key'] ?? '');
            if (! $choice_key) {
                continue;
            }
            $choice_states[$choice_key] = $this->choice_state($meeting, $choice, $exclude_id);
        }
        $available = array_filter($choice_states, static function (array $state): bool {
            return ! empty($state['available']);
        });
        $meeting_end = $this->meeting_end_at($meeting);
        $open_at = (int) ($meeting['registration_opens_at'] ?? 0);
        $close_at = (int) ($meeting['registration_closes_at'] ?? 0);

        $status = self::AVAILABILITY_OPEN;
        if ($meeting_post && 'publish' !== $meeting_post->post_status) {
            $status = self::AVAILABILITY_DISABLED;
        } elseif ($meeting_post && (int) $meeting_post->ID !== (int) get_option('ssf_member_portal_active_meeting_id', 0)) {
            $status = self::AVAILABILITY_DISABLED;
        } elseif (! $this->feature_enabled('annual_meetings') || ! $this->feature_enabled('annual_meeting_registration') || empty($meeting['registration_open'])) {
            $status = self::AVAILABILITY_DISABLED;
        } elseif (! $choices) {
            $status = self::AVAILABILITY_NO_CHOICES;
        } elseif ($meeting_end && $now > $meeting_end) {
            $status = self::AVAILABILITY_MEETING_PASSED;
        } elseif ($available) {
            $close_times = array_filter(array_map(static function (array $state): int {
                return ! empty($state['available']) ? (int) ($state['closes_at'] ?? 0) : 0;
            }, $choice_states));
            $close_at = $close_times ? min($close_times) : 0;
        } else {
            $statuses = array_values(array_unique(array_map(static function (array $state): string {
                return (string) ($state['status'] ?? '');
            }, $choice_states)));
            if (in_array(self::AVAILABILITY_NOT_STARTED, $statuses, true)) {
                $status = self::AVAILABILITY_NOT_STARTED;
                $open_times = array_filter(array_map(static function (array $state): int {
                    return (int) ($state['opens_at'] ?? 0);
                }, $choice_states));
                $open_at = $open_times ? min($open_times) : $open_at;
            } elseif ($statuses && count(array_diff($statuses, array(self::AVAILABILITY_SOLD_OUT))) === 0) {
                $status = self::AVAILABILITY_SOLD_OUT;
            } else {
                $status = self::AVAILABILITY_CLOSED;
                $close_times = array_filter(array_map(static function (array $state): int {
                    return (int) ($state['closes_at'] ?? 0);
                }, $choice_states));
                $close_at = $close_times ? max($close_times) : $close_at;
            }
        }

        return array(
            'status' => $status,
            'label' => $this->availability_label($status),
            'message' => $this->availability_message($status, $open_at, $close_at, $meeting_end),
            'can_register' => self::AVAILABILITY_OPEN === $status,
            'opens_at' => $open_at,
            'closes_at' => $close_at,
            'meeting_end_at' => $meeting_end,
            'choices' => $choices,
            'choice_states' => $choice_states,
            'available_choices' => $available,
        );
    }

    public function choice_state(array $meeting, array $choice, int $exclude_id = 0): array
    {
        $counts = $this->selection_counts((int) $meeting['id'], $exclude_id);
        $capacity = max(0, (int) ($choice['capacity'] ?? 0));
        $count = (int) ($counts[(string) ($choice['key'] ?? '')] ?? 0);
        $now = $this->now();
        $open_at = $this->choice_open_at($meeting, $choice);
        $close_at = $this->choice_close_at($meeting, $choice);
        $meeting_end = $this->meeting_end_at($meeting);
        $full = $capacity > 0 && $count >= $capacity;
        $status = self::AVAILABILITY_OPEN;

        if (empty($meeting['registration_open'])) {
            $status = self::AVAILABILITY_DISABLED;
        } elseif ($meeting_end && $now > $meeting_end) {
            $status = self::AVAILABILITY_MEETING_PASSED;
        } elseif ($open_at && $now < $open_at) {
            $status = self::AVAILABILITY_NOT_STARTED;
        } elseif (! empty($choice['closed'])) {
            $status = self::AVAILABILITY_CLOSED;
        } elseif ($close_at && $now > $close_at) {
            $status = self::AVAILABILITY_CLOSED;
        } elseif ($full) {
            $status = self::AVAILABILITY_SOLD_OUT;
        }

        return array(
            'count' => $count,
            'capacity' => $capacity,
            'remaining' => $capacity ? max(0, $capacity - $count) : null,
            'full' => $full,
            'closed' => in_array($status, array(self::AVAILABILITY_CLOSED, self::AVAILABILITY_MEETING_PASSED), true),
            'registration_open' => self::AVAILABILITY_OPEN === $status,
            'available' => self::AVAILABILITY_OPEN === $status,
            'status' => $status,
            'label' => $this->availability_label($status),
            'message' => $this->availability_message($status, $open_at, $close_at, $meeting_end),
            'opens_at' => $open_at,
            'closes_at' => $close_at,
        );
    }

    public function queue_sync(int $meeting_id, int $attempt = 0): void
    {
        if (! wp_next_scheduled('ssf_member_portal_sync_meeting_registrations', array($meeting_id, $attempt))) {
            wp_schedule_single_event($this->now() + 15, 'ssf_member_portal_sync_meeting_registrations', array($meeting_id, $attempt));
        }
    }

    public function sync_meeting(int $meeting_id, int $attempt = 0): void
    {
        $meeting = $this->meetings->data($meeting_id);
        $registrations = $this->registrations($meeting_id);
        if (! $registrations) {
            return;
        }
        if (! $this->sharepoint->enabled()) {
            $this->mark_sync($registrations, 'error', __('SharePoint är inte konfigurerat. Anmälan är sparad i WordPress.', 'ssf-member-portal'));
            return;
        }
        $file = $this->export->create_xlsx($meeting, $registrations);
        if (is_wp_error($file)) {
            $this->sync_failed($meeting_id, $registrations, $file->get_error_message(), $attempt);
            return;
        }
        $content = file_get_contents($file);
        wp_delete_file($file);
        if (false === $content) {
            $this->sync_failed($meeting_id, $registrations, __('Excel-filen kunde inte läsas före uppladdning.', 'ssf-member-portal'), $attempt);
            return;
        }
        $year = max(2000, (int) ($meeting['sharepoint_year'] ?: $meeting['year']));
        $result = $this->sharepoint->upload_registration_excel($year, $this->export->sharepoint_filename($year), $content);
        if (is_wp_error($result)) {
            $this->sync_failed($meeting_id, $registrations, $result->get_error_message(), $attempt);
            return;
        }
        $now = gmdate('c');
        $this->mark_sync($registrations, 'synced', '', $now);
        update_post_meta($meeting_id, '_ssf_am_sharepoint_excel_url', esc_url_raw((string) ($result['web_url'] ?? '')));
        update_post_meta($meeting_id, '_ssf_am_sharepoint_excel_synced_at', $now);
    }

    public function calendar_for_meeting(int $meeting_id): string
    {
        return $this->calendar->ics($this->meetings->data($meeting_id));
    }

    public function calendar(\WP_Post $registration): string
    {
        return $this->calendar_for_meeting((int) $registration->post_parent);
    }

    public function run_retention(): void
    {
        foreach ($this->meetings->all() as $post) {
            $meeting = $this->meetings->data($post->ID);
            $reference = (int) ($meeting['end_at'] ?: $meeting['start_at']);
            if (! $reference) {
                continue;
            }
            $cutoff = strtotime('+' . max(1, (int) $meeting['retention_months']) . ' months', $reference);
            if ($cutoff > $this->now()) {
                continue;
            }
            foreach ($this->registrations($post->ID) as $registration) {
                if (get_post_meta($registration->ID, '_ssf_am_anonymised_at', true)) {
                    continue;
                }
                foreach (array('email', 'phone', 'food', 'food_note', 'answers', 'represented_vessels', 'associated_vessels') as $key) {
                    delete_post_meta($registration->ID, '_ssf_am_' . $key);
                }
                update_post_meta($registration->ID, '_ssf_am_first_name', __('Anonymiserad', 'ssf-member-portal'));
                update_post_meta($registration->ID, '_ssf_am_last_name', __('deltagare', 'ssf-member-portal'));
                update_post_meta($registration->ID, '_ssf_am_anonymised_at', gmdate('c'));
            }
            $this->queue_sync($post->ID);
        }
    }

    public function status_label(string $status): string
    {
        $labels = array(
            self::REGISTERED => __('Bekräftad', 'ssf-member-portal'),
            self::CANCELLED => __('Avbokad', 'ssf-member-portal'),
            self::WAITLIST => __('Reservlista', 'ssf-member-portal'),
        );
        return $labels[$status] ?? $status;
    }

    private function validate(array $meeting, array $input, int $exclude_id)
    {
        $data = array(
            'first_name' => sanitize_text_field((string) ($input['first_name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($input['last_name'] ?? '')),
            'email' => sanitize_email((string) ($input['email'] ?? '')),
            'phone' => sanitize_text_field((string) ($input['phone'] ?? '')),
            'relationship' => sanitize_key((string) ($input['relationship'] ?? '')),
            'represented_vessels' => $this->vessels((array) ($input['represented_vessels'] ?? array())),
            'has_associated_vessels' => ! empty($input['has_associated_vessels']) ? 1 : 0,
            'associated_vessels' => $this->vessels((array) ($input['associated_vessels'] ?? array())),
            'program' => array(),
            'food' => array(),
            'food_note' => sanitize_textarea_field((string) ($input['food_note'] ?? '')),
            'answers' => array(),
        );
        if (! $data['first_name'] || ! $data['last_name'] || ! is_email($data['email'])) {
            return new \WP_Error('annual_meeting_required', __('Fyll i förnamn, efternamn och en giltig e-postadress.', 'ssf-member-portal'));
        }
        $valid_relationships = array('representative', 'supporter');
        if (! empty($meeting['allow_guest'])) {
            $valid_relationships[] = 'guest';
        }
        if (! in_array($data['relationship'], $valid_relationships, true)) {
            return new \WP_Error('annual_meeting_relationship', __('Välj din relation till SSF.', 'ssf-member-portal'));
        }
        if ('representative' === $data['relationship'] && ! $data['represented_vessels']) {
            return new \WP_Error('annual_meeting_vessel', __('Ange minst ett fartyg du är ombud för.', 'ssf-member-portal'));
        }
        if (! $data['has_associated_vessels']) {
            $data['associated_vessels'] = array();
        }
        $existing = get_posts(array(
            'post_type' => RegistrationPostType::POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => 2,
            'post_parent' => (int) $meeting['id'],
            'meta_key' => '_ssf_am_email',
            'meta_value' => $data['email'],
        ));
        foreach ($existing as $duplicate) {
            if ((int) $duplicate->ID !== $exclude_id && self::CANCELLED !== get_post_meta($duplicate->ID, '_ssf_am_status', true)) {
                return new \WP_Error('annual_meeting_duplicate', __('Det finns redan en anmälan med den här e-postadressen. Använd din personliga länk för att ändra den.', 'ssf-member-portal'));
            }
        }
        $submitted_program = (array) ($input['program'] ?? array());
        $existing_program = $exclude_id ? (array) get_post_meta($exclude_id, '_ssf_am_program', true) : array();
        foreach ($this->meetings->registration_choices($meeting) as $item) {
            if (empty($item['key'])) {
                continue;
            }
            $selected = ! empty($submitted_program[$item['key']]);
            $was_selected = ! empty($existing_program[$item['key']]);
            $state = $this->choice_state($meeting, $item, $exclude_id);
            if (empty($state['available']) && ! $was_selected) {
                if ($was_selected) {
                    $data['program'][$item['key']] = 1;
                }
                if ($selected) {
                    return new \WP_Error(
                        ! empty($state['full']) ? 'annual_meeting_choice_full' : 'annual_meeting_choice_closed',
                        $this->choice_error_message($state, (string) $item['title'])
                    );
                }
                continue;
            }
            if (empty($item['optional']) && ! $selected) {
                return new \WP_Error('annual_meeting_program_required', sprintf(__('Välj %s.', 'ssf-member-portal'), $item['title']));
            }
            if ($selected) {
                $data['program'][$item['key']] = 1;
            }
        }
        if (! $data['program']) {
            return new \WP_Error('annual_meeting_choice_required', __('Välj minst en middag eller aktivitet.', 'ssf-member-portal'));
        }
        if ($this->selection_uses_food($meeting, $data['program'])) {
            $selected_food = (array) ($input['food'] ?? array());
            foreach ((array) $meeting['food_options'] as $option) {
                if (! empty($selected_food[$option]) || in_array($option, $selected_food, true)) {
                    $data['food'][] = $option;
                }
            }
        } else {
            $data['food_note'] = '';
        }
        foreach ((array) $meeting['questions'] as $question) {
            if (empty($question['visible']) || 'info' === $question['type'] || empty($question['key'])) {
                continue;
            }
            $value = $input['answers'][$question['key']] ?? '';
            $value = $this->answer($question, $value);
            if (! empty($question['required']) && ('' === $value || array() === $value)) {
                return new \WP_Error('annual_meeting_question_required', sprintf(__('Besvara frågan: %s', 'ssf-member-portal'), $question['title']));
            }
            $data['answers'][$question['key']] = $value;
        }
        return $data;
    }

    private function registration_status(array $meeting, array $data, int $exclude_id)
    {
        $active = $this->registrations((int) $meeting['id'], array('status' => self::REGISTERED));
        $active = array_filter($active, static function (\WP_Post $post) use ($exclude_id): bool { return (int) $post->ID !== $exclude_id; });
        $full = ! empty($meeting['capacity']) && count($active) >= (int) $meeting['capacity'];
        if (! $full) {
            return self::REGISTERED;
        }
        return ! empty($meeting['waitlist']) ? self::WAITLIST : new \WP_Error('annual_meeting_full', __('Tyvärr är det fullbokat.', 'ssf-member-portal'));
    }

    private function answer(array $question, $value)
    {
        $type = $question['type'] ?? 'text';
        $options = (array) ($question['options'] ?? array());
        if ('multiple' === $type || 'checkbox' === $type) {
            $values = array_map('sanitize_text_field', (array) $value);
            return array_values(array_intersect($values, $options));
        }
        if ('yes_no' === $type) {
            return in_array($value, array('yes', 'no'), true) ? $value : '';
        }
        if (in_array($type, array('single', 'date'), true)) {
            $value = sanitize_text_field((string) $value);
            return 'single' === $type ? (in_array($value, $options, true) ? $value : '') : $value;
        }
        return 'textarea' === $type ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value);
    }

    private function vessels(array $values): array
    {
        $values = array_slice($values, 0, 10);
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', $values))));
    }

    private function registration_id(array $meeting, int $post_id): string
    {
        return sprintf('%d-REG-%04d', (int) $meeting['year'], $post_id);
    }

    private function token(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Exception $exception) {
            return wp_generate_password(64, false, false);
        }
    }

    private function choice_open_at(array $meeting, array $choice): int
    {
        return (int) ($meeting['registration_opens_at'] ?? 0);
    }

    private function choice_close_at(array $meeting, array $choice): int
    {
        return (int) (($meeting['registration_closes_at'] ?? 0) ?: $this->meeting_end_at($meeting));
    }

    private function meeting_end_at(array $meeting): int
    {
        return (int) (($meeting['end_at'] ?? 0) ?: ($meeting['meeting_end_at'] ?? 0) ?: ($meeting['meeting_start_at'] ?? 0) ?: ($meeting['start_at'] ?? 0));
    }

    private function availability_label(string $status): string
    {
        $labels = array(
            self::AVAILABILITY_DISABLED => __('Avstängd', 'ssf-member-portal'),
            self::AVAILABILITY_NO_CHOICES => __('Ingen anmälan krävs', 'ssf-member-portal'),
            self::AVAILABILITY_NOT_STARTED => __('Inte öppnad', 'ssf-member-portal'),
            self::AVAILABILITY_OPEN => __('Öppen', 'ssf-member-portal'),
            self::AVAILABILITY_CLOSED => __('Stängd', 'ssf-member-portal'),
            self::AVAILABILITY_MEETING_PASSED => __('Årsmötet har passerat', 'ssf-member-portal'),
            self::AVAILABILITY_SOLD_OUT => __('Fullbokad', 'ssf-member-portal'),
        );
        return $labels[$status] ?? $status;
    }

    private function availability_message(string $status, int $opens_at = 0, int $closes_at = 0, int $meeting_end = 0): string
    {
        switch ($status) {
            case self::AVAILABILITY_NO_CHOICES:
                return __('Ingen anmälan krävs till årsmötet.', 'ssf-member-portal');
            case self::AVAILABILITY_NOT_STARTED:
                return $opens_at ? sprintf(__('Anmälan öppnar %s.', 'ssf-member-portal'), $this->date_text($opens_at)) : __('Anmälan är inte öppen ännu.', 'ssf-member-portal');
            case self::AVAILABILITY_OPEN:
                return $closes_at ? sprintf(__('Anmälan är öppen till %s.', 'ssf-member-portal'), $this->date_text($closes_at)) : __('Anmälan är öppen.', 'ssf-member-portal');
            case self::AVAILABILITY_CLOSED:
                return $closes_at ? sprintf(__('Anmälan stängde %s.', 'ssf-member-portal'), $this->date_text($closes_at)) : __('Anmälan är stängd.', 'ssf-member-portal');
            case self::AVAILABILITY_MEETING_PASSED:
                return $meeting_end ? sprintf(__('Årsmöteshelgen passerade %s.', 'ssf-member-portal'), $this->date_text($meeting_end)) : __('Årsmöteshelgen har passerat.', 'ssf-member-portal');
            case self::AVAILABILITY_SOLD_OUT:
                return __('Middag och aktiviteter är fullbokade.', 'ssf-member-portal');
            case self::AVAILABILITY_DISABLED:
            default:
                return __('Anmälan är inte aktiverad just nu.', 'ssf-member-portal');
        }
    }

    private function availability_error(array $availability, int $meeting_id): \WP_Error
    {
        $status = (string) ($availability['status'] ?? self::AVAILABILITY_DISABLED);
        Logger::add('annual_meeting_registration_rejected', array('annual_meeting' => $meeting_id, 'status' => $status));
        return new \WP_Error('annual_meeting_' . $status, (string) ($availability['message'] ?? __('Anmälan är inte öppen just nu.', 'ssf-member-portal')));
    }

    private function choice_error_message(array $state, string $title): string
    {
        switch ((string) ($state['status'] ?? '')) {
            case self::AVAILABILITY_SOLD_OUT:
                return sprintf(__('Tyvärr blev %s fullbokad precis innan din anmälan registrerades.', 'ssf-member-portal'), $title);
            case self::AVAILABILITY_NOT_STARTED:
                return sprintf(__('Anmälan till %s är inte öppen ännu.', 'ssf-member-portal'), $title);
            case self::AVAILABILITY_MEETING_PASSED:
                return __('Årsmöteshelgen har passerat och ny anmälan kan inte göras.', 'ssf-member-portal');
            default:
                return sprintf(__('Anmälan till %s är stängd.', 'ssf-member-portal'), $title);
        }
    }

    private function selection_uses_food(array $meeting, array $program): bool
    {
        foreach ($this->meetings->registration_choices($meeting) as $choice) {
            $choice_key = (string) ($choice['key'] ?? '');
            if ($choice_key && ! empty($program[$choice_key]) && ! empty($choice['food'])) {
                return true;
            }
        }
        return false;
    }

    private function date_text(int $timestamp): string
    {
        return wp_date('j F Y H:i', $timestamp, wp_timezone());
    }

    private function feature_enabled(string $feature): bool
    {
        return ! class_exists('SSF_Feature_Manager') || \SSF_Feature_Manager::can_access($feature);
    }

    private function now(): int
    {
        return current_datetime()->getTimestamp();
    }

    private function acquire_registration_lock(int $meeting_id)
    {
        $key = 'ssf_am_registration_lock_' . $meeting_id;
        $token = wp_generate_uuid4();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $expires = $this->now() + 15;
            if (add_option($key, array('token' => $token, 'expires' => $expires), '', 'no')) {
                return $token;
            }
            $lock = (array) get_option($key, array());
            if (! empty($lock['expires']) && (int) $lock['expires'] < $this->now()) {
                delete_option($key);
                continue;
            }
            usleep(200000);
        }
        return new \WP_Error('annual_meeting_registration_busy', __('Anmälan behandlas just nu. Försök igen om en liten stund.', 'ssf-member-portal'));
    }

    private function release_registration_lock(int $meeting_id, string $token): void
    {
        $key = 'ssf_am_registration_lock_' . $meeting_id;
        $lock = (array) get_option($key, array());
        if (! empty($lock['token']) && hash_equals((string) $lock['token'], $token)) {
            delete_option($key);
        }
    }

    private function relationship_label(string $relationship): string
    {
        $labels = array('representative' => __('Fartygsombud', 'ssf-member-portal'), 'supporter' => __('Stödmedlem', 'ssf-member-portal'), 'guest' => __('Annan/inbjuden deltagare', 'ssf-member-portal'));
        return $labels[$relationship] ?? $relationship;
    }

    private function mark_sync(array $registrations, string $status, string $error = '', string $synced_at = ''): void
    {
        foreach ($registrations as $registration) {
            update_post_meta($registration->ID, '_ssf_am_sharepoint_sync_status', $status);
            if ($synced_at) {
                update_post_meta($registration->ID, '_ssf_am_sharepoint_last_sync', $synced_at);
                delete_post_meta($registration->ID, '_ssf_am_sharepoint_last_error');
            } elseif ($error) {
                update_post_meta($registration->ID, '_ssf_am_sharepoint_last_error', sanitize_text_field($error));
            }
        }
    }

    private function sync_failed(int $meeting_id, array $registrations, string $error, int $attempt): void
    {
        $this->mark_sync($registrations, 'error', $error);
        if ($attempt >= 4) {
            return;
        }
        $delays = array(300, 1800, 7200, DAY_IN_SECONDS);
        wp_schedule_single_event($this->now() + $delays[$attempt], 'ssf_member_portal_sync_meeting_registrations', array($meeting_id, $attempt + 1));
    }

}
