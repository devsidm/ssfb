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

    /** @return array<int,array<string,mixed>> */
    public function events(array $meeting, ?array $selected_activity_counts = null): array
    {
        $timezone = wp_timezone();
        $timezone_name = wp_timezone_string() ?: $timezone->getName();
        $url = $this->meetings->meeting_url(array('meeting' => (int) ($meeting['id'] ?? 0)));
        $base_title = trim((string) ($meeting['title'] ?? '')) ?: trim((string) ($meeting['calendar_title'] ?? '')) ?: sprintf(__('SSF Årsmöte %d', 'ssf-member-portal'), (int) ($meeting['year'] ?? 0));
        $days = array();

        foreach ((array) ($meeting['program'] ?? array()) as $activity) {
            $key = (string) ($activity['key'] ?? '');
            if (empty($activity['visible']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($activity['date'] ?? ''))) {
                continue;
            }
            if (null !== $selected_activity_counts && (empty($selected_activity_counts[$key]) || (empty($activity['requires_registration']) && 'annual_meeting' !== ($activity['type'] ?? '')))) {
                continue;
            }
            $days[(string) $activity['date']][] = $activity;
        }

        if (! $days) {
            return $this->legacy_event($meeting, $base_title, $timezone, $timezone_name, $url);
        }

        ksort($days);
        $events = array();
        foreach ($days as $date => $activities) {
            $starts = array();
            $ends = array();
            $locations = array();
            $program_lines = array(__('Dagens program', 'ssf-member-portal'));

            foreach ($activities as $activity) {
                $start_text = preg_match('/^\d{2}:\d{2}$/', (string) ($activity['start'] ?? '')) ? (string) $activity['start'] : '';
                $end_text = preg_match('/^\d{2}:\d{2}$/', (string) ($activity['end'] ?? '')) ? (string) $activity['end'] : '';
                $start = $start_text ? new \DateTimeImmutable($date . ' ' . $start_text, $timezone) : null;
                $end = $end_text ? new \DateTimeImmutable($date . ' ' . $end_text, $timezone) : null;
                // The model assigns an activity to its start day and does not
                // currently express activities that cross midnight.
                if ($start && $end && $end > $start) {
                    $starts[] = $start;
                    $ends[] = $end;
                    $time = $start_text . '–' . $end_text;
                } else {
                    $time = __('Tid ej angiven', 'ssf-member-portal');
                }
                $title = trim((string) ($activity['title'] ?? ''));
                $count = null === $selected_activity_counts ? 0 : (int) ($selected_activity_counts[(string) ($activity['key'] ?? '')] ?? 0);
                $program_lines[] = '';
                $program_lines[] = trim($time . '  ' . $title) . ($count > 0 ? sprintf(__(' (deltagare: %d)', 'ssf-member-portal'), $count) : '');
                $location = trim((string) ($activity['location'] ?? ''));
                if ($location) {
                    $locations[$location] = true;
                    $program_lines[] = $location;
                }
                $description = trim((string) ($activity['description'] ?? ''));
                if ($description) {
                    $program_lines[] = $description;
                }
            }

            $day = new \DateTimeImmutable($date . ' 12:00:00', $timezone);
            $all_day = ! $starts || ! $ends;
            $start = $all_day ? $day->setTime(0, 0) : min($starts);
            $end = $all_day ? $day->modify('+1 day')->setTime(0, 0) : max($ends);
            $location_names = array_keys($locations);
            $location = 1 === count($location_names) ? $location_names[0] : trim((string) ($meeting['location'] ?? ''));
            $description = $base_title . "\n\n" . implode("\n", $program_lines) . "\n\n" . __('Mer information:', 'ssf-member-portal') . "\n" . $url;
            $events[] = array(
                'id' => (int) ($meeting['id'] ?? 0), 'date' => $date,
                'title' => $base_title . ' – ' . wp_date('l', $day->getTimestamp(), $timezone),
                'start' => $start, 'end' => $end, 'all_day' => $all_day, 'timezone' => $timezone_name,
                'location' => $location, 'description' => $description, 'url' => $url,
                'uid' => 'annual-meeting-' . (int) ($meeting['id'] ?? 0) . '-' . str_replace('-', '', $date) . '@ssfb.se',
            );
        }
        return $events;
    }

    public function event_data(array $meeting): array
    {
        return $this->events($meeting)[0] ?? array();
    }

    public function urls(array $meeting, string $ics_url = ''): array
    {
        if (! $this->event_data($meeting)) {
            return array();
        }
        $ics = $ics_url ?: $this->meetings->calendar_url((int) ($meeting['id'] ?? 0));
        return array('ics' => $ics, 'apple' => $ics);
    }

    public function ics(array $meeting, ?array $selected_activity_counts = null): string
    {
        $events = $this->events($meeting, $selected_activity_counts);
        if (! $events) {
            return '';
        }
        $lines = array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sveriges Segelfartygsförbund//Årsmöte//SV', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH');
        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $event['uid'];
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            if ($event['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:' . $event['start']->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:' . $event['end']->format('Ymd');
            } else {
                $lines[] = 'DTSTART;TZID=' . $event['timezone'] . ':' . $event['start']->format('Ymd\THis');
                $lines[] = 'DTEND;TZID=' . $event['timezone'] . ':' . $event['end']->format('Ymd\THis');
            }
            $lines[] = 'SUMMARY:' . $this->escape($event['title']);
            if ($event['location']) {
                $lines[] = 'LOCATION:' . $this->escape($event['location']);
            }
            $lines[] = 'DESCRIPTION:' . $this->escape($event['description']);
            $lines[] = 'URL:' . esc_url_raw($event['url']);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $lines[] = '';
        return implode("\r\n", array_map(array($this, 'fold'), $lines));
    }

    private function legacy_event(array $meeting, string $title, \DateTimeZone $timezone, string $timezone_name, string $url): array
    {
        $start_at = (int) ($meeting['start_at'] ?? 0);
        $end_at = (int) ($meeting['end_at'] ?? 0);
        if (! $start_at || ! $end_at || $end_at <= $start_at) {
            return array();
        }
        $start = (new \DateTimeImmutable('@' . $start_at))->setTimezone($timezone)->setTime(0, 0);
        $end = (new \DateTimeImmutable('@' . $end_at))->setTimezone($timezone)->setTime(0, 0);
        if ($end <= $start) {
            $end = $start->modify('+1 day');
        }
        return array(array(
            'id' => (int) ($meeting['id'] ?? 0), 'date' => $start->format('Y-m-d'), 'title' => $title,
            'start' => $start, 'end' => $end, 'all_day' => true, 'timezone' => $timezone_name,
            'location' => trim((string) ($meeting['location'] ?? '')),
            'description' => $title . "\n\n" . __('Mer information:', 'ssf-member-portal') . "\n" . $url,
            'url' => $url, 'uid' => 'annual-meeting-' . (int) ($meeting['id'] ?? 0) . '@ssfb.se',
        ));
    }

    private function escape(string $value): string
    {
        return str_replace(array('\\', ';', ',', "\r\n", "\n", "\r"), array('\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'), $value);
    }

    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($line);
        $chunks = array();
        $chunk = '';
        $limit = 75;
        foreach ($characters as $character) {
            if ($chunk && strlen($chunk . $character) > $limit) {
                $chunks[] = $chunk;
                $chunk = '';
                $limit = 74;
            }
            $chunk .= $character;
        }
        $chunks[] = $chunk;
        return implode("\r\n ", $chunks);
    }
}
