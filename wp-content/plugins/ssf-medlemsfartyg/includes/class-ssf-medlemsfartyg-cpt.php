<?php
/**
 * Post types and taxonomies.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_CPT
{
    public const POST_TYPE = 'medlemsfartyg';

    public function __construct()
    {
        add_action('init', array($this, 'register'));
        add_action('init', array($this, 'seed_terms'));
    }

    public function register(): void
    {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name' => __('Medlemsfartyg', 'ssf-medlemsfartyg'),
                    'singular_name' => __('Medlemsfartyg', 'ssf-medlemsfartyg'),
                    'add_new_item' => __('Lägg till medlemsfartyg', 'ssf-medlemsfartyg'),
                    'edit_item' => __('Redigera medlemsfartyg', 'ssf-medlemsfartyg'),
                    'view_item' => __('Visa fartyg', 'ssf-medlemsfartyg'),
                    'search_items' => __('Sök medlemsfartyg', 'ssf-medlemsfartyg'),
                ),
                'public' => true,
                'has_archive' => true,
                'show_in_rest' => true,
                'menu_icon' => 'dashicons-sos',
                'supports' => array('title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author'),
                'rewrite' => array('slug' => 'medlemsfartyg', 'with_front' => false),
                'capability_type' => array('ssf_ship', 'ssf_ships'),
                'map_meta_cap' => true,
            )
        );

        $taxonomies = array(
            'fartygstyp' => __('Fartygstyp', 'ssf-medlemsfartyg'),
            'fartygsstatus' => __('Status', 'ssf-medlemsfartyg'),
            'fartygsanvandning' => __('Användning', 'ssf-medlemsfartyg'),
            'fartygsregion' => __('Hemmahamn / Region', 'ssf-medlemsfartyg'),
        );

        foreach ($taxonomies as $slug => $label) {
            register_taxonomy(
                $slug,
                self::POST_TYPE,
                array(
                    'labels' => array(
                        'name' => $label,
                        'singular_name' => $label,
                    ),
                    'public' => true,
                    'show_admin_column' => true,
                    'show_in_rest' => true,
                    'hierarchical' => true,
                    'rewrite' => array('slug' => $slug),
                )
            );
        }
    }

    public function seed_terms(): void
    {
        $terms = array(
            'fartygstyp' => array('Skonare', 'Kutter', 'Galeas', 'Brigg', 'Bark', 'Jakt', 'Övrigt'),
            'fartygsstatus' => array('Anslutet medlemsfartyg', 'Aspirant', 'Under restaurering', 'Vilande'),
            'fartygsanvandning' => array('Seglar med ungdomar', 'Charter', 'Kulturarrangemang', 'Utbildning', 'Privat bruk', 'Museiverksamhet', 'Under restaurering'),
            'fartygsregion' => array('Stockholm', 'Göteborg', 'Malmö', 'Skärgården', 'Ostkusten', 'Västkusten', 'Gotland', 'Norrland', 'Annan region'),
        );

        foreach ($terms as $taxonomy => $names) {
            foreach ($names as $name) {
                if (! term_exists($name, $taxonomy)) {
                    wp_insert_term($name, $taxonomy);
                }
            }
        }
    }
}
