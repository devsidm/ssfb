<?php

namespace SSF\MemberPortal\Modules\Motions;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionPostType
{
    public const POST_TYPE = 'ssf_motion';

    public function register(): void
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array('name' => __('Motioner', 'ssf-member-portal'), 'singular_name' => __('Motion', 'ssf-member-portal'), 'edit_item' => __('Granska motion', 'ssf-member-portal')),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title', 'editor', 'revisions'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));

        foreach (array('_ssf_mp_meeting_id', '_ssf_mp_meeting_year', '_ssf_mp_submission_open_at', '_ssf_mp_submission_deadline_at', '_ssf_mp_submitted_at', '_ssf_mp_submitted_after_deadline', '_ssf_mp_member_user_id') as $key) {
            register_post_meta(self::POST_TYPE, $key, array('type' => 'integer', 'single' => true, 'show_in_rest' => true, 'auth_callback' => array(MotionPermissions::class, 'can_manage')));
        }
        foreach (array('_ssf_mp_status', '_ssf_mp_submitter_name', '_ssf_mp_submitter_email', '_ssf_mp_submitter_phone', '_ssf_mp_motion_number') as $key) {
            register_post_meta(self::POST_TYPE, $key, array('type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => array(MotionPermissions::class, 'can_manage')));
        }
    }
}
