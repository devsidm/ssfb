<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

if (! defined('ABSPATH')) {
    exit;
}

final class RegistrationExport
{
    public function data(array $meeting, array $registrations): array
    {
        $headers = array('Registration ID', 'Status', 'Anmäld datum', 'Senast ändrad', 'Förnamn', 'Efternamn', 'Telefon', 'E-post', 'Medlemstyp', 'Fartygsombud för', 'Anknytning till fartyg', 'Matpreferens', 'Matkommentar');
        $program = (array) ($meeting['program'] ?? array());
        $questions = array_filter((array) ($meeting['questions'] ?? array()), static function (array $question): bool { return ! empty($question['visible']) && 'info' !== ($question['type'] ?? ''); });
        foreach ($program as $item) {
            if (! empty($item['ask'])) {
                $headers[] = (string) $item['title'];
            }
        }
        foreach ($questions as $question) {
            $headers[] = (string) $question['title'];
        }
        $headers[] = 'SharePoint sync date';

        $rows = array();
        foreach ($registrations as $registration) {
            $id = $registration->ID;
            $program_values = (array) get_post_meta($id, '_ssf_am_program', true);
            $answers = (array) get_post_meta($id, '_ssf_am_answers', true);
            $row = array(
                (string) get_post_meta($id, '_ssf_am_registration_id', true),
                (string) get_post_meta($id, '_ssf_am_status', true),
                $this->date((int) get_post_meta($id, '_ssf_am_submitted_at', true)),
                $this->date((int) get_post_meta($id, '_ssf_am_updated_at', true)),
                (string) get_post_meta($id, '_ssf_am_first_name', true),
                (string) get_post_meta($id, '_ssf_am_last_name', true),
                (string) get_post_meta($id, '_ssf_am_phone', true),
                (string) get_post_meta($id, '_ssf_am_email', true),
                $this->relationship((string) get_post_meta($id, '_ssf_am_relationship', true)),
                implode(', ', (array) get_post_meta($id, '_ssf_am_represented_vessels', true)),
                implode(', ', (array) get_post_meta($id, '_ssf_am_associated_vessels', true)),
                implode(', ', (array) get_post_meta($id, '_ssf_am_food', true)),
                (string) get_post_meta($id, '_ssf_am_food_note', true),
            );
            foreach ($program as $item) {
                if (! empty($item['ask'])) {
                    $row[] = ! empty($program_values[$item['key']]) ? 'Ja' : 'Nej';
                }
            }
            foreach ($questions as $question) {
                $answer = $answers[$question['key']] ?? '';
                $row[] = is_array($answer) ? implode(', ', $answer) : (string) $answer;
            }
            $row[] = (string) get_post_meta($id, '_ssf_am_sharepoint_last_sync', true);
            $rows[] = $row;
        }
        return array('headers' => $headers, 'rows' => $rows);
    }

    public function create_xlsx(array $meeting, array $registrations)
    {
        $data = $this->data($meeting, $registrations);
        $year = max(2000, (int) ($meeting['sharepoint_year'] ?: $meeting['year']));
        return (new ExcelWriter())->create($data['headers'], $data['rows'], $this->sharepoint_filename($year));
    }

    public function sharepoint_filename(int $year): string
    {
        return 'Anmälningar-SSF-Årsmöte-' . $year . '.xlsx';
    }

    private function relationship(string $value): string
    {
        $labels = array('representative' => 'Fartygsombud', 'supporter' => 'Stödmedlem', 'guest' => 'Annan/inbjuden deltagare');
        return $labels[$value] ?? $value;
    }

    private function date(int $timestamp): string
    {
        return $timestamp ? wp_date('Y-m-d H:i', $timestamp, wp_timezone()) : '';
    }
}
