<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

if (! defined('ABSPATH')) {
    exit;
}

final class RegistrationMailer
{
    public function confirmation(array $meeting, array $registration, string $manage_url, string $calendar_url): bool
    {
        $year = (int) $meeting['year'];
        $subject = sprintf(__('Anmälan bekräftad – SSF:s årsmöteshelg %d', 'ssf-member-portal'), $year);
        $lines = array(
            sprintf(__('Hej %s,', 'ssf-member-portal'), $registration['first_name']),
            '',
            __('Din anmälan till årsmöteshelgens middag och aktiviteter är registrerad.', 'ssf-member-portal'),
            __('Själva årsmötet är öppet för alla medlemmar och kräver ingen anmälan.', 'ssf-member-portal'),
            '',
            sprintf(__('Årsmöte: %s', 'ssf-member-portal'), get_the_title((int) $meeting['id'])),
            sprintf(__('Datum: %s', 'ssf-member-portal'), $this->dates($meeting)),
            sprintf(__('Plats: %s', 'ssf-member-portal'), $meeting['location'] ?: __('Meddelas senare', 'ssf-member-portal')),
            '',
            __('Dina val:', 'ssf-member-portal'),
        );
        foreach ((array) $registration['program_labels'] as $item) {
            $lines[] = '• ' . $item;
        }
        if (! empty($registration['food'])) {
            $lines[] = '';
            $lines[] = sprintf(__('Mat: %s', 'ssf-member-portal'), implode(', ', (array) $registration['food']));
        }
        if (! empty($registration['food_note'])) {
            $lines[] = sprintf(__('Information till köket: %s', 'ssf-member-portal'), $registration['food_note']);
        }
        $lines = array_merge($lines, array(
            '',
            __('Ändra eller avboka din anmälan:', 'ssf-member-portal'),
            $manage_url,
            '',
            __('Lägg till mötet i din kalender:', 'ssf-member-portal'),
            $calendar_url,
        ));

        return wp_mail($registration['email'], $subject, implode("\n", $lines));
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

    private function dates(array $meeting): string
    {
        $start = (int) ($meeting['start_at'] ?? 0);
        $end = (int) ($meeting['end_at'] ?? 0);
        if (! $start) {
            return __('Meddelas senare', 'ssf-member-portal');
        }
        $start_text = wp_date('j F Y H:i', $start, wp_timezone());
        return $end ? $start_text . ' – ' . wp_date('j F Y H:i', $end, wp_timezone()) : $start_text;
    }
}
