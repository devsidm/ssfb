<?php

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Promotion_Renderer
{
    private SSF_Promotion_Repository $repository;

    public function __construct(SSF_Promotion_Repository $repository)
    {
        $this->repository = $repository;
    }

    public function render(array $args = array()): string
    {
        if (! $this->feature_available()) {
            return '';
        }

        $args = wp_parse_args($args, array(
            'location' => 'home',
            'max' => 3,
            'type' => '',
            'layout' => 'auto',
            'heading' => __('Viktigt just nu', 'ssf-promotions'),
        ));
        $posts = $this->repository->active($args);
        if (! $posts) {
            return '';
        }

        $layout = in_array($args['layout'], array('auto', 'banner', 'card'), true) ? $args['layout'] : 'auto';
        $single = 1 === count($posts);
        $section_id = wp_unique_id('ssf-promotions-title-');

        ob_start();
        ?>
        <section class="ssf-promotions ssf-promotions--<?php echo $single ? 'single' : 'grid'; ?>" aria-labelledby="<?php echo esc_attr($section_id); ?>">
            <div class="ssf-promotions__inner">
                <h2 id="<?php echo esc_attr($section_id); ?>" class="ssf-promotions__heading"><?php echo esc_html((string) $args['heading']); ?></h2>
                <div class="ssf-promotions__items">
                    <?php foreach ($posts as $post) : ?>
                        <?php echo $this->render_item($post, $single, $layout); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
        return (string) apply_filters('ssf_promotions_rendered_html', ob_get_clean(), $posts, $args);
    }

    public function render_preview(array $values): string
    {
        $priority = (int) ($values['priority'] ?? 50);
        $layout = in_array(($values['layout'] ?? ''), array('banner', 'card'), true) ? $values['layout'] : 'banner';
        $type = sanitize_key((string) ($values['type'] ?? 'information'));
        $title = sanitize_text_field((string) ($values['title'] ?? '')) ?: __('Rubrik för budskapet', 'ssf-promotions');
        $text = sanitize_textarea_field((string) ($values['text'] ?? '')) ?: __('Den korta texten visas här.', 'ssf-promotions');
        $cta = sanitize_text_field((string) ($values['cta_text'] ?? '')) ?: __('Läs mer', 'ssf-promotions');
        $severity = $this->severity($priority);

        ob_start();
        ?>
        <article class="ssf-promotion ssf-promotion--<?php echo esc_attr($layout); ?> ssf-promotion--<?php echo esc_attr($severity); ?>">
            <p class="ssf-promotion__type"><?php echo esc_html($this->type_label($type)); ?></p>
            <h3 class="ssf-promotion__title"><?php echo esc_html($title); ?></h3>
            <p class="ssf-promotion__text"><?php echo esc_html($text); ?></p>
            <span class="ssf-promotion__cta"><?php echo esc_html($cta); ?><span aria-hidden="true"> →</span></span>
        </article>
        <?php
        return ob_get_clean();
    }

    private function render_item(WP_Post $post, bool $single, string $requested_layout): string
    {
        $data = $this->repository->data($post->ID);
        $layout = 'auto' === $requested_layout ? ($single ? $data['layout'] : 'card') : $requested_layout;
        $layout = in_array($layout, array('banner', 'card'), true) ? $layout : 'banner';
        $severity = $this->severity((int) $data['priority']);
        $relation = (array) $data['relation'];
        $meta = (string) ($relation['meta'] ?? '');
        $status = $this->status_phrase($data);
        $url = esc_url((string) $data['url']);
        $cta = trim((string) $data['cta_text']) ?: __('Läs mer', 'ssf-promotions');

        ob_start();
        ?>
        <article class="ssf-promotion ssf-promotion--<?php echo esc_attr($layout); ?> ssf-promotion--<?php echo esc_attr($severity); ?>">
            <div class="ssf-promotion__content">
                <p class="ssf-promotion__type"><?php echo esc_html($this->type_label((string) $data['type'])); ?></p>
                <h3 class="ssf-promotion__title"><?php echo esc_html(get_the_title($post)); ?></h3>
                <?php if ($post->post_excerpt) : ?><p class="ssf-promotion__text"><?php echo esc_html(wp_strip_all_tags($post->post_excerpt)); ?></p><?php endif; ?>
                <?php if ($meta || $status) : ?>
                    <p class="ssf-promotion__meta">
                        <?php if ($meta) : ?><span><?php echo esc_html($meta); ?></span><?php endif; ?>
                        <?php if ($status) : ?><strong><?php echo esc_html($status); ?></strong><?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php if ($url) : ?>
                <p class="ssf-promotion__action"><a class="ssf-promotion__cta" href="<?php echo $url; ?>"><?php echo esc_html($cta); ?><span aria-hidden="true"> →</span></a></p>
            <?php endif; ?>
        </article>
        <?php
        return ob_get_clean();
    }

    private function status_phrase(array $data): string
    {
        $relation = (array) $data['relation'];
        $end = (int) ($data['end'] ?: ($relation['end'] ?? 0));
        if ($data['show_countdown'] && $end) {
            $remaining = $end - current_datetime()->getTimestamp();
            if ($remaining >= 0) {
                $days = (int) ceil($remaining / DAY_IN_SECONDS);
                if (0 === $days) {
                    return __('Sista dagen idag', 'ssf-promotions');
                }
                return sprintf(_n('%d dag kvar', '%d dagar kvar', $days, 'ssf-promotions'), $days);
            }
        }
        return (string) ($relation['status'] ?? '');
    }

    private function severity(int $priority): string
    {
        if ($priority >= 100) {
            return 'action';
        }
        return $priority >= 80 ? 'important' : 'information';
    }

    private function type_label(string $type): string
    {
        $labels = array(
            'annual_meeting' => __('Årsmöte', 'ssf-promotions'),
            'motions' => __('Motioner', 'ssf-promotions'),
            'newsletter' => __('Nyhetsbrev', 'ssf-promotions'),
            'event' => __('Evenemang', 'ssf-promotions'),
            'membership' => __('Medlemsinformation', 'ssf-promotions'),
            'security' => __('Säkerhetsinformation', 'ssf-promotions'),
            'campaign' => __('Kampanj', 'ssf-promotions'),
            'information' => __('Information', 'ssf-promotions'),
        );
        return $labels[$type] ?? $labels['information'];
    }

    private function feature_available(): bool
    {
        if (! class_exists('SSF_Feature_Manager')) {
            return true;
        }
        if (method_exists('SSF_Feature_Manager', 'get_registry') && ! isset(SSF_Feature_Manager::get_registry()['promotions'])) {
            return true;
        }
        return SSF_Feature_Manager::can_access('promotions');
    }
}
