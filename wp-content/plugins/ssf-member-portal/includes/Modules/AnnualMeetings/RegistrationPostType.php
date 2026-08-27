<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

if (! defined('ABSPATH')) {
    exit;
}

final class RegistrationPostType
{
    public const POST_TYPE = 'ssf_meeting_registration';

    public function register(): void
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Anmälningar', 'ssf-member-portal'),
                'singular_name' => __('Anmälan', 'ssf-member-portal'),
                'edit_item' => __('Redigera anmälan', 'ssf-member-portal'),
                'search_items' => __('Sök anmälningar', 'ssf-member-portal'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title', 'revisions'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));
    }
}
