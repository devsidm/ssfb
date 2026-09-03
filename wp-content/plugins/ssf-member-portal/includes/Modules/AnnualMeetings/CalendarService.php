<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

if (! defined('ABSPATH')) {
    exit;
}

final class CalendarService
{
    private Module $meetings;

    public function __construct(Module $meetings)
    {
        $this->meetings = $meetings;
    }

    public function event_data(array $meeting): array
    {
        $start = (int) ($meeting['start_at'] ?? 0);
        $end = (int) ($meeting['end_at'] ?? 0);
        if (! $start || ! $end || $end <= $start) {
            return array();
        }

        $year = (int) ($meeting['year'] ?? wp_date('Y', $start, wp_timezone()));
        $location = trim(implode(', ', array_filter(array(
            trim((string) ($meeting['location'] ?? '')),
            trim((string) ($meeting['address'] ?? '')),
            trim(implode(' ', array_filter(array((string) ($meeting['postal_code'] ?? ''), (string) ($meeting['city'] ?? ''))))),
        ))));
        $url = $this->meetings->meeting_url(array('meeting' => (int) ($meeting['id'] ?? 0)));
        $description = trim((string) ($meeting['calendar_description'] ?? ''));
        if (! $description) {
            $description = sprintf(__('SSF:s årsmöteshelg %d.', 'ssf-member-portal'), $year) . "\n"
                . __('Själva årsmötet kräver ingen anmälan.', 'ssf-member-portal') . "\n"
                . __('Anmälan gäller middag och valfria aktiviteter.', 'ssf-member-portal');
        }

        $start_date = (new \DateTimeImmutable('@' . $start))->setTimezone(wp_timezone())->setTime(0, 0);
        $end_date = (new \DateTimeImmutable('@' . $end))->setTimezone(wp_timezone())->setTime(0, 0);
        if ($end_date <= $start_date) {
            $end_date = $start_date->modify('+1 day');
        }

        return array(
            'id' => (int) ($meeting['id'] ?? 0),
            'year' => $year,
            'title' => trim((string) ($meeting['calendar_title'] ?? '')) ?: sprintf(__('SSF Årsmöte %d', 'ssf-member-portal'), $year),
            'start' => $start_date,
            'end' => $end_date,
            'all_day' => true,
            'timezone' => wp_timezone_string() ?: 'Europe/Stockholm',
            'location' => $location,
            'description' => trim($description) . "\n\n" . __('Mer information:', 'ssf-member-portal') . "\n" . $url,
            'url' => $url,
            'uid' => 'annual-meeting-' . (int) ($meeting['id'] ?? 0) . '@ssfb.se',
        );
    }

    public function urls(array $meeting): array
    {
        $event = $this->event_data($meeting);
        if (! $event) {
            return array();
        }
        $ics = $this->meetings->calendar_url((int) $event['id']);
        return array(
            'ics' => $ics,
            'apple' => $ics,
            'google' => add_query_arg(array(
                'action' => 'TEMPLATE',
                'text' => $event['title'],
                'dates' => $event['start']->format('Ymd') . '/' . $event['end']->format('Ymd'),
                'details' => $event['description'],
                'location' => $event['location'],
                'ctz' => $event['timezone'],
            ), 'https://calendar.google.com/calendar/render'),
            'outlook' => add_query_arg(array(
                'path' => '/calendar/action/compose',
                'rru' => 'addevent',
                'subject' => $event['title'],
                'startdt' => $event['start']->format('Y-m-d'),
                'enddt' => $event['end']->format('Y-m-d'),
                'allday' => 'true',
                'body' => $event['description'],
                'location' => $event['location'],
            ), 'https://outlook.office.com/calendar/0/deeplink/compose'),
        );
    }

    public function ics(array $meeting): string
    {
        $event = $this->event_data($meeting);
        if (! $event) {
            return '';
        }
        $lines = array(
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sveriges Segelfartygsförbund//Årsmöte//SV', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-TIMEZONE:' . $event['timezone'], 'BEGIN:VEVENT', 'UID:' . $event['uid'], 'DTSTAMP:' . gmdate('Ymd\\THis\\Z'),
            'DTSTART;VALUE=DATE:' . $event['start']->format('Ymd'),
            'DTEND;VALUE=DATE:' . $event['end']->format('Ymd'),
            'SUMMARY:' . $this->escape($event['title']), 'LOCATION:' . $this->escape($event['location']),
            'DESCRIPTION:' . $this->escape($event['description']), 'URL:' . esc_url_raw($event['url']), 'END:VEVENT', 'END:VCALENDAR', '',
        );
        return implode("\r\n", array_map(array($this, 'fold'), $lines));
    }

    private function escape(string $value): string
    {
        return str_replace(array('\\', ';', ',', "\r\n", "\n", "\r"), array('\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'), $value);
    }

    private function fold(string $line): string
    {
        return strlen($line) <= 75 ? $line : implode("\r\n ", str_split($line, 75));
    }
}
