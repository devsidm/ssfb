<?php
/** Database-free behavior tests for canonical news galleries and article image HTML. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

class WP_Post
{
    public int $post_author = 7;
    public string $post_content = '<p>Artikeltext</p>';
    public string $post_excerpt = '';
    public string $post_type = 'post';
    public string $post_mime_type = '';
    public string $post_title = '';
    public string $post_status = 'draft';
    public function __construct(public int $ID) {}
}

$GLOBALS['gallery_meta'] = array();
$GLOBALS['gallery_posts'] = array();
$GLOBALS['gallery_thumbnail'] = array(1 => 10);
$GLOBALS['gallery_alt'] = array(10 => 'Huvudbildens alt', 11 => 'Första bildens alt', 12 => '');

function add_action(...$args): void {}
function add_filter(...$args): void {}
function absint($value): int { return abs((int) $value); }
function metadata_exists(string $type, int $id, string $key): bool { return array_key_exists($key, $GLOBALS['gallery_meta'][$id] ?? array()); }
function get_post_meta(int $id, string $key, bool $single = false) { return $GLOBALS['gallery_meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, $value): bool { $GLOBALS['gallery_meta'][$id][$key] = $value; return true; }
function get_post(int $id): ?WP_Post { return $GLOBALS['gallery_posts'][$id] ?? null; }
function wp_attachment_is_image(int $id): bool { $post = get_post($id); return $post instanceof WP_Post && 'attachment' === $post->post_type && str_starts_with($post->post_mime_type, 'image/'); }
function get_post_thumbnail_id(int $id): int { return (int) ($GLOBALS['gallery_thumbnail'][$id] ?? 0); }
function wp_get_attachment_image(int $id, string $size, bool $icon = false, array $attr = array()): string
{
    if (! wp_attachment_is_image($id)) { return ''; }
    $attributes = array(
        'src' => "image-{$id}-768.jpg",
        'srcset' => "image-{$id}-300.jpg 300w, image-{$id}-768.jpg 768w",
        'sizes' => (string) ($attr['sizes'] ?? ''),
        'width' => '768',
        'height' => '512',
        'alt' => (string) ($GLOBALS['gallery_alt'][$id] ?? ''),
        'data-id' => (string) $id,
        'data-size' => $size,
    ) + $attr;
    $html = '<img';
    foreach ($attributes as $key => $value) { $html .= ' ' . $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"'; }
    return $html . '>';
}
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_url_raw(string $value): string { return $value; }
function wp_kses_post(string $value): string { return $value; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function get_userdata(int $id): object { return (object) array('display_name' => 'Redaktör'); }
function get_the_date(string $format = '', $post = null): string { return '16 november 2026'; }
function get_option(string $key): string { return 'j F Y'; }
function get_the_title(WP_Post $post): string { return $post->post_title; }
function apply_filters(string $hook, $value) { return $value; }
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }

foreach (array(10, 11, 12) as $id) {
    $attachment = new WP_Post($id);
    $attachment->post_type = 'attachment';
    $attachment->post_mime_type = 'image/jpeg';
    $GLOBALS['gallery_posts'][$id] = $attachment;
}
$GLOBALS['gallery_posts'][11]->post_excerpt = 'Bildtext ett';
$invalid = new WP_Post(13);
$invalid->post_type = 'attachment';
$invalid->post_mime_type = 'application/pdf';
$GLOBALS['gallery_posts'][13] = $invalid;
$article = new WP_Post(1);
$article->post_title = 'Responsiva bilder';
$GLOBALS['gallery_posts'][1] = $article;

require dirname(__DIR__, 2) . '/wp-content/plugins/ssf-site-customizations/includes/external-news-authors.php';
require dirname(__DIR__, 2) . '/wp-content/themes/ssf/functions.php';

function gallery_check(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$GLOBALS['gallery_meta'][1][SSF_EXTERNAL_NEWS_GALLERY] = array(11, '12', 11, 13, 0);
gallery_check(array(11, 12) === ssf_news_gallery_ids(1), 'legacy metadata ska läsas, saneras och dedupliceras');

ssf_news_set_gallery_ids(1, array());
gallery_check(metadata_exists('post', 1, SSF_NEWS_GALLERY), 'tom canonical metadata ska finnas kvar');
gallery_check(array() === ssf_news_gallery_ids(1), 'tom canonical metadata ska blockera legacy fallback');

ssf_news_append_gallery_ids(1, array(11, 11, 13, 12));
gallery_check(array(11, 12) === ssf_news_gallery_ids(1), 'append ska behålla ordning och endast giltiga unika bilder');
ssf_news_append_gallery_ids(1, array(10));

ob_start();
ssf_render_news_article($article);
$html = (string) ob_get_clean();
gallery_check(1 === substr_count($html, 'data-id="10"'), 'featured image får inte dupliceras i gallery');
gallery_check(1 === substr_count($html, 'data-id="11"') && 1 === substr_count($html, 'data-id="12"'), 'gallery ska rendera varje extra bild en gång');
gallery_check(str_contains($html, 'srcset=') && str_contains($html, 'sizes='), 'bilder ska ha srcset och sizes');
gallery_check(str_contains($html, 'width="768"') && str_contains($html, 'height="512"'), 'bilddimensioner ska behållas');
gallery_check(str_contains($html, 'loading="lazy"'), 'gallerybilder ska lazy-loadas');
gallery_check(str_contains($html, 'alt="Första bildens alt"') && str_contains($html, 'alt=""'), 'Media Library alt ska bevaras, även tom alt');
gallery_check(str_contains($html, '<figcaption>Bildtext ett</figcaption>'), 'attachment caption ska renderas semantiskt');
gallery_check(str_contains($html, '<div class="entry-content"><p>Artikeltext</p></div><section class="ssf-news-gallery"'), 'gallery ska ligga efter artikeltexten');

ssf_news_set_gallery_ids(1, array(11));
$single_html = ssf_news_gallery($article);
gallery_check(str_contains($single_html, 'data-size="large"') && str_contains($single_html, '860px'), 'en ensam gallerybild ska använda large och full artikelbredd i sizes');

$service = file_get_contents(dirname(__DIR__, 2) . '/wp-content/plugins/ssf-arbetsyta/includes/class-ssf-news-service.php');
$styles = file_get_contents(dirname(__DIR__, 2) . '/wp-content/themes/ssf/style.css');
gallery_check(str_contains($service, 'name="gallery_images[]"') && str_contains($service, 'multiple'), 'Workspace ska stödja multiple upload');
gallery_check(str_contains($service, 'name="remove_gallery_ids[]"') && str_contains($service, 'Ta bort från artikeln'), 'Workspace ska kunna koppla bort bilder');
gallery_check(! str_contains($service, 'wp_delete_attachment'), 'borttagning från artikel får inte radera attachment');
gallery_check(str_contains($styles, 'grid-template-columns: repeat(2, minmax(0, 1fr))') && str_contains($styles, '@media (max-width: 640px)'), 'gallery ska vara responsiv CSS Grid');

echo "PASS: canonical gallery, legacy fallback, editor controls and responsive article image HTML.\n";
