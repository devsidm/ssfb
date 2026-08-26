<?php
/**
 * Document post type, metadata and version handling.
 *
 * @package SSF_Stadgar
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Stadgar_Document
{
    public const POST_TYPE = 'ssf_document';
    public const ARCHIVED_STATUS = 'ssf_archived';

    private bool $updating_status = false;

    public function __construct()
    {
        add_action('init', array($this, 'register'));
        add_action('rest_after_insert_' . self::POST_TYPE, array($this, 'enforce_current_after_rest'), 10, 3);
    }

    public function register(): void
    {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name'               => 'Stadgar & dokument',
                    'singular_name'      => 'Dokument',
                    'menu_name'          => 'Stadgar & dokument',
                    'add_new'            => 'Lägg till dokument',
                    'add_new_item'       => 'Lägg till dokument',
                    'edit_item'          => 'Redigera dokument',
                    'new_item'           => 'Nytt dokument',
                    'view_item'          => 'Visa dokument',
                    'search_items'       => 'Sök dokument',
                    'not_found'          => 'Inga dokument hittades.',
                    'all_items'          => 'Alla dokument',
                    'archives'           => 'Dokumentarkiv',
                ),
                'public'             => false,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'show_in_rest'       => true,
                'rest_base'          => 'ssf-documents',
                'menu_icon'          => 'dashicons-media-document',
                'menu_position'      => 26,
                'supports'           => array('title', 'editor', 'revisions', 'custom-fields'),
                'capability_type'    => 'post',
                'map_meta_cap'       => true,
                'has_archive'        => false,
                'rewrite'            => false,
                'exclude_from_search'=> true,
            )
        );

        register_post_status(
            self::ARCHIVED_STATUS,
            array(
                'label'                     => 'Arkiverad',
                'public'                    => false,
                'exclude_from_search'       => true,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop('Arkiverad <span class="count">(%s)</span>', 'Arkiverade <span class="count">(%s)</span>'),
            )
        );

        $this->register_meta('_ssf_document_type', 'string');
        $this->register_meta('_ssf_document_version', 'string');
        $this->register_meta('_ssf_document_adopted_date', 'string');
        $this->register_meta('_ssf_document_adopted_by', 'string');
        $this->register_meta('_ssf_document_summary', 'string');
        $this->register_meta('_ssf_document_change_note', 'string');
        $this->register_meta('_ssf_document_pdf_id', 'integer');
        $this->register_meta('_ssf_document_extracted_text', 'string');
        $this->register_meta('_ssf_document_outline', 'string');
        $this->register_meta('_ssf_document_related_ids', 'array');
        $this->register_meta('_ssf_document_current', 'boolean');
        $this->register_meta('_ssf_document_sort', 'integer');
    }

    private function register_meta(string $key, string $type): void
    {
        $show_in_rest = true;
        if ('array' === $type) {
            $show_in_rest = array(
                'schema' => array(
                    'type'  => 'array',
                    'items' => array('type' => 'integer'),
                ),
            );
        }

        register_post_meta(
            self::POST_TYPE,
            $key,
            array(
                'single'       => true,
                'type'         => $type,
                'show_in_rest' => $show_in_rest,
                'auth_callback' => static function (bool $allowed, string $meta_key, int $post_id): bool {
                    return current_user_can('edit_post', $post_id);
                },
            )
        );
    }

    public static function types(): array
    {
        return array(
            'stadgar'             => 'Stadgar',
            'avgifter'            => 'Avgifter',
            'policy'              => 'Policy',
            'ansokningsunderlag'  => 'Ansökningsunderlag',
            'riktlinje'           => 'Riktlinje',
            'ovrigt'              => 'Övrigt',
        );
    }

    public function type_label(string $type): string
    {
        $types = self::types();
        return $types[$type] ?? $types['ovrigt'];
    }

    public function data(int $document_id): array
    {
        return array(
            'type'         => sanitize_key((string) get_post_meta($document_id, '_ssf_document_type', true)) ?: 'ovrigt',
            'version'      => (string) get_post_meta($document_id, '_ssf_document_version', true),
            'adopted_date' => (string) get_post_meta($document_id, '_ssf_document_adopted_date', true),
            'adopted_by'   => (string) get_post_meta($document_id, '_ssf_document_adopted_by', true),
            'summary'      => (string) get_post_meta($document_id, '_ssf_document_summary', true),
            'change_note'  => (string) get_post_meta($document_id, '_ssf_document_change_note', true),
            'pdf_id'       => (int) get_post_meta($document_id, '_ssf_document_pdf_id', true),
            'extracted_text' => (string) get_post_meta($document_id, '_ssf_document_extracted_text', true),
            'current'      => (bool) get_post_meta($document_id, '_ssf_document_current', true),
            'sort'         => (int) get_post_meta($document_id, '_ssf_document_sort', true),
            'related_ids'  => array_values(array_filter(array_map('absint', (array) get_post_meta($document_id, '_ssf_document_related_ids', true)))),
            'outline'      => $this->outline($document_id),
        );
    }

    public function outline(int $document_id): array
    {
        $outline = json_decode((string) get_post_meta($document_id, '_ssf_document_outline', true), true);
        if (! is_array($outline)) {
            return array();
        }

        $clean = array();
        foreach ($outline as $item) {
            if (! is_array($item) || empty($item['title'])) {
                continue;
            }

            $title = sanitize_text_field((string) $item['title']);
            $anchor = sanitize_title((string) ($item['anchor'] ?? $title));
            if ($title && $anchor) {
                $clean[] = array('title' => $title, 'anchor' => $anchor);
            }
        }

        return $clean;
    }

    public function save_outline(int $document_id, string $raw_outline): array
    {
        $outline = $this->parse_outline($raw_outline);
        update_post_meta($document_id, '_ssf_document_outline', wp_json_encode($outline, JSON_UNESCAPED_UNICODE));
        return $outline;
    }

    public function parse_outline(string $raw_outline): array
    {
        $outline = array();
        $used_anchors = array();
        $lines = preg_split('/\r\n|\r|\n/', $raw_outline) ?: array();

        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            $title = sanitize_text_field($parts[0] ?? '');
            if (! $title) {
                continue;
            }

            $anchor = sanitize_title($parts[1] ?? $title);
            if (! $anchor) {
                continue;
            }

            $base_anchor = $anchor;
            $suffix = 2;
            while (in_array($anchor, $used_anchors, true)) {
                $anchor = $base_anchor . '-' . $suffix;
                ++$suffix;
            }

            $used_anchors[] = $anchor;
            $outline[] = array('title' => $title, 'anchor' => $anchor);
        }

        return $outline;
    }

    public function outline_as_text(int $document_id): string
    {
        $rows = array();
        foreach ($this->outline($document_id) as $item) {
            $rows[] = $item['title'] . ' | ' . $item['anchor'];
        }

        return implode("\n", $rows);
    }

    public function current_statutes(): ?WP_Post
    {
        $documents = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => array(
                    'relation' => 'AND',
                    array('key' => '_ssf_document_type', 'value' => 'stadgar'),
                    array('key' => '_ssf_document_current', 'value' => '1'),
                ),
                'orderby'        => array('menu_order' => 'ASC', 'date' => 'DESC'),
            )
        );

        return $documents[0] ?? null;
    }

    public function history(int $current_document_id = 0): array
    {
        return get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array('publish', self::ARCHIVED_STATUS),
                'posts_per_page' => -1,
                'post__not_in'   => $current_document_id ? array($current_document_id) : array(),
                'meta_query'     => array(
                    array('key' => '_ssf_document_type', 'value' => 'stadgar'),
                ),
                'meta_key'       => '_ssf_document_adopted_date',
                'orderby'        => array('meta_value' => 'DESC', 'date' => 'DESC'),
            )
        );
    }

    public function related_documents(int $document_id): array
    {
        $ids = $this->data($document_id)['related_ids'];
        if (! $ids) {
            return array();
        }

        return get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'post__in'       => $ids,
                'orderby'        => 'post__in',
            )
        );
    }

    public function enforce_current_after_rest(WP_Post $post, WP_REST_Request $request, bool $creating): void
    {
        if ('publish' === $post->post_status) {
            $this->enforce_current($post->ID);
        }
    }

    public function enforce_current(int $document_id): void
    {
        if ($this->updating_status) {
            return;
        }

        $data = $this->data($document_id);
        if ('stadgar' !== $data['type'] || ! $data['current']) {
            return;
        }

        $others = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'post__not_in'   => array($document_id),
                'meta_query'     => array(
                    'relation' => 'AND',
                    array('key' => '_ssf_document_type', 'value' => 'stadgar'),
                    array('key' => '_ssf_document_current', 'value' => '1'),
                ),
            )
        );

        $this->updating_status = true;
        foreach ($others as $other) {
            update_post_meta($other->ID, '_ssf_document_current', '0');
            wp_update_post(array('ID' => $other->ID, 'post_status' => self::ARCHIVED_STATUS));
        }
        $this->updating_status = false;
    }

    public function set_status(int $document_id, string $status): void
    {
        if ($this->updating_status || ! in_array($status, array('draft', 'publish', self::ARCHIVED_STATUS), true)) {
            return;
        }

        $post = get_post($document_id);
        if (! $post instanceof WP_Post || $post->post_status === $status) {
            return;
        }

        $this->updating_status = true;
        wp_update_post(array('ID' => $document_id, 'post_status' => $status));
        $this->updating_status = false;

        if ('publish' === $status) {
            $this->enforce_current($document_id);
        }
    }

    public function pdf_url(int $document_id): string
    {
        $pdf_id = $this->data($document_id)['pdf_id'];
        return $pdf_id ? (string) wp_get_attachment_url($pdf_id) : '';
    }
}
