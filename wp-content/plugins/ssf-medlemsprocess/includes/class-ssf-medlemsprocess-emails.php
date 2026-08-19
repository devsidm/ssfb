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
            'received' => array('label' => 'Bekräftelse på mottagen ansökan', 'subject' => 'Vi har tagit emot din ansökan till SSF', 'body' => "Hej {applicant_name},\n\nTack för din ansökan som fartygsombud för {ship_name}. SSF har tagit emot ansökan.\n\nFölj ärendet här:\n{status_link}\n\nVänliga hälsningar\nSveriges Segelfartygsförbund"),
            'completion_required' => array('label' => 'Komplettering krävs', 'subject' => 'Komplettering behövs för din ansökan till SSF', 'body' => "Hej {applicant_name},\n\nSSF behöver en komplettering i ärendet för {ship_name}.\n\n{admin_comment}\n\nSvara och följ ärendet här:\n{status_link}"),
            'completion_received' => array('label' => 'Komplettering mottagen', 'subject' => 'Vi har tagit emot din komplettering', 'body' => "Hej {applicant_name},\n\nTack, din komplettering för {ship_name} är mottagen och granskas av SSF.\n\n{status_link}"),
            'booking' => array('label' => 'Tid bokad', 'subject' => 'Tid bokad för ansökan till SSF - {ship_name}', 'body' => "Hej {applicant_name},\n\nSSF har bokat en tid för din ansökan.\n\nTid: {booking_time}\nPlats/form: {booking_location}\n\n{admin_comment}\n\n{status_link}"),
            'inspection_completed' => array('label' => 'Inspektion genomförd', 'subject' => 'Inspektionen är genomförd - {ship_name}', 'body' => "Hej {applicant_name},\n\nInspektionsunderlaget för {ship_name} är klart. SSF återkommer när nästa steg är beslutat.\n\n{status_link}"),
            'approved' => array('label' => 'Beslut: godkänd', 'subject' => 'Din ansökan till SSF är godkänd', 'body' => "Hej {applicant_name},\n\nVi är glada att meddela att ansökan för {ship_name} har godkänts av Sveriges Segelfartygsförbund.\n\nNästa steg: {next_step}\n\n{status_link}\n\nVälkommen till SSF!"),
            'approved_aspirant' => array('label' => 'Beslut: godkänd som aspirant', 'subject' => 'Din ansökan till SSF är godkänd som aspirant', 'body' => "Hej {applicant_name},\n\nAnsökan för {ship_name} har godkänts som aspirant.\n\n{admin_comment}\n\n{status_link}"),
            'rejected' => array('label' => 'Beslut: avslagen', 'subject' => 'Beslut om din ansökan till SSF', 'body' => "Hej {applicant_name},\n\nSSF har fattat beslut om ansökan för {ship_name}.\n\n{admin_comment}\n\n{status_link}"),
            'reminder' => array('label' => 'Påminnelse till sökanden', 'subject' => 'Påminnelse om din ansökan till SSF', 'body' => "Hej {applicant_name},\n\nDet finns en uppdatering i ditt ärende för {ship_name}.\n\n{admin_comment}\n\n{status_link}"),
            'admin_notice' => array('label' => 'Intern notis till admin', 'subject' => 'Ny ansökan till SSF - {ship_name}', 'body' => "En ny ansökan har skickats in.\n\nÄrende: {application_id}\nFartyg: {ship_name}\nSökande: {applicant_name}\n\nÖppna ärendet i WordPress."),
        );
    }

    public function send_received(int $application_id, string $token): bool
    {
        $applicant_sent = $this->send_template('received', $application_id, array('status_link' => SSF_Medlemsprocess_Application::status_link($token)));
        $this->send_template('admin_notice', $application_id, array(), true);
        return $applicant_sent;
    }

    public function send_status_email(int $application_id, string $status, string $message = ''): void
    {
        $map = array(
            'needs_completion' => 'completion_required', 'completion_submitted' => 'completion_received',
            'inspection_completed' => 'inspection_completed', 'approved' => 'approved',
            'approved_aspirant' => 'approved_aspirant', 'rejected' => 'rejected',
        );
        if (isset($map[$status])) {
            $token = SSF_Medlemsprocess_Application::issue_token($application_id);
            $this->send_template($map[$status], $application_id, array('admin_comment' => $message, 'status_link' => SSF_Medlemsprocess_Application::status_link($token)));
        }
    }

    public function send_booking(int $application_id, array $booking): void
    {
        $time = trim(($booking['date'] ?? '') . ' ' . ($booking['start'] ?? '') . (! empty($booking['end']) ? ' - ' . $booking['end'] : ''));
        $token = SSF_Medlemsprocess_Application::issue_token($application_id);
        $this->send_template('booking', $application_id, array('booking_time' => $time, 'booking_location' => $booking['location'] ?? '', 'admin_comment' => $booking['comment'] ?? '', 'status_link' => SSF_Medlemsprocess_Application::status_link($token)));
    }

    public function send_inspector_assignment(int $application_id, WP_User $inspector): bool
    {
        if (! is_email($inspector->user_email)) {
            return false;
        }
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $deadline = (string) get_post_meta($application_id, '_ssf_inspector_deadline', true);
        $task = (string) get_post_meta($application_id, '_ssf_inspector_task', true);
        $body = "Hej " . $inspector->display_name . ",\n\nDu har tilldelats en inspektion för " . ($data['ship_name'] ?? get_the_title($application_id)) . ".\n\n";
        if ($deadline) {
            $body .= "Önskat klart-datum: " . $deadline . "\n";
        }
        if ($task) {
            $body .= "Uppdrag:\n" . $task . "\n";
        }
        $body .= "\nÖppna ärendet här:\n" . SSF_Medlemsprocess_Plugin::instance()->inspector->case_url($application_id) . "\n\nVänliga hälsningar\nSveriges Segelfartygsförbund";
        $sent = wp_mail($inspector->user_email, 'Ny inspektion tilldelad: ' . ($data['ship_name'] ?? get_the_title($application_id)), $body, array('Content-Type: text/plain; charset=UTF-8'));
        SSF_Medlemsprocess_Application::add_history($application_id, 'email', 'E-post om inspektörstilldelning ' . ($sent ? 'skickades.' : 'kunde inte skickas.'), false);
        return $sent;
    }

    public function send_inspection_complete(int $application_id): bool
    {
        $settings = SSF_Medlemsprocess_Plugin::settings();
        $recipient = (string) ($settings['admin_email'] ?? '');
        if (! is_email($recipient)) {
            return false;
        }
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $number = (string) get_post_meta($application_id, '_ssf_application_number', true);
        $edit_url = get_edit_post_link($application_id, '');
        $body = "Inspektionsrapporterna för ärendet är klara.\n\nÄrende: " . $number . "\nFartyg: " . ($data['ship_name'] ?? get_the_title($application_id)) . "\n\nÖppna ärendet i WordPress:\n" . $edit_url;
        $sent = wp_mail($recipient, 'Inspektionsrapport klar: ' . ($data['ship_name'] ?? get_the_title($application_id)), $body, array('Content-Type: text/plain; charset=UTF-8'));
        SSF_Medlemsprocess_Application::add_history($application_id, 'email', 'E-post om färdig inspektionsrapport ' . ($sent ? 'skickades till handläggare.' : 'kunde inte skickas.'), false);
        return $sent;
    }

    public function send_template(string $key, int $application_id, array $variables = array(), bool $admin_recipient = false): bool
    {
        $templates = self::templates();
        if (! isset($templates[$key])) {
            return false;
        }
        $settings = SSF_Medlemsprocess_Plugin::settings();
        $override = (array) ($settings['templates'][$key] ?? array());
        $template = array_merge($templates[$key], array_filter($override, 'is_string'));
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $defaults = array(
            'applicant_name' => $data['applicant_name'] ?? '',
            'applicant_email' => $data['applicant_email'] ?? '',
            'ship_name' => $data['ship_name'] ?? get_the_title($application_id),
            'application_id' => get_post_meta($application_id, '_ssf_application_number', true),
            'application_status' => SSF_Medlemsprocess_Application::status_label(SSF_Medlemsprocess_Application::status($application_id)),
            'status_link' => '',
            'admin_comment' => '',
            'next_step' => get_post_meta($application_id, '_ssf_next_action', true),
            'booking_time' => '',
            'booking_location' => '',
            'decision' => '',
            'decision_comment' => get_post_meta($application_id, '_ssf_decision_public_reason', true),
        );
        $variables = array_merge($defaults, $variables);
        $replace = array();
        foreach ($variables as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }
        $recipient = $admin_recipient ? $settings['admin_email'] : ($data['applicant_email'] ?? '');
        if (! is_email($recipient)) {
            return false;
        }
        $sent = wp_mail($recipient, strtr($template['subject'], $replace), strtr($template['body'], $replace), array('Content-Type: text/plain; charset=UTF-8'));
        SSF_Medlemsprocess_Application::add_history($application_id, 'email', sprintf('E-postmall "%s" %s.', $template['label'], $sent ? 'skickad' : 'kunde inte skickas'), false);
        return $sent;
    }
}
