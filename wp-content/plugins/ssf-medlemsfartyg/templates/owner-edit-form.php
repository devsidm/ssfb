<?php
/**
 * Owner edit form.
 *
 * @package SSF_Medlemsfartyg
 */
?>
<div class="wrap ssf-admin-page">
    <h1><?php echo esc_html(sprintf(__('Redigera %s', 'ssf-medlemsfartyg'), get_the_title($ship_id))); ?></h1>
    <?php if (! empty($_GET['updated'])) : ?><div class="notice notice-success"><p><?php esc_html_e('Ändringarna är sparade.', 'ssf-medlemsfartyg'); ?></p></div><?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ssf-owner-form">
        <input type="hidden" name="action" value="ssf_save_owner_ship">
        <input type="hidden" name="ship_id" value="<?php echo esc_attr((string) $ship_id); ?>">
        <?php wp_nonce_field('ssf_save_owner_ship_' . $ship_id); ?>
        <section><h2><?php esc_html_e('Grunduppgifter', 'ssf-medlemsfartyg'); ?></h2><label><?php esc_html_e('Fartygets namn', 'ssf-medlemsfartyg'); ?><input name="post_title" value="<?php echo esc_attr(get_the_title($ship_id)); ?>"></label><label><?php esc_html_e('Kort utdrag', 'ssf-medlemsfartyg'); ?><textarea name="post_excerpt"><?php echo esc_textarea($post->post_excerpt); ?></textarea></label></section>
        <section><h2><?php esc_html_e('Presentation', 'ssf-medlemsfartyg'); ?></h2><label><?php esc_html_e('Om fartyget', 'ssf-medlemsfartyg'); ?><textarea name="post_content" rows="8"><?php echo esc_textarea($post->post_content); ?></textarea></label></section>
        <?php foreach (SSF_Medlemsfartyg_Meta::fields() as $section) : ?>
            <section><h2><?php echo esc_html($section['label']); ?></h2>
                <?php foreach ($section['fields'] as $key => $field) : $value = get_post_meta($ship_id, $key, true); ?>
                    <label><?php echo esc_html($field['label']); ?>
                    <?php if (in_array($field['type'], array('textarea', 'wysiwyg'), true)) : ?>
                        <textarea name="<?php echo esc_attr($key); ?>" rows="5"><?php echo esc_textarea((string) $value); ?></textarea>
                    <?php elseif ('checkbox' === $field['type']) : ?>
                        <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked('1', (string) $value); ?>>
                    <?php else : ?>
                        <input type="<?php echo esc_attr(in_array($field['type'], array('email', 'url', 'number'), true) ? $field['type'] : 'text'); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr((string) $value); ?>">
                    <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
        <?php submit_button(__('Spara ändringar', 'ssf-medlemsfartyg')); ?>
    </form>
</div>
