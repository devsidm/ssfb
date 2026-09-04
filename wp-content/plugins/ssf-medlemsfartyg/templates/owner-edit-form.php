<?php
/**
 * Logged-in vessel representative profile editor.
 *
 * @package SSF_Medlemsfartyg
 */

$route = (string) get_post_meta($ship_id, '_ssf_application_route', true);
$values = SSF_Medlemsfartyg_Profile::values($ship_id, $route, SSF_Medlemsfartyg_Profile::MODE_PORTAL);
?>
<div class="wrap ssf-admin-page">
    <h1><?php echo esc_html(sprintf(__('Redigera %s', 'ssf-medlemsfartyg'), get_the_title($ship_id))); ?></h1>
    <?php if (! empty($_GET['updated'])) : ?><div class="notice notice-success"><p><?php esc_html_e('Ändringarna är sparade.', 'ssf-medlemsfartyg'); ?></p></div><?php endif; ?>
    <?php if ($route) : ?><p><strong><?php esc_html_e('Ansökningsväg:', 'ssf-medlemsfartyg'); ?></strong> <?php echo esc_html(SSF_Medlemsfartyg_Profile::route_label($route)); ?></p><?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ssf-owner-form" data-ssf-vessel-profile="portal">
        <input type="hidden" name="action" value="ssf_save_owner_ship">
        <input type="hidden" name="ship_id" value="<?php echo esc_attr((string) $ship_id); ?>">
        <?php wp_nonce_field('ssf_save_owner_ship_' . $ship_id); ?>
        <?php SSF_Medlemsfartyg_Profile::render(SSF_Medlemsfartyg_Profile::MODE_PORTAL, $route, $values); ?>
        <?php submit_button(__('Spara ändringar', 'ssf-medlemsfartyg')); ?>
    </form>
</div>
