<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

if (! defined('ABSPATH')) {
    exit;
}

final class RegistrationMailer
{
    public function confirmation(array $meeting, array $registration, string $manage_url, array $calendar, string $meeting_url): bool
    {
        $data = $this->confirmation_data($meeting, $registration, $manage_url, $calendar, $meeting_url);
        return $this->send_confirmation((string) $registration['email'], (string) $data['subject'], $this->render_confirmation_html($data), $this->render_confirmation_text($data));
    }

    public function notification(array $meeting, array $registration): bool
    {
        $recipient = sanitize_email((string) $meeting['notification_email']);
        if (! $recipient || empty($meeting['notify_each'])) {
            return true;
        }
        $subject = sprintf(__('Ny aktivitetsanmälan – SSF:s årsmöteshelg %d', 'ssf-member-portal'), (int) $meeting['year']);
        $message = implode("\n", array(
            sprintf('%s %s', $registration['first_name'], $registration['last_name']),
            sprintf(__('E-post: %s', 'ssf-member-portal'), $registration['email']),
            sprintf(__('Medlemsrelation: %s', 'ssf-member-portal'), $registration['relationship_label']),
            sprintf(__('Status: %s', 'ssf-member-portal'), $registration['status_label']),
        ));
        return wp_mail($recipient, $subject, $message);
    }

    private function confirmation_data(array $meeting, array $registration, string $manage_url, array $calendar, string $meeting_url): array
    {
        $year = (int) ($meeting['year'] ?? 0);
        $meeting_rows = array();
        $this->add_row($meeting_rows, __('Årsmöte', 'ssf-member-portal'), get_the_title((int) ($meeting['id'] ?? 0)));
        $this->add_row($meeting_rows, __('Datum', 'ssf-member-portal'), $this->date_range($meeting));
        $this->add_row($meeting_rows, __('Plats', 'ssf-member-portal'), $this->location($meeting));

        $registration_rows = array();
        $this->add_row($registration_rows, __('Deltagare', 'ssf-member-portal'), trim((string) $registration['first_name'] . ' ' . (string) $registration['last_name']));
        $this->add_row($registration_rows, __('E-post', 'ssf-member-portal'), (string) ($registration['email'] ?? ''));
        $this->add_row($registration_rows, __('Telefon', 'ssf-member-portal'), (string) ($registration['phone'] ?? ''));
        $this->add_row($registration_rows, __('Relation till SSF', 'ssf-member-portal'), (string) ($registration['relationship_label'] ?? ''));
        $this->add_row($registration_rows, __('Fartygsombud för', 'ssf-member-portal'), implode(', ', (array) ($registration['represented_vessels'] ?? array())));
        $this->add_row($registration_rows, __('Kopplade fartyg', 'ssf-member-portal'), implode(', ', (array) ($registration['associated_vessels'] ?? array())));
        $this->add_row($registration_rows, __('Valda arrangemang', 'ssf-member-portal'), implode(', ', (array) ($registration['program_labels'] ?? array())));
        $this->add_row($registration_rows, __('Matpreferenser', 'ssf-member-portal'), implode(', ', (array) ($registration['food'] ?? array())));
        $this->add_row($registration_rows, __('Information till köket', 'ssf-member-portal'), (string) ($registration['food_note'] ?? ''));

        foreach ((array) ($meeting['questions'] ?? array()) as $question) {
            if (empty($question['visible']) || 'info' === ($question['type'] ?? '') || empty($question['key']) || empty($question['title'])) {
                continue;
            }
            $answer = $registration['answers'][$question['key']] ?? '';
            $this->add_row($registration_rows, (string) $question['title'], $this->answer_value($question, $answer));
        }

        return array(
            'subject' => sprintf(__('Anmälan bekräftad – SSF:s årsmöteshelg %d', 'ssf-member-portal'), $year),
            'year' => $year,
            'greeting' => trim((string) ($registration['first_name'] ?? '')),
            'meeting_rows' => $meeting_rows,
            'registration_rows' => $registration_rows,
            'calendar_url' => (string) ($calendar['ics'] ?? ''),
            'meeting_url' => $meeting_url,
            'manage_url' => $manage_url,
            'practical_information' => $this->practical_information((string) ($meeting['intro'] ?? '')),
            'logo_url' => get_template_directory_uri() . '/assets/images/ssf-logo.svg',
            'site_url' => home_url('/'),
        );
    }

    private function render_confirmation_html(array $data): string
    {
        ob_start();
        include SSF_MEMBER_PORTAL_PATH . 'templates/emails/annual-meeting-confirmation.php';
        return (string) ob_get_clean();
    }

    private function render_confirmation_text(array $data): string
    {
        $lines = array(
            __('Din anmälan är bekräftad', 'ssf-member-portal'),
            sprintf(__('SSF:s årsmöteshelg %d', 'ssf-member-portal'), (int) $data['year']),
            '',
            $data['greeting'] ? sprintf(__('Hej %s!', 'ssf-member-portal'), $data['greeting']) : __('Hej!', 'ssf-member-portal'),
            __('Tack för din anmälan till Sveriges Segelfartygsförbunds årsmöteshelg.', 'ssf-member-portal'),
            __('Vi ser fram emot att träffa dig!', 'ssf-member-portal'),
            '',
            __('ÅRSMÖTESHELGEN', 'ssf-member-portal'),
        );
        foreach ($data['meeting_rows'] as $row) {
            $lines[] = $row['label'] . ': ' . $row['value'];
        }
        if ($data['registration_rows']) {
            $lines[] = '';
            $lines[] = __('DIN ANMÄLAN', 'ssf-member-portal');
            foreach ($data['registration_rows'] as $row) {
                $lines[] = $row['label'] . ': ' . $row['value'];
            }
        }
        if ($data['calendar_url']) {
            $lines[] = '';
            $lines[] = __('Lägg till i kalender:', 'ssf-member-portal');
            $lines[] = $data['calendar_url'];
        }
        if ($data['meeting_url']) {
            $lines[] = '';
            $lines[] = __('Visa information om årsmötet:', 'ssf-member-portal');
            $lines[] = $data['meeting_url'];
        }
        if ($data['practical_information']) {
            $lines[] = '';
            $lines[] = __('PRAKTISK INFORMATION', 'ssf-member-portal');
            $lines[] = $data['practical_information'];
        }
        if ($data['manage_url']) {
            $lines[] = '';
            $lines[] = __('Visa eller ändra min anmälan:', 'ssf-member-portal');
            $lines[] = $data['manage_url'];
        }
        $lines = array_merge($lines, array(
            '',
            __('Sveriges Segelfartygsförbund', 'ssf-member-portal'),
            $data['site_url'],
            __('Detta är ett automatiskt meddelande från system@ssfb.se.', 'ssf-member-portal'),
        ));
        return implode("\n", $lines);
    }

    private function send_confirmation(string $recipient, string $subject, string $html, string $text): bool
    {
        $boundary = '=_ssf_annual_meeting_' . wp_generate_password(24, false, false);
        $message = implode("\r\n", array(
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $text,
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $html,
            '--' . $boundary . '--',
            '',
        ));
        $headers = array(
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        );
        $from = static function (string $value): string {
            return 'system@ssfb.se';
        };
        $from_name = static function (string $value): string {
            return 'Sveriges Segelfartygsförbund';
        };
        add_filter('wp_mail_from', $from);
        add_filter('wp_mail_from_name', $from_name);
        try {
            return wp_mail(sanitize_email($recipient), $subject, $message, $headers);
        } finally {
            remove_filter('wp_mail_from', $from);
            remove_filter('wp_mail_from_name', $from_name);
        }
    }

    private function add_row(array &$rows, string $label, string $value): void
    {
        $value = trim(wp_strip_all_tags($value));
        if ($value) {
            $rows[] = array('label' => $label, 'value' => $value);
        }
    }

    private function answer_value(array $question, $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map('sanitize_text_field', $value)));
        }
        if ('yes_no' === ($question['type'] ?? '')) {
            if ('yes' === $value) {
                return __('Ja', 'ssf-member-portal');
            }
            if ('no' === $value) {
                return __('Nej', 'ssf-member-portal');
            }
            return '';
        }
        if ('date' === ($question['type'] ?? '') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value, wp_timezone());
            return $date ? wp_date('j F Y', $date->getTimestamp(), wp_timezone()) : '';
        }
        return sanitize_textarea_field((string) $value);
    }

    private function date_range(array $meeting): string
    {
        $start = (int) ($meeting['start_at'] ?? 0);
        $end = (int) ($meeting['end_at'] ?? 0);
        if (! $start) {
            return __('Datum meddelas senare', 'ssf-member-portal');
        }
        if (! $end || $end <= $start) {
            return wp_date('j F Y', $start, wp_timezone());
        }
        $last_day = $end - 1;
        if (wp_date('Y-m-d', $start, wp_timezone()) === wp_date('Y-m-d', $last_day, wp_timezone())) {
            return wp_date('j F Y', $start, wp_timezone());
        }
        return wp_date('j F', $start, wp_timezone()) . ' – ' . wp_date('j F Y', $last_day, wp_timezone());
    }

    private function location(array $meeting): string
    {
        $city = trim(implode(' ', array_filter(array((string) ($meeting['postal_code'] ?? ''), (string) ($meeting['city'] ?? '')))));
        return implode(', ', array_filter(array(
            trim((string) ($meeting['location'] ?? '')),
            trim((string) ($meeting['address'] ?? '')),
            $city,
        )));
    }

    private function practical_information(string $intro): string
    {
        $intro = trim(wp_strip_all_tags($intro));
        return $intro ? wp_trim_words($intro, 55, '…') : '';
    }
}
