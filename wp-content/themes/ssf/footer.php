</main>
<footer class="site-footer">
    <div class="site-footer__inner">
        <?php $ssf_organization = class_exists('SSF_Organization_Info') ? SSF_Organization_Info::get() : array(); ?>
        <div class="site-footer__organization">
            <strong><?php echo esc_html($ssf_organization['organization_name'] ?? get_bloginfo('name')); ?></strong>
            <?php if ($ssf_organization) : ?>
                <address><?php foreach (SSF_Organization_Info::address_lines() as $line) : ?><?php echo esc_html($line); ?><br><?php endforeach; ?></address>
                <p>Org.nr: <?php echo esc_html($ssf_organization['organization_number']); ?><br>Bankgiro: <?php echo esc_html($ssf_organization['bankgiro']); ?><br>Swish: <?php echo esc_html($ssf_organization['swish']); ?></p>
                <p><a href="<?php echo esc_url($ssf_organization['website_url']); ?>"><?php echo esc_html($ssf_organization['website_label']); ?></a></p>
            <?php endif; ?>
        </div>
        <div class="site-footer__secondary">
            <div class="site-footer__meta">
                <p>&copy; <?php echo esc_html(date_i18n('Y')); ?> <?php bloginfo('name'); ?></p>
            <?php if (class_exists('SSF_Release_Manager')) : ?>
                <p class="site-footer__release"><?php echo esc_html(SSF_Release_Manager::get_display_string()); ?></p>
            <?php endif; ?>
            </div>
            <nav class="site-footer__nav" aria-label="<?php esc_attr_e('Sidfotsmeny', 'ssf'); ?>">
                <?php
                wp_nav_menu(
                    array(
                        'theme_location' => 'footer',
                        'container'      => false,
                        'fallback_cb'    => false,
                        'depth'          => 1,
                    )
                );
                ?>
            </nav>
        </div>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
