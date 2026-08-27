<?php
/**
 * Editorial landing pages and the curated SSF navigation structure.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

function ssf_site_current_content_shortcode(): string
{
    ob_start();
    ?>
    <section class="ssf-page-section">
        <p>Här samlar vi nyheter, nyhetsbrev och aktiviteter från Sveriges Segelfartygsförbund.</p>
        <div class="ssf-card-grid ssf-card-grid--three">
            <?php echo ssf_site_feature_card('Nyheter', 'Följ det som händer i förbundet.', home_url('/nyheter/')); ?>
            <?php echo ssf_site_feature_card('Nyhetsbrev', 'Läs senaste utgåvan och tidigare nyhetsbrev.', get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE)); ?>
            <?php echo ssf_site_feature_card('Kalender', 'Se kommande aktiviteter och viktiga datum.', home_url('/kalender/')); ?>
        </div>
    </section>
    <section class="ssf-page-section ssf-section--blue">
        <div class="ssf-wrap">
            <h2>Senaste nyheterna</h2>
            <?php echo do_shortcode('[ssf_news_cards count="3"]'); ?>
        </div>
    </section>
    <section class="ssf-page-section">
        <h2>Senaste nyhetsbrevet</h2>
        <?php echo do_shortcode('[ssf_latest_newsletter]'); ?>
    </section>
    <?php
    return (string) ob_get_clean();
}
add_shortcode('ssf_current_content', 'ssf_site_current_content_shortcode');

function ssf_site_member_resources_shortcode(): string
{
    ob_start();
    ?>
    <section class="ssf-page-section">
        <p>Här samlas sådant som är särskilt relevant för SSF:s medlemmar och fartygsombud.</p>
        <div class="ssf-card-grid ssf-card-grid--three">
            <?php echo ssf_site_feature_card('Årsmöten', 'Information och underlag inför föreningens årsmöten.', home_url('/arsmoten/')); ?>
            <?php echo ssf_site_feature_card('Medlemsinformation', 'Praktisk information för dig som är medlem.', home_url('/medlemsinformation/')); ?>
            <?php echo ssf_site_feature_card('Lämna motion', 'Skicka in en motion till nästa årsmöte.', home_url('/lamna-motion/')); ?>
        </div>
    </section>
    <?php
    return (string) ob_get_clean();
}
add_shortcode('ssf_member_resources', 'ssf_site_member_resources_shortcode');

function ssf_site_information_pages(): array
{
    $member_vessels_url = esc_url(home_url('/medlemsfartyg/'));
    $traditional_vessels_url = esc_url(home_url('/om-traditionsfartyg/'));
    $motion_url = esc_url(home_url('/lamna-motion/'));

    return array(
        'forbundet' => array(
            'title' => 'Förbundet',
            'content' => '<p>Sveriges Segelfartygsförbund samlar människor och fartyg som vill bevara, bruka och utveckla det seglande kulturarvet.</p><h2>Om förbundet</h2><p>Här hittar du information om SSF, styrelsen, stadgar och hur du kontaktar oss.</p>',
        ),
        'styrelsen' => array(
            'title' => 'Styrelsen',
            'content' => '<p>SSF:s styrelse ansvarar för förbundets löpande arbete och utveckling. Aktuella kontaktuppgifter och uppdrag publiceras här.</p>',
        ),
        'fartyg' => array(
            'title' => 'Fartyg',
            'content' => sprintf('<p>SSF verkar för att traditionella segelfartyg ska kunna vårdas, användas och upplevas också i framtiden.</p><p><a href="%1$s">Se våra medlemsfartyg</a> eller läs <a href="%2$s">om traditionsfartyg</a>.</p>', $member_vessels_url, $traditional_vessels_url),
        ),
        'om-traditionsfartyg' => array(
            'title' => 'Om traditionsfartyg',
            'content' => '<p>Traditionsfartyg bär kunskap om byggnadssätt, hantverk och livet till sjöss. De ska kunna seglas och brukas, samtidigt som deras kulturhistoriska värden tas till vara.</p>',
        ),
        'aktuellt' => array(
            'title' => 'Aktuellt',
            'content' => '[ssf_current_content]',
        ),
        'kalender' => array(
            'title' => 'Kalender',
            'content' => '<p>Här publicerar SSF kommande aktiviteter, möten och viktiga datum.</p>',
        ),
        'for-medlemmar' => array(
            'title' => 'För medlemmar',
            'content' => '[ssf_member_resources]',
        ),
        'arsmoten' => array(
            'title' => 'Årsmöten',
            'content' => '<p>Här samlar SSF kallelser, handlingar och information inför föreningens årsmöten.</p>',
        ),
        'medlemsinformation' => array(
            'title' => 'Medlemsinformation',
            'content' => '<p>Här hittar du praktisk information för SSF:s medlemmar och fartygsombud.</p>',
        ),
    );
}

function ssf_site_ensure_information_pages(): array
{
    $page_ids = array();
    foreach (ssf_site_information_pages() as $slug => $page) {
        $existing = get_page_by_path($slug);
        if ($existing) {
            $page_ids[$slug] = (int) $existing->ID;
            continue;
        }

        $page_ids[$slug] = (int) wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_name'    => $slug,
                'post_title'   => $page['title'],
                'post_content' => $page['content'],
            )
        );
    }
    return $page_ids;
}

function ssf_site_find_page_id(string $slug, array $created_pages = array()): int
{
    if (isset($created_pages[$slug])) {
        return (int) $created_pages[$slug];
    }
    $page = get_page_by_path($slug);
    return $page ? (int) $page->ID : 0;
}

function ssf_site_sync_menu(string $name, array $items): int
{
    $menu = wp_get_nav_menu_object($name);
    $menu_id = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu($name);
    if (! $menu_id) {
        return 0;
    }

    $existing_items = wp_get_nav_menu_items($menu_id, array('post_status' => 'any'));
    foreach ((array) $existing_items as $item) {
        wp_delete_post((int) $item->ID, true);
    }

    $inserted = array();
    foreach ($items as $key => $item) {
        $parent = ! empty($item['parent']) && isset($inserted[$item['parent']]) ? $inserted[$item['parent']] : 0;
        $args = array(
            'menu-item-title'     => $item['title'],
            'menu-item-status'    => 'publish',
            'menu-item-parent-id' => $parent,
        );
        if (! empty($item['page_id'])) {
            $args['menu-item-type']      = 'post_type';
            $args['menu-item-object']    = 'page';
            $args['menu-item-object-id'] = (int) $item['page_id'];
        } else {
            $args['menu-item-type'] = 'custom';
            $args['menu-item-url']  = $item['url'];
        }
        $inserted[$key] = (int) wp_update_nav_menu_item($menu_id, 0, $args);
    }

    return $menu_id;
}

function ssf_site_sync_information_architecture(): array
{
    $pages = ssf_site_ensure_information_pages();
    $page = static function (string $slug) use ($pages): int {
        return ssf_site_find_page_id($slug, $pages);
    };

    $primary_menu_id = ssf_site_sync_menu(
        'SSF huvudmeny',
        array(
            'start' => array('title' => 'Start', 'url' => home_url('/')),
            'forbundet' => array('title' => 'Förbundet', 'page_id' => $page('forbundet')),
            'om-ssf' => array('title' => 'Om SSF', 'page_id' => $page('om-ssf'), 'parent' => 'forbundet'),
            'styrelsen' => array('title' => 'Styrelsen', 'page_id' => $page('styrelsen'), 'parent' => 'forbundet'),
            'stadgar' => array('title' => 'Stadgar & dokument', 'page_id' => $page('stadgar'), 'parent' => 'forbundet'),
            'kontakt' => array('title' => 'Kontakt', 'page_id' => $page('kontakta-oss'), 'parent' => 'forbundet'),
            'fartyg' => array('title' => 'Fartyg', 'page_id' => $page('fartyg')),
            'medlemsfartyg' => array('title' => 'Medlemsfartyg', 'url' => home_url('/medlemsfartyg/'), 'parent' => 'fartyg'),
            'traditionsfartyg' => array('title' => 'Om traditionsfartyg', 'page_id' => $page('om-traditionsfartyg'), 'parent' => 'fartyg'),
            'medlemskap' => array('title' => 'Medlemskap', 'page_id' => $page('medlemskap')),
            'bli-medlem' => array('title' => 'Medlemskap', 'page_id' => $page('medlemskap'), 'parent' => 'medlemskap'),
            'ansokan' => array('title' => 'Ansökan', 'page_id' => $page('ansokan'), 'parent' => 'medlemskap'),
            'aktuellt' => array('title' => 'Aktuellt', 'page_id' => $page('aktuellt')),
            'nyheter' => array('title' => 'Nyheter', 'page_id' => $page('nyheter'), 'parent' => 'aktuellt'),
            'nyhetsbrev' => array('title' => 'Nyhetsbrev', 'url' => get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE), 'parent' => 'aktuellt'),
            'kalender' => array('title' => 'Kalender', 'page_id' => $page('kalender'), 'parent' => 'aktuellt'),
            'medlemmar' => array('title' => 'För medlemmar', 'page_id' => $page('for-medlemmar')),
            'arsmoten' => array('title' => 'Årsmöten', 'page_id' => $page('arsmoten'), 'parent' => 'medlemmar'),
            'medlemsinformation' => array('title' => 'Medlemsinformation', 'page_id' => $page('medlemsinformation'), 'parent' => 'medlemmar'),
        )
    );

    $footer_menu_id = ssf_site_sync_menu(
        'SSF sidfot',
        array(
            'forbundet' => array('title' => 'Förbundet', 'page_id' => $page('forbundet')),
            'medlemskap' => array('title' => 'Medlemskap', 'page_id' => $page('medlemskap')),
            'medlemsfartyg' => array('title' => 'Medlemsfartyg', 'url' => home_url('/medlemsfartyg/')),
            'nyheter' => array('title' => 'Nyheter', 'page_id' => $page('nyheter')),
            'nyhetsbrev' => array('title' => 'Nyhetsbrev', 'url' => get_post_type_archive_link(SSF_SITE_NEWSLETTER_POST_TYPE)),
            'kontakt' => array('title' => 'Kontakt', 'page_id' => $page('kontakta-oss')),
        )
    );

    $locations = (array) get_theme_mod('nav_menu_locations', array());
    $locations['primary'] = $primary_menu_id;
    $locations['footer'] = $footer_menu_id;
    set_theme_mod('nav_menu_locations', $locations);
    flush_rewrite_rules(false);

    return array(
        'pages'        => $pages,
        'primary_menu' => $primary_menu_id,
        'footer_menu'  => $footer_menu_id,
    );
}

function ssf_site_sync_information_architecture_route(): WP_REST_Response
{
    $result = ssf_site_sync_information_architecture();
    return new WP_REST_Response(array('success' => true, 'result' => $result));
}
