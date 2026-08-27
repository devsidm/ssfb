<?php

namespace SSF\MemberPortal\Core;

use SSF\MemberPortal\Modules\Motions\MotionPostType;
use SSF\MemberPortal\Modules\AnnualMeetings\RegistrationPostType;

if (! defined('ABSPATH')) {
    exit;
}

final class Privacy
{
    public function hooks(): void
    {
        add_filter('wp_privacy_personal_data_exporters', array($this, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array($this, 'register_eraser'));
    }

    public function register_exporter(array $exporters): array
    {
        $exporters['ssf-member-portal'] = array('exporter_friendly_name' => 'SSF Medlemsportal', 'callback' => array($this, 'exporter'));
        return $exporters;
    }

    public function register_eraser(array $erasers): array
    {
        $erasers['ssf-member-portal'] = array('eraser_friendly_name' => 'SSF Medlemsportal', 'callback' => array($this, 'eraser'));
        return $erasers;
    }

    public function exporter(string $email, int $page = 1): array
    {
        $motions = get_posts(array('post_type' => MotionPostType::POST_TYPE, 'post_status' => 'any', 'meta_key' => '_ssf_mp_submitter_email', 'meta_value' => sanitize_email($email), 'posts_per_page' => 50, 'paged' => $page));
        $data = array();
        foreach ($motions as $motion) {
            $data[] = array('group_id' => 'ssf-motions', 'group_label' => 'SSF Motioner', 'item_id' => 'ssf-motion-' . $motion->ID, 'data' => array(
                array('name' => 'Motion', 'value' => $motion->post_title),
                array('name' => 'Inskickad', 'value' => wp_date('c', (int) get_post_meta($motion->ID, '_ssf_mp_submitted_at', true))),
                array('name' => 'Status', 'value' => (string) get_post_meta($motion->ID, '_ssf_mp_status', true)),
            ));
        }
        $registrations = get_posts(array('post_type' => RegistrationPostType::POST_TYPE, 'post_status' => 'private', 'meta_key' => '_ssf_am_email', 'meta_value' => sanitize_email($email), 'posts_per_page' => 50, 'paged' => $page));
        foreach ($registrations as $registration) {
            $data[] = array('group_id' => 'ssf-annual-meetings', 'group_label' => 'SSF Årsmöten', 'item_id' => 'ssf-annual-meeting-registration-' . $registration->ID, 'data' => array(
                array('name' => 'Årsmöte', 'value' => get_the_title((int) $registration->post_parent)),
                array('name' => 'Namn', 'value' => trim((string) get_post_meta($registration->ID, '_ssf_am_first_name', true) . ' ' . (string) get_post_meta($registration->ID, '_ssf_am_last_name', true))),
                array('name' => 'Telefon', 'value' => (string) get_post_meta($registration->ID, '_ssf_am_phone', true)),
                array('name' => 'Status', 'value' => (string) get_post_meta($registration->ID, '_ssf_am_status', true)),
                array('name' => 'Anmäld', 'value' => wp_date('c', (int) get_post_meta($registration->ID, '_ssf_am_submitted_at', true))),
            ));
        }
        return array('data' => $data, 'done' => count($motions) < 50 && count($registrations) < 50);
    }

    public function eraser(string $email, int $page = 1): array
    {
        $registrations = get_posts(array('post_type' => RegistrationPostType::POST_TYPE, 'post_status' => 'private', 'meta_key' => '_ssf_am_email', 'meta_value' => sanitize_email($email), 'posts_per_page' => 50, 'paged' => $page));
        foreach ($registrations as $registration) {
            foreach (array('email', 'phone', 'food', 'food_note', 'answers', 'represented_vessels', 'associated_vessels', 'token_hash') as $key) {
                delete_post_meta($registration->ID, '_ssf_am_' . $key);
            }
            update_post_meta($registration->ID, '_ssf_am_first_name', __('Anonymiserad', 'ssf-member-portal'));
            update_post_meta($registration->ID, '_ssf_am_last_name', __('deltagare', 'ssf-member-portal'));
            update_post_meta($registration->ID, '_ssf_am_anonymised_at', gmdate('c'));
        }
        return array('items_removed' => ! empty($registrations), 'items_retained' => true, 'messages' => array(__('Årsmötesanmälningar har anonymiserats. Motioner bevaras som föreningshandlingar och bedöms manuellt vid behov.', 'ssf-member-portal')), 'done' => count($registrations) < 50);
    }
}
