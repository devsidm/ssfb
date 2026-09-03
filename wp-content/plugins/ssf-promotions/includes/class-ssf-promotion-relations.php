<?php

if (! defined('ABSPATH')) {
    exit;
}

interface SSF_Promotion_Relation_Provider
{
    public function key(): string;

    public function label(): string;

    public function options(): array;

    public function anchors(): array;

    public function resolve(int $post_id, string $anchor = ''): array;
}

final class SSF_Promotion_Post_Type_Provider implements SSF_Promotion_Relation_Provider
{
    private string $key;
    private string $label;
    private array $post_types;

    public function __construct(string $key, string $label, $post_types)
    {
        $this->key = $key;
        $this->label = $label;
        $this->post_types = array_map('sanitize_key', (array) $post_types);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function options(): array
    {
        $post_types = array_values(array_filter($this->post_types, 'post_type_exists'));
        if (! $post_types) {
            return array();
        }

        $posts = get_posts(array(
            'post_type' => $post_types,
            'post_status' => array('publish', 'draft', 'private', 'future'),
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        $options = array();
        foreach ($posts as $post) {
            $options[$post->ID] = get_the_title($post) ?: sprintf(__('Objekt %d', 'ssf-promotions'), $post->ID);
        }
        return $options;
    }

    public function anchors(): array
    {
        return array();
    }

    public function resolve(int $post_id, string $anchor = ''): array
    {
        $post = get_post($post_id);
        if (! $post || ! in_array($post->post_type, $this->post_types, true)) {
            return array();
        }

        $url = 'publish' === $post->post_status ? get_permalink($post) : '';
        if ('publish' === $post->post_status && 'ssf_newsletter' === $post->post_type) {
            $pdf_id = (int) get_post_meta($post_id, '_ssf_newsletter_pdf_id', true);
            $url = $pdf_id ? wp_get_attachment_url($pdf_id) : $url;
        }

        return array(
            'title' => get_the_title($post),
            'url' => $url ? (string) $url : '',
            'meta' => '',
            'status' => '',
            'start' => 0,
            'end' => 0,
        );
    }
}

final class SSF_Promotion_Annual_Meeting_Provider implements SSF_Promotion_Relation_Provider
{
    public function key(): string
    {
        return 'annual_meeting';
    }

    public function label(): string
    {
        return __('Årsmöte', 'ssf-promotions');
    }

    public function options(): array
    {
        if (! post_type_exists('ssf_annual_meeting')) {
            return array();
        }

        $posts = get_posts(array(
            'post_type' => 'ssf_annual_meeting',
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => 50,
            'meta_key' => '_ssf_am_start_at',
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
        ));

        $options = array();
        foreach ($posts as $post) {
            $options[$post->ID] = get_the_title($post) ?: sprintf(__('Årsmöte %d', 'ssf-promotions'), $post->ID);
        }
        return $options;
    }

    public function anchors(): array
    {
        return array(
            '' => __('Översikt', 'ssf-promotions'),
            'overview' => __('Översikt', 'ssf-promotions'),
            'invitation' => __('Kallelse', 'ssf-promotions'),
            'meeting' => __('Själva årsmötet', 'ssf-promotions'),
            'dinner' => __('Middag', 'ssf-promotions'),
            'day2' => __('Program dag 2', 'ssf-promotions'),
            'motions' => __('Motioner', 'ssf-promotions'),
            'documents' => __('Handlingar', 'ssf-promotions'),
        );
    }

    public function resolve(int $post_id, string $anchor = ''): array
    {
        $post = get_post($post_id);
        if (! $post || 'ssf_annual_meeting' !== $post->post_type) {
            return array();
        }

        $start = (int) get_post_meta($post_id, '_ssf_am_start_at', true);
        $end = (int) get_post_meta($post_id, '_ssf_am_end_at', true);
        $location = (string) get_post_meta($post_id, '_ssf_am_location', true);
        $registration_open = (bool) get_post_meta($post_id, '_ssf_am_registration_open', true);
        $registration_opens = (int) get_post_meta($post_id, '_ssf_am_registration_opens_at', true);
        $registration_closes = (int) get_post_meta($post_id, '_ssf_am_registration_closes_at', true);
        $dinner = (array) get_post_meta($post_id, '_ssf_am_dinner', true);

        if ('dinner' === $anchor && ! empty($dinner['deadline'])) {
            $registration_closes = (int) $dinner['deadline'];
        }

        $page_id = (int) get_option('ssf_member_portal_annual_meeting_page_id', 0);
        $url = '';
        if ('publish' === $post->post_status) {
            $url = add_query_arg('meeting', $post_id, $page_id ? get_permalink($page_id) : home_url('/arsmote/'));
        }
        $anchors = array(
            'overview' => 'ssf-am-overview',
            'invitation' => 'ssf-am-invitation',
            'meeting' => 'ssf-am-meeting',
            'dinner' => 'ssf-am-dinner',
            'day2' => 'ssf-am-day2',
            'motions' => 'ssf-am-motions',
            'documents' => 'ssf-am-documents',
        );
        if ($url && $anchor && isset($anchors[$anchor])) {
            $url .= '#' . $anchors[$anchor];
        }

        $date = $start ? wp_date('j M', $start, wp_timezone()) : '';
        if ($start && $end && wp_date('Y-m-d', $start, wp_timezone()) !== wp_date('Y-m-d', $end, wp_timezone())) {
            $date = wp_date('j M', $start, wp_timezone()) . '–' . wp_date('j M', $end, wp_timezone());
        }
        $meta = implode(' · ', array_filter(array($date, $location)));

        $now = current_datetime()->getTimestamp();
        $status = '';
        if ($registration_open && (! $registration_opens || $now >= $registration_opens) && (! $registration_closes || $now <= $registration_closes)) {
            $status = $registration_closes
                ? sprintf(__('Anmälan stänger %s', 'ssf-promotions'), wp_date('j F', $registration_closes, wp_timezone()))
                : __('Anmälan är öppen', 'ssf-promotions');
        }

        return array(
            'title' => get_the_title($post),
            'url' => esc_url_raw($url),
            'meta' => $meta,
            'status' => $status,
            'start' => $registration_opens,
            'end' => $registration_closes,
        );
    }
}

final class SSF_Promotion_Relations
{
    /** @var array<string, SSF_Promotion_Relation_Provider>|null */
    private ?array $providers = null;

    public function providers(): array
    {
        if (null !== $this->providers) {
            return $this->providers;
        }

        $providers = array(
            'annual_meeting' => new SSF_Promotion_Annual_Meeting_Provider(),
            'newsletter' => new SSF_Promotion_Post_Type_Provider('newsletter', __('Nyhetsbrev', 'ssf-promotions'), 'ssf_newsletter'),
            'event' => new SSF_Promotion_Post_Type_Provider('event', __('Kalenderhändelse', 'ssf-promotions'), 'ssf_event'),
            'post' => new SSF_Promotion_Post_Type_Provider('post', __('Nyhet eller sida', 'ssf-promotions'), array('post', 'page')),
        );

        $providers = apply_filters('ssf_promotions_relation_providers', $providers);
        $this->providers = array_filter($providers, static function ($provider): bool {
            return $provider instanceof SSF_Promotion_Relation_Provider;
        });
        return $this->providers;
    }

    public function provider(string $key): ?SSF_Promotion_Relation_Provider
    {
        $providers = $this->providers();
        return $providers[$key] ?? null;
    }

    public function resolve(string $type, int $post_id, string $anchor = ''): array
    {
        $provider = $this->provider($type);
        return $provider && $post_id ? $provider->resolve($post_id, $anchor) : array();
    }
}
