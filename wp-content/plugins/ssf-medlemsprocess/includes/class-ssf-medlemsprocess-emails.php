<?php
/**
 * Transactional email templates and sending.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsprocess_Emails
{
    public static function templates(): array
    {
        return array(
            'received' => array('label' => 'Bekräftelse på mottagen ansökan', 'subject' => 'Vi har tagit emot din ansökan {application_id}', 'body' => "Hej {applicant_name},\n\nTack för din ansökan som fartygsombud för {ship_name}.\n\nAnsökningsnummer: {application_id}\nStatus: Inkommen\n\nFölj ansökan här:\n{status_link}\n\nVänliga hälsningar\nSveriges Segelfartygsförbund"),
            'status_updated' => array('label' => 'Status uppdaterad', 'subject' => 'Din ansökan {application_id} har uppdaterats', 'body' => "Hej {applicant_name},\n\nStatusen för ansökan för {ship_name} har ändrats.\n\nNy status: {application_status}\n\n{admin_comment}\n\nFölj ansökan här:\n{status_link}\n\nVänliga hälsningar\nSveriges Segelfartygsförbund"),
            'completion_required' => array('label' => 'Komplettering krävs', 'subject' => 'Komplettering behövs för din ansökan till SSF', 'body' => "Hej {applicant_name},\n\nSSF behöver en komplettering i ärendet för {ship_name}.\n\n{admin_comment}\n\nSvara och följ ärendet här:\n{status_link}"),
            'completion_received' => array('label' => 'Komplettering mottagen', 'subject' => 'Vi har tagit emot din komplettering', 'body' => "Hej {applicant_name},\n\nTack, din komplettering för {ship_name} är mottagen och granskas av SSF.\n\n{status_link}"),
            'booking' => array('label' => 'Tid bokad', 'subject' => 'Tid bokad för ansökan till SSF - {ship_name}', 'body' => "Hej {applicant_name},\n\nSSF har bokat en tid för din ansökan.\n\nTid: {booking_time}\nPlats/form: {booking_location}\n\n{admin_comment}\n\n{status_link}"),
            'inspection_completed' => array('label' => 'Inspektion genomförd', 'subject' => 'Inspektionen är genomförd - {ship_name}', 'body' => "Hej {applicant_name},\n\nInspektionsunderlaget för {ship_name} är klart. SSF återkommer när nästa steg är beslutat.\n\n{status_link}"),
            'approved_aspirant' => array('label' => 'Beslut: godkänd som aspirant', 'subject' => 'Din ansökan till SSF är godkänd som aspirant', 'body' => "Hej {applicant_name},\n\nAnsökan för {ship_name} har godkänts som aspirant.\n\n{admin_comment}\n\n{status_link}"),
            'rejected' => array('label' => 'Beslut: avslagen', 'subject' => 'Beslut om din ansökan till SSF', 'body' => "Hej {applicant_name},\n\nSSF har fattat beslut om ansökan för {ship_name}.\n\n{admin_comment}\n\n{status_link}"),
            'reminder' => array('label' => 'Påminnelse till sökanden', 'subject' => 'Påminnelse om din ansökan till SSF', 'body' => "Hej {applicant_name},\n\nDet finns en uppdatering i ditt ärende för {ship_name}.\n\n{admin_comment}\n\n{status_link}"),
            'admin_notice' => array('label' => 'Intern notis till admin', 'subject' => 'Ny medlemsansökan: {ship_name} ({application_id})', 'body' => "En ny ansökan har skickats in.\n\nÄrende: {application_id}\nFartyg: {ship_name}\nSökande: {applicant_name}\n\nGranska ansökan:\n{admin_url}"),
        );
    }

    public function send_received(int $application_id, string $token): bool
    {
        $this->send_admin_notice($application_id);
        $applicant_sent = $this->send_template('received', $application_id, array('status_link' => SSF_Medlemsprocess_Application::status_link($token)));
        return $applicant_sent;
    }

    public function send_admin_notice(int $application_id): bool
    {
        if (get_post_meta($application_id, '_ssf_admin_new_application_notification_sent_at', true)) {
            return true;
        }

        $variables = $this->application_variables($application_id, array(
            'admin_url' => SSF_Medlemsprocess_Application::review_url($application_id),
        ));
        if (! $variables['admin_url']) {
            return false;
        }

        $sharepoint_status = (string) get_post_meta($application_id, '_ssf_sp_sync_status', true);
        $subject = strtr(self::templates()['admin_notice']['subject'], $this->replace_map($variables));
        $sent = false;
        if (class_exists('SSF_Email_Router') && class_exists('SSF_Email_Template')) {
            $sent = SSF_Email_Router::send_template_to_function('membership_application', $subject, 'application_admin_notice', array(
                'category' => 'membership',
                'recipient_name' => 'Medlemsgruppen',
                'body' => array('En ny ansökan om medlemskap för fartyg har skickats in. Granska ärendet i handläggningsportalen för att se uppgifter, bilagor, status och SharePoint-synk.'),
                'sections' => array(array('title' => 'Ansökan', 'rows' => array_filter(array(
                    'Ansökningsnummer' => $variables['application_id'],
                    'Fartyg' => $variables['ship_name'],
                    'Sökande' => $variables['applicant_name'],
                    'E-post' => $variables['applicant_email'],
                    'Ansökningsväg' => $variables['application_path'],
                    'Ansökningsstatus' => $variables['application_status'],
                    'Medlemsstatus' => $variables['membership_status'],
                    'Inkommen' => $variables['received_date'],
                    'SharePoint-synk' => $this->sharepoint_status_label($sharepoint_status),
                )))),
                'button_label' => 'Granska ansökan',
                'button_url' => $variables['admin_url'],
            ));
        }

        if ($sent) {
            update_post_meta($application_id, '_ssf_admin_new_application_notification_sent_at', current_time('mysql'));
        }
        SSF_Medlemsprocess_Application::add_history($application_id, 'email', 'Adminnotis för ny medlemsansökan ' . ($sent ? 'skickades.' : 'kunde inte skickas.'), false);
        return $sent;
    }

    public function send_status_email(int $application_id, string $status, string $message = ''): void
    {
        $map = array(
            'needs_completion' => 'completion_required', 'completion_submitted' => 'completion_received',
            'inspection_completed' => 'inspection_completed', 'approved_aspirant' => 'approved_aspirant', 'rejected' => 'rejected',
        );
        $token = SSF_Medlemsprocess_Application::issue_token($application_id);
        $this->send_template($map[$status] ?? 'status_updated', $application_id, array('public_status_comment' => $message, 'status_link' => SSF_Medlemsprocess_Application::status_link($token)));
    }

    public function send_booking(int $application_id, array $booking): void
    {
        $time = trim(($booking['date'] ?? '') . ' ' . ($booking['start'] ?? '') . (! empty($booking['end']) ? ' - ' . $booking['end'] : ''));
        $token = SSF_Medlemsprocess_Application::issue_token($application_id);
        $this->send_template('booking', $application_id, array('booking_time' => $time, 'booking_location' => $booking['location'] ?? '', 'public_status_comment' => $booking['comment'] ?? '', 'status_link' => SSF_Medlemsprocess_Application::status_link($token)));
    }

    public function send_inspector_assignment(int $application_id, WP_User $inspector): bool
    {
        if (! is_email($inspector->user_email)) {
            return false;
        }
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $deadline = (string) get_post_meta($application_id, '_ssf_inspector_deadline', true);
        $task = (string) get_post_meta($application_id, '_ssf_inspector_task', true);
        $ship_name = (string) ($data['ship_name'] ?? get_the_title($application_id));
        $sent = SSF_Email_Template::send($inspector->user_email, 'Ny inspektion tilldelad: ' . $ship_name, 'inspector_assignment', array(
            'recipient_name' => $inspector->display_name,
            'body' => array('Du har tilldelats en inspektion för ' . $ship_name . '.'),
            'sections' => array(array('title' => 'Inspektionsuppdrag', 'rows' => array_filter(array(
                'Fartyg' => $ship_name,
                'Önskat klart-datum' => $deadline,
            )))),
            'notice_title' => $task ? 'Uppdrag' : '',
            'notice' => $task,
            'button_label' => 'Öppna ärendet',
            'button_url' => SSF_Medlemsprocess_Plugin::instance()->inspector->case_url($application_id),
        ));
        SSF_Medlemsprocess_Application::add_history($application_id, 'email', 'E-post om inspektörstilldelning ' . ($sent ? 'skickades.' : 'kunde inte skickas.'), false);
        return $sent;
    }

    public function send_inspection_complete(int $application_id): bool
    {
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $number = (string) get_post_meta($application_id, '_ssf_application_number', true);
        $edit_url = get_edit_post_link($application_id, '');
        $body = "Inspektionsrapporterna för ärendet är klara.\n\nÄrende: " . $number . "\nFartyg: " . ($data['ship_name'] ?? get_the_title($application_id)) . "\n\nÖppna ärendet i WordPress:\n" . $edit_url;
        $subject = 'Inspektionsrapport klar: ' . ($data['ship_name'] ?? get_the_title($application_id));
        $sent = SSF_Email_Router::send_to_function('inspection_complete', $subject, $body, array('Content-Type: text/plain; charset=UTF-8'));
        SSF_Medlemsprocess_Application::add_history($application_id, 'email', 'E-post om färdig inspektionsrapport ' . ($sent ? 'skickades till handläggare.' : 'kunde inte skickas.'), false);
        return $sent;
    }

    public function send_template(string $key, int $application_id, array $variables = array(), bool $admin_recipient = false, string $recipient_override = ''): bool
    {
        $templates = self::templates();
        if (! isset($templates[$key])) {
            return false;
        }
        $settings = SSF_Medlemsprocess_Plugin::settings();
        $override = (array) ($settings['templates'][$key] ?? array());
        $template = array_merge($templates[$key], array_filter($override, 'is_string'));
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $variables = $this->application_variables($application_id, $variables);
        $replace = $this->replace_map($variables);
        $subject = strtr($template['subject'], $replace);
        $body = strtr($template['body'], $replace);
        if ($admin_recipient) {
            if ('admin_notice' === $key) {
                return $this->send_admin_notice($application_id);
            }
            $sent = SSF_Email_Router::send_to_function('membership_application', $subject, $body, array('Content-Type: text/plain; charset=UTF-8'));
            SSF_Medlemsprocess_Application::add_history($application_id, 'email', sprintf('E-postmall "%s" %s.', $template['label'], $sent ? 'skickad' : 'kunde inte skickas'), false);
            return $sent;
        }
        $recipient = $recipient_override ?: ($admin_recipient ? ($settings['application_notification_email'] ?: $settings['admin_email']) : ($data['applicant_email'] ?? ''));
        if (! is_email($recipient)) {
            return false;
        }
        $sent = SSF_Email_Template::send($recipient, $subject, $this->central_template($key), $this->central_message($key, $variables));
        SSF_Medlemsprocess_Application::add_history($application_id, 'email', sprintf('E-postmall "%s" %s.', $template['label'], $sent ? 'skickad' : 'kunde inte skickas'), false);
        return $sent;
    }

    private function application_variables(int $application_id, array $variables = array()): array
    {
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $defaults = array(
            'applicant_name' => $data['applicant_name'] ?? '',
            'applicant_email' => $data['applicant_email'] ?? '',
            'ship_name' => $data['ship_name'] ?? get_the_title($application_id),
            'vessel_operation' => $data['vessel_operation'] ?? '',
            'application_id' => get_post_meta($application_id, '_ssf_application_number', true),
            'application_path' => $data['application_path'] ?? '',
            'application_status' => SSF_Medlemsprocess_Application::status_label(SSF_Medlemsprocess_Application::status($application_id)),
            'membership_status' => SSF_Medlemsprocess_Application::membership_status_label(SSF_Medlemsprocess_Application::membership_status($application_id)),
            'received_date' => get_the_date('j F Y, H:i', $application_id),
            'status_link' => '',
            'admin_url' => '',
            'admin_comment' => '',
            'public_status_comment' => '',
            'next_step' => get_post_meta($application_id, '_ssf_next_action', true),
            'booking_time' => '',
            'booking_location' => '',
            'decision' => '',
            'decision_comment' => get_post_meta($application_id, '_ssf_decision_public_reason', true),
            'decision_date' => get_post_meta($application_id, '_ssf_decision_date', true),
            'aspirant_start' => get_post_meta($application_id, '_ssf_aspirant_started_at', true),
            'aspirant_review' => get_post_meta($application_id, '_ssf_aspirant_review_due_at', true),
        );
        return array_merge($defaults, $variables);
    }

    private function replace_map(array $variables): array
    {
        $replace = array();
        foreach ($variables as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }
        return $replace;
    }

    private function sharepoint_status_label(string $status): string
    {
        $labels = array(
            'pending' => 'Väntar',
            'syncing' => 'Synkas',
            'synced' => 'Synkad',
            'error' => 'Fel - försöker igen enligt befintlig policy',
            'not_configured' => 'Inte konfigurerad',
        );
        return $labels[$status] ?? ($status ?: 'Väntar');
    }

    private function central_template(string $key): string
    {
        if ('received' === $key) {
            return 'application_received';
        }
        return 'completion_required' === $key ? 'application_completion' : 'application_status';
    }

    private function central_message(string $key, array $variables): array
    {
        $ship_name = sanitize_text_field((string) ($variables['ship_name'] ?? ''));
        $application_number = sanitize_text_field((string) ($variables['application_id'] ?? ''));
        $status = sanitize_text_field((string) ($variables['application_status'] ?? ''));
        $comment = sanitize_textarea_field((string) ($variables['public_status_comment'] ?? ''));
        $body = 'Det finns en uppdatering i ärendet för ' . $ship_name . '.';
        $title = '';
        $button_label = 'Följ din ansökan';
        $extra_rows = array();
        $sections = array();
        $notice_title = $comment ? 'Meddelande från SSF' : '';
        $notice = $comment;

        if ('received' === $key) {
            $body = 'Tack för din ansökan om medlemskap för ' . $ship_name . '. Ansökan har registrerats och kommer att behandlas av Sveriges Segelfartygsförbund.';
            $status = 'Inkommen';
        } elseif ('completion_required' === $key) {
            $body = 'Vi behöver ytterligare information för att kunna fortsätta behandlingen av ansökan för ' . $ship_name . '.';
            $button_label = 'Komplettera din ansökan';
        } elseif ('completion_received' === $key) {
            $title = 'Vi har tagit emot din komplettering';
            $body = 'Tack, din komplettering för ' . $ship_name . ' är mottagen och granskas av SSF.';
        } elseif ('booking' === $key) {
            $title = 'Tid bokad för din ansökan';
            $body = 'SSF har bokat en tid för din ansökan.';
            $extra_rows = array('Tid' => (string) ($variables['booking_time'] ?? ''), 'Plats eller form' => (string) ($variables['booking_location'] ?? ''));
        } elseif ('inspection_completed' === $key) {
            $title = 'Inspektionen är genomförd';
            $body = 'Inspektionsunderlaget för ' . $ship_name . ' är klart. SSF återkommer när nästa steg är beslutat.';
        } elseif ('approved_aspirant' === $key) {
            $title = 'Din ansökan är godkänd som aspirant';
            $body = 'Er ansökan har godkänts och fartyget antas som aspirant i Sveriges Segelfartygsförbund. Aspirantperioden är ett år och följs av en uppföljning inför ett aktivt beslut om medlemskap som medlemsfartyg.';
            $extra_rows = array('Aspirant från' => (string) ($variables['aspirant_start'] ?? ''), 'Planerat uppföljningsdatum' => (string) ($variables['aspirant_review'] ?? ''));
        } elseif ('rejected' === $key) {
            $title = 'Beslut om din ansökan';
            $body = 'SSF har fattat beslut om ansökan för ' . $ship_name . '.';
        } elseif ('reminder' === $key) {
            $title = 'Meddelande om din ansökan';
        }

        $rows = array_merge(array(
            'Fartyg' => $ship_name,
            'Ansökningsnummer' => $application_number,
            'Inkommen' => 'received' === $key ? (string) ($variables['received_date'] ?? '') : '',
            'Status' => $status,
        ), $extra_rows);
        $sections[] = array('title' => 'Ansökan', 'rows' => array_filter($rows));
        if ('received' === $key) {
            $operation = sanitize_key((string) ($variables['vessel_operation'] ?? ''));
            $organization = SSF_Organization_Info::get();
            $fees = SSF_Organization_Info::membership_fees();
            if (isset($fees[$operation])) {
                $body .= ' För att vi ska börja behandla ansökan behöver medlemsavgiften betalas in.';
                $sections[] = array('title' => 'Betalning', 'rows' => array(
                    'Fartygskategori' => $fees[$operation]['label'],
                    'Årsavgift' => $fees[$operation]['amount'],
                    'Bankgiro' => $organization['bankgiro'],
                    'Swish' => $organization['swish'],
                ));
                $notice_title = 'Viktigt om betalningen';
                $notice = 'Betala in årsavgiften och ange ansökningsnummer ' . $application_number . ' som meddelande i betalningen.';
            }
        }
        return array(
            'title' => $title,
            'recipient_name' => (string) ($variables['applicant_name'] ?? ''),
            'body' => array($body),
            'sections' => $sections,
            'notice_title' => $notice_title,
            'notice' => $notice,
            'button_label' => $button_label,
            'button_url' => esc_url_raw((string) ($variables['status_link'] ?? '')),
        );
    }
}
