<?php
/**
 * Email notifications.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Notifications
{
    public function send_owner_update_notice(int $ship_id, int $user_id): void
    {
        $settings = SSF_Medlemsfartyg_Plugin::settings();
        $user = get_userdata($user_id);
        $body = sprintf(
            "Fartyg: %s\nAnvändare: %s\nTidpunkt: %s\nAdmin: %s\nPublik sida: %s",
            get_the_title($ship_id),
            $user ? $user->display_name : __('Okänd användare', 'ssf-medlemsfartyg'),
            wp_date('Y-m-d H:i'),
            get_edit_post_link($ship_id, ''),
            get_permalink($ship_id)
        );

        wp_mail(
            $settings['admin_email'],
            'Fartygssida uppdaterad - ' . get_the_title($ship_id),
            $body,
            array('Content-Type: text/plain; charset=UTF-8')
        );
    }
}
