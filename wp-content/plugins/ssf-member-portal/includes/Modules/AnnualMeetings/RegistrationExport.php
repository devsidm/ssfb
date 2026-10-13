<?php
namespace SSF\MemberPortal\Modules\AnnualMeetings;

if (! defined('ABSPATH')) { exit; }

final class RegistrationExport
{
    public function data(array $meeting, array $registrations): array
    {
        $activities = $this->activities($meeting);
        $headers = array('Anmälnings-ID', 'Huvudanmälare', 'Namn', 'E-post', 'Telefon', 'Medlemskap/roll', 'Fartyg');
        foreach ($activities as $activity) { $headers[] = (string) $activity['title']; }
        $headers = array_merge($headers, array('Specialkost', 'Allergi/övrigt', 'Status', 'Registreringsdatum'));
        $rows = array();
        $activity_counts = array_fill_keys(array_keys($activities), 0);
        $diet_rows = array();
        $answer_rows = array();
        foreach ($registrations as $registration) {
            $id = (int) $registration->ID;
            $status = (string) get_post_meta($id, '_ssf_am_status', true) ?: RegistrationService::REGISTERED;
            $registration_id = (string) get_post_meta($id, '_ssf_am_registration_id', true);
            $submitted = $this->date((int) get_post_meta($id, '_ssf_am_submitted_at', true));
            $registration_participants = $this->participants($id);
            foreach ($registration_participants as $participant) {
                $name = trim((string) ($participant['first_name'] ?? '') . ' ' . (string) ($participant['last_name'] ?? ''));
                $row = array($registration_id, ! empty($participant['primary']) ? 'Ja' : 'Nej', $name, (string) ($participant['email'] ?? ''), (string) ($participant['phone'] ?? ''), $this->relationship((string) ($participant['relationship'] ?? '')), implode(', ', (array) ($participant['vessels'] ?? array())));
                $selected = array_filter((array) ($participant['activities'] ?? array()));
                foreach ($activities as $key => $activity) {
                    $is_selected = ! empty($selected[$key]);
                    $row[] = $is_selected ? 'Ja' : 'Nej';
                    if ($is_selected && RegistrationService::CANCELLED !== $status) { $activity_counts[$key]++; }
                    if ($is_selected && RegistrationService::CANCELLED !== $status && ! empty($activity['food']) && $this->has_special_diet($participant)) {
                        $diet_rows[] = array($name, (string) $activity['title'], $this->dietary($participant), (string) ($participant['allergy_note'] ?? ''), $registration_id);
                    }
                }
                $row[] = $this->dietary($participant);
                $row[] = (string) ($participant['allergy_note'] ?? '');
                $row[] = $this->status($status);
                $row[] = $submitted;
                $rows[] = $row;
            }
            $stored_answers = get_post_meta($id, '_ssf_am_activity_answers', true);
            if (is_array($stored_answers) && $stored_answers) {
                $names = array();
                foreach ($registration_participants as $participant) { $names[(string) ($participant['id'] ?? '')] = trim((string) ($participant['first_name'] ?? '') . ' ' . (string) ($participant['last_name'] ?? '')); }
                foreach ($stored_answers as $activity_key => $entries) {
                    foreach ((array) $entries as $entry) {
                        $question = (array) ($entry['question'] ?? array());
                        $activity_title = (string) ($activities[$activity_key]['title'] ?? $activity_key);
                        if ('registration' === ($question['scope'] ?? 'participant')) {
                            $answer_rows[] = array($activity_title, 'Hela anmälan', (string) ($question['title'] ?? ''), $this->answer_value($entry['registration'] ?? ''), $registration_id);
                        } else {
                            foreach ((array) ($entry['participants'] ?? array()) as $person_id => $value) {
                                $answer_rows[] = array($activity_title, $names[$person_id] ?? $person_id, (string) ($question['title'] ?? ''), $this->answer_value($value), $registration_id);
                            }
                        }
                    }
                }
            } else {
                foreach ($registration_participants as $participant) {
                    $name = trim((string) ($participant['first_name'] ?? '') . ' ' . (string) ($participant['last_name'] ?? ''));
                    foreach ((array) ($participant['activities'] ?? array()) as $activity_key => $selected) {
                        if (! $selected || empty($activities[$activity_key]['questions'])) { continue; }
                        foreach ((array) $activities[$activity_key]['questions'] as $question) {
                            if ('dietary_preferences' === ($question['key'] ?? '')) { $value = $this->dietary($participant); }
                            elseif ('kitchen_notes' === ($question['key'] ?? '')) { $value = (string) ($participant['allergy_note'] ?? ''); }
                            else { continue; }
                            $answer_rows[] = array((string) $activities[$activity_key]['title'], $name, (string) $question['title'], $value, $registration_id);
                        }
                    }
                }
            }
        }
        $activity_rows = array();
        foreach ($activities as $key => $activity) {
            $capacity = max(0, (int) ($activity['capacity'] ?? 0));
            $count = (int) ($activity_counts[$key] ?? 0);
            $activity_rows[] = array((string) $activity['title'], $count, $capacity ?: '', $capacity ? max(0, $capacity - $count) : 'Obegränsat');
        }
        return array('headers' => $headers, 'rows' => $rows, 'sheets' => array(
            array('name' => 'Deltagare', 'headers' => $headers, 'rows' => $rows),
            array('name' => 'Aktiviteter', 'headers' => array('Aktivitet', 'Antal', 'Max antal', 'Lediga platser'), 'rows' => $activity_rows),
            array('name' => 'Aktivitetsfrågor', 'headers' => array('Aktivitet', 'Deltagare/anmälan', 'Fråga', 'Svar', 'Anmälnings-ID'), 'rows' => $answer_rows),
            array('name' => 'Specialkost', 'headers' => array('Namn', 'Aktivitet', 'Kost', 'Allergi/övrigt', 'Anmälnings-ID'), 'rows' => $diet_rows),
        ));
    }

    public function create_xlsx(array $meeting, array $registrations)
    {
        $data = $this->data($meeting, $registrations);
        $year = max(2000, (int) ($meeting['sharepoint_year'] ?: $meeting['year']));
        return (new ExcelWriter())->create_workbook($data['sheets'], $this->sharepoint_filename($year));
    }

    public function sharepoint_filename(int $year): string { return 'Anmälningar-SSF-Årsmöte-' . $year . '.xlsx'; }

    private function participants(int $id): array
    {
        $stored = get_post_meta($id, '_ssf_am_participants', true);
        if (is_array($stored) && $stored) { return $stored; }
        $program = (array) get_post_meta($id, '_ssf_am_program', true);
        if ('yes' === get_post_meta($id, '_ssf_am_meeting_attendance', true)) { $program['annual_meeting'] = 1; }
        return array(array(
            'first_name' => (string) get_post_meta($id, '_ssf_am_first_name', true), 'last_name' => (string) get_post_meta($id, '_ssf_am_last_name', true),
            'email' => (string) get_post_meta($id, '_ssf_am_email', true), 'phone' => (string) get_post_meta($id, '_ssf_am_phone', true),
            'relationship' => (string) get_post_meta($id, '_ssf_am_relationship', true),
            'vessels' => array_values(array_filter(array_merge((array) get_post_meta($id, '_ssf_am_represented_vessels', true), (array) get_post_meta($id, '_ssf_am_associated_vessels', true)))),
            'activities' => $program, 'dietary' => array_values(array_filter((array) get_post_meta($id, '_ssf_am_food', true))), 'dietary_other' => '',
            'allergy_note' => (string) get_post_meta($id, '_ssf_am_food_note', true), 'primary' => 1,
        ));
    }

    private function activities(array $meeting): array
    {
        $activities = array();
        foreach ((array) ($meeting['program'] ?? array()) as $item) {
            if (! empty($item['visible']) && (! empty($item['requires_registration']) || 'annual_meeting' === ($item['type'] ?? '') || 'annual_meeting' === ($item['key'] ?? ''))) { $activities[(string) $item['key']] = $item; }
        }
        return $activities;
    }

    private function relationship(string $value): string
    {
        $labels = array('representative' => 'Fartygsombud', 'supporter' => 'Stödmedlem', 'vessel_member' => 'Fartygsmedlem', 'companion' => 'Medföljande', 'other' => 'Annan', 'guest' => 'Annan/inbjuden deltagare');
        return $labels[$value] ?? $value;
    }

    private function dietary(array $participant): string
    {
        $labels = array('none' => 'Ingen specialkost', 'vegetarian' => 'Vegetarisk', 'vegan' => 'Vegansk', 'gluten_free' => 'Glutenfri', 'lactose_free' => 'Laktosfri', 'other' => 'Annan');
        $values = array_map(static function ($value) use ($labels): string { return $labels[$value] ?? (string) $value; }, (array) ($participant['dietary'] ?? array()));
        if (! empty($participant['dietary_other'])) { $values[] = (string) $participant['dietary_other']; }
        return implode(', ', array_unique(array_filter($values)));
    }

    private function has_special_diet(array $participant): bool
    {
        return (bool) (array_diff((array) ($participant['dietary'] ?? array()), array('none')) || ! empty($participant['dietary_other']) || ! empty($participant['allergy_note']));
    }

    private function answer_value($value): string
    {
        if (is_array($value)) { return implode(', ', array_filter(array_map('sanitize_text_field', $value))); }
        if ('yes' === $value) { return 'Ja'; }
        if ('no' === $value) { return 'Nej'; }
        return sanitize_textarea_field((string) $value);
    }

    private function status(string $value): string
    {
        return array(RegistrationService::REGISTERED => 'Registrerad', RegistrationService::WAITLIST => 'Reservlista', RegistrationService::CANCELLED => 'Avbokad')[$value] ?? $value;
    }

    private function date(int $timestamp): string { return $timestamp ? wp_date('Y-m-d H:i', $timestamp, wp_timezone()) : ''; }
}
