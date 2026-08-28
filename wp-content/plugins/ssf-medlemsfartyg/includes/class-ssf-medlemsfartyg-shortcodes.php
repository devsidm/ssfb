<?php
/**
 * Public shortcodes.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Shortcodes
{
    public function __construct()
    {
        add_shortcode('ssf_medlemsfartyg', array($this, 'archive_shortcode'));
        add_shortcode('ssf_utvalt_fartyg', array($this, 'featured_shortcode'));
        add_shortcode('ssf_fartyg_grid', array($this, 'grid_shortcode'));
    }

    public function archive_shortcode(array $atts = array()): string
    {
        if (! $this->can_display()) {
            return $this->unavailable();
        }
        $settings = SSF_Medlemsfartyg_Plugin::settings();
        $query = $this->build_query($settings);
        $is_shortcode = true;

        ob_start();
        include SSF_Medlemsfartyg_Templates::locate('archive-medlemsfartyg.php');
        return $this->test_banner() . ob_get_clean();
    }

    public function featured_shortcode(array $atts): string
    {
        if (! $this->can_display()) {
            return '';
        }
        $atts = shortcode_atts(array('id' => 0), $atts);
        $post = get_post((int) $atts['id']);
        if (! $post || 'medlemsfartyg' !== $post->post_type) {
            return '';
        }

        ob_start();
        echo '<div class="ssf-ship-featured">';
        $this->render_card($post->ID, true);
        echo '</div>';
        return $this->test_banner() . ob_get_clean();
    }

    public function grid_shortcode(array $atts): string
    {
        if (! $this->can_display()) {
            return '';
        }
        $atts = shortcode_atts(array('antal' => 4, 'status' => ''), $atts);
        $args = array(
            'post_type' => 'medlemsfartyg',
            'post_status' => 'publish',
            'posts_per_page' => (int) $atts['antal'],
            'meta_key' => '_ssf_sort_order',
            'orderby' => array('meta_value_num' => 'ASC', 'title' => 'ASC'),
        );

        if ($atts['status']) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'fartygsstatus',
                    'field' => 'slug',
                    'terms' => sanitize_title($atts['status']),
                ),
            );
        }

        $query = new WP_Query($args);
        ob_start();
        echo '<div class="ssf-ships-grid">';
        while ($query->have_posts()) {
            $query->the_post();
            $this->render_card(get_the_ID());
        }
        echo '</div>';
        wp_reset_postdata();
        return $this->test_banner() . ob_get_clean();
    }

    public function render_card(int $post_id, bool $large = false): void
    {
        include SSF_Medlemsfartyg_Templates::locate('card-medlemsfartyg.php');
    }

    public function build_query(array $settings): WP_Query
    {
        $tax_query = array();
        foreach (array('fartygstyp', 'fartygsstatus', 'fartygsregion', 'fartygsanvandning') as $taxonomy) {
            if (! empty($_GET[$taxonomy])) {
                $tax_query[] = array(
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => sanitize_title(wp_unslash($_GET[$taxonomy])),
                );
            }
        }

        $orderby = 'title';
        $order = 'ASC';
        $meta_key = '';
        $sort = isset($_GET['ssf_sort']) ? sanitize_text_field(wp_unslash($_GET['ssf_sort'])) : 'name';
        if ('newest' === $sort) {
            $orderby = 'date';
            $order = 'DESC';
        } elseif ('build_year' === $sort) {
            $orderby = 'meta_value_num';
            $meta_key = '_ssf_build_year';
        } elseif ('featured' === $sort) {
            $orderby = array('meta_value_num' => 'DESC', 'title' => 'ASC');
            $meta_key = '_ssf_featured_ship';
        }

        $args = array(
            'post_type' => 'medlemsfartyg',
            'post_status' => 'publish',
            'posts_per_page' => (int) $settings['per_page'],
            'paged' => max(1, (int) get_query_var('paged'), (int) ($_GET['ssf_page'] ?? 1)),
            's' => isset($_GET['ssf_search']) ? sanitize_text_field(wp_unslash($_GET['ssf_search'])) : '',
            'orderby' => $orderby,
            'order' => $order,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_ssf_show_in_archive',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key' => '_ssf_show_in_archive',
                    'value' => '0',
                    'compare' => '!=',
                ),
            ),
        );

        if ($meta_key) {
            $args['meta_key'] = $meta_key;
        }
        if ($tax_query) {
            $args['tax_query'] = $tax_query;
        }

        return new WP_Query($args);
    }

    public static function terms_label(int $post_id, string $taxonomy): string
    {
        $terms = get_the_terms($post_id, $taxonomy);
        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        return implode(', ', wp_list_pluck($terms, 'name'));
    }

    public static function field(int $post_id, string $key): string
    {
        return (string) get_post_meta($post_id, $key, true);
    }

    private function can_display(): bool
    {
        return ! class_exists('SSF_Feature_Manager') || SSF_Feature_Manager::can_access('member_vessels');
    }

    private function unavailable(): string
    {
        if (class_exists('SSF_Feature_Manager')) {
            return SSF_Feature_Manager::unavailable_markup('member_vessels');
        }
        return '<p>Medlemsfartygen är inte tillgängliga just nu.</p>';
    }

    private function test_banner(): string
    {
        return class_exists('SSF_Feature_Manager') ? SSF_Feature_Manager::test_mode_banner('member_vessels') : '';
    }
}
