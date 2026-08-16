<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="site-header">
    <div class="site-header__inner">
        <a class="site-title" href="<?php echo esc_url(home_url('/')); ?>">
            <span class="site-title__mark">SSF</span>
            <span><?php bloginfo('name'); ?></span>
        </a>
        <button class="site-menu-toggle" type="button" aria-expanded="false" aria-controls="primary-menu">
            <span><?php esc_html_e('Meny', 'ssf'); ?></span>
        </button>
        <nav class="site-nav" aria-label="<?php esc_attr_e('Primary menu', 'ssf'); ?>">
            <?php
            wp_nav_menu(
                array(
                    'theme_location' => 'primary',
                    'container'      => false,
                    'fallback_cb'    => false,
                    'menu_id'        => 'primary-menu',
                )
            );
            ?>
        </nav>
    </div>
</header>
<main class="site-main">
