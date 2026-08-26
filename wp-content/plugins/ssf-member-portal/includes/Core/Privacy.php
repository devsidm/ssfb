<?php

namespace SSF\MemberPortal\Core;

use SSF\MemberPortal\Modules\Motions\MotionPostType;

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
        return array('data' => $data, 'done' => count($motions) < 50);
    }

    public function eraser(string $email, int $page = 1): array
    {
        // Motionshandlingar kan omfattas av föreningens arkivkrav och anonymiseras inte automatiskt.
        return array('items_removed' => false, 'items_retained' => true, 'messages' => array(__('Motioner bevaras som föreningshandlingar. Kontakta SSF för en manuell integritetsbedömning.', 'ssf-member-portal')), 'done' => true);
    }
}
