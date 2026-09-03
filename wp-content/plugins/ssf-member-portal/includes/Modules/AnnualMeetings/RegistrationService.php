<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

use SSF\MemberPortal\Integrations\Microsoft365\SharePoint;

if (! defined('ABSPATH')) {
    exit;
}

final class RegistrationService
{
    public const REGISTERED = 'registered';
    public const CANCELLED = 'cancelled';
    public const WAITLIST = 'waitlist';

    private Module $meetings;
    private RegistrationMailer $mailer;
    private RegistrationExport $export;
    private SharePoint $sharepoint;

    public function __construct(Module $meetings, RegistrationMailer $mailer, RegistrationExport $export, SharePoint $sharepoint)
    {
        $this->meetings = $meetings;
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
        if (! $existing && ! $this->meetings->is_registration_open($meeting)) {
            return new \WP_Error('annual_meeting_closed', __('Anmälan är stängd.', 'ssf-member-portal'));
        }
        if ($existing && (! $meeting['allow_edits'] || ! $this->meets_deadline($meeting))) {
            return new \WP_Error('annual_meeting_edits_closed', __('Ändring och avbokning är stängd för denna anmälan.', 'ssf-member-portal'));
        }

        $data = $this->validate($meeting, $input, $existing ? (int) $existing->ID : 0);
        if (is_wp_error($data)) {
            return $data;
        }

        $status = $this->registration_status($meeting, $data, $existing ? (int) $existing->ID : 0);
        if (is_wp_error($status)) {
            return $status;
        }

        $now = time();
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
        $meeting = $this->meetings->data((int) $registration->post_parent);
        if (! $this->meets_deadline($meeting) || empty($meeting['allow_edits']) || ! hash_equals((string) get_post_meta($registration->ID, '_ssf_am_token_hash', true), hash('sha256', $token))) {
            return new \WP_Error('annual_meeting_cancel', __('Den här anmälan kan inte längre avbokas.', 'ssf-member-portal'));
        }
        update_post_meta($registration->ID, '_ssf_am_status', self::CANCELLED);
        update_post_meta($registration->ID, '_ssf_am_updated_at', time());
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

    public function choice_state(array $meeting, array $choice, int $exclude_id = 0): array
    {
        $counts = $this->selection_counts((int) $meeting['id'], $exclude_id);
        $capacity = max(0, (int) ($choice['capacity'] ?? 0));
        $count = (int) ($counts[(string) ($choice['key'] ?? '')] ?? 0);
        $deadline = (int) ($choice['deadline'] ?? 0);
        $closed = ! empty($choice['closed']) || (empty($choice['manual_open']) && $deadline && time() > $deadline);
        $registration_open = $this->meetings->is_registration_open($meeting);
        return array(
            'count' => $count,
            'capacity' => $capacity,
            'remaining' => $capacity ? max(0, $capacity - $count) : null,
            'full' => $capacity > 0 && $count >= $capacity,
            'closed' => $closed,
            'registration_open' => $registration_open,
            'available' => $registration_open && ! $closed && (! $capacity || $count < $capacity),
        );
    }

    public function queue_sync(int $meeting_id, int $attempt = 0): void
    {
        if (! wp_next_scheduled('ssf_member_portal_sync_meeting_registrations', array($meeting_id, $attempt))) {
            wp_schedule_single_event(time() + 15, 'ssf_member_portal_sync_meeting_registrations', array($meeting_id, $attempt));
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
        $meeting = $this->meetings->data($meeting_id);
        $year = (int) $meeting['year'];
        $uid = 'annual-meeting-' . $meeting_id . '@ssfb.se';
        $description = trim((string) $meeting['calendar_description']);
        if (! $description) {
            $description = (string) $meeting['intro'];
        }
        $meeting_url = $this->meetings->meeting_url(array('meeting' => $meeting_id));
        $description .= "\n" . $meeting_url;
        return implode("\r\n", array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sveriges Segelfartygsförbund//Årsmöte//SV',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . gmdate('Ymd\\THis\\Z'),
            'DTSTART:' . gmdate('Ymd\\THis\\Z', (int) $meeting['start_at']),
            'DTEND:' . gmdate('Ymd\\THis\\Z', (int) ($meeting['end_at'] ?: $meeting['start_at'] + HOUR_IN_SECONDS)),
            'SUMMARY:' . $this->ics((string) ($meeting['calendar_title'] ?: 'SSF Årsmöte ' . $year)),
            'LOCATION:' . $this->ics(trim((string) $meeting['location'] . ' ' . (string) $meeting['address'])),
            'DESCRIPTION:' . $this->ics($description),
            'URL:' . esc_url_raw($meeting_url),
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ));
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
            if ($cutoff > time()) {
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
        if (! $data['first_name'] || ! $data['last_name'] || ! is_email($data['email']) || ! $data['phone']) {
            return new \WP_Error('annual_meeting_required', __('Fyll i förnamn, efternamn, en giltig e-postadress och telefonnummer.', 'ssf-member-portal'));
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
            if ($state['closed'] || ($state['full'] && ! $was_selected)) {
                if ($was_selected) {
                    $data['program'][$item['key']] = 1;
                }
                if ($selected && ! $was_selected) {
                    return new \WP_Error(
                        $state['full'] ? 'annual_meeting_choice_full' : 'annual_meeting_choice_closed',
                        sprintf($state['full'] ? __('Tyvärr är %s fullbokad.', 'ssf-member-portal') : __('Anmälan till %s är stängd.', 'ssf-member-portal'), $item['title'])
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
        $selected_food = (array) ($input['food'] ?? array());
        foreach ((array) $meeting['food_options'] as $option) {
            if (! empty($selected_food[$option]) || in_array($option, $selected_food, true)) {
                $data['food'][] = $option;
            }
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

    private function meets_deadline(array $meeting): bool
    {
        return ! $meeting['registration_closes_at'] || time() <= (int) $meeting['registration_closes_at'];
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
        wp_schedule_single_event(time() + $delays[$attempt], 'ssf_member_portal_sync_meeting_registrations', array($meeting_id, $attempt + 1));
    }

    private function ics(string $value): string
    {
        return str_replace(array('\\', ';', ',', "\r\n", "\n", "\r"), array('\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'), $value);
    }
}
