<?php
/**
 * Owner dashboard list.
 *
 * @package SSF_Medlemsfartyg
 */
?>
<div class="wrap ssf-admin-page">
    <h1><?php esc_html_e('Mina fartyg', 'ssf-medlemsfartyg'); ?></h1>
    <?php if (empty($ids)) : ?>
        <p><?php esc_html_e('Du har inga fartyg kopplade till ditt konto ännu.', 'ssf-medlemsfartyg'); ?></p>
    <?php else : ?>
        <div class="ssf-owner-grid">
            <?php foreach ($ids as $ship_id) : ?>
                <article class="ssf-owner-card">
                    <?php echo get_the_post_thumbnail($ship_id, 'thumbnail'); ?>
                    <h2><?php echo esc_html(get_the_title($ship_id)); ?></h2>
                    <p><?php echo esc_html(SSF_Medlemsfartyg_Shortcodes::terms_label($ship_id, 'fartygsstatus')); ?></p>
                    <p><?php echo esc_html(sprintf(__('Senast uppdaterad %s', 'ssf-medlemsfartyg'), get_the_modified_date('', $ship_id))); ?></p>
                    <a class="button button-primary" href="<?php echo esc_url(add_query_arg(array('page' => 'ssf-mina-fartyg', 'ship_id' => $ship_id), admin_url('admin.php'))); ?>"><?php esc_html_e('Redigera fartygssida', 'ssf-medlemsfartyg'); ?></a>
                    <a class="button" href="<?php echo esc_url(get_permalink($ship_id)); ?>"><?php esc_html_e('Visa publik sida', 'ssf-medlemsfartyg'); ?></a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
