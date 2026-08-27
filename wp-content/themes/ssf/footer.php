</main>
<footer class="site-footer">
    <div class="site-footer__inner">
        <p>&copy; <?php echo esc_html(date_i18n('Y')); ?> <?php bloginfo('name'); ?></p>
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
</footer>
<?php wp_footer(); ?>
</body>
</html>
