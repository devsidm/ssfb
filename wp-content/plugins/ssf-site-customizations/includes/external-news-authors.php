<?php
/** Secure no-login contribution links for ordinary WordPress news posts. */
if (! defined('ABSPATH')) { exit; }

const SSF_EXTERNAL_NEWS_HASH = '_ssf_external_news_token_hash';
const SSF_EXTERNAL_NEWS_EXPIRES = '_ssf_external_news_expires_at';
const SSF_EXTERNAL_NEWS_REVOKED = '_ssf_external_news_revoked';
const SSF_EXTERNAL_NEWS_AUDIT = '_ssf_external_news_audit';
const SSF_EXTERNAL_NEWS_GALLERY = '_ssf_external_news_gallery_ids';

function ssf_external_news_token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function ssf_external_news_url(string $token): string { return home_url('/skriv-nyhet/' . rawurlencode($token) . '/'); }
function ssf_external_news_audit(int $post_id, string $event): void {
    $events = (array) get_post_meta($post_id, SSF_EXTERNAL_NEWS_AUDIT, true);
    $events[] = array('event' => sanitize_key($event), 'time' => current_time('mysql'));
    update_post_meta($post_id, SSF_EXTERNAL_NEWS_AUDIT, array_slice($events, -100));
}
function ssf_external_news_find(string $token): ?WP_Post {
    if (! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) { return null; }
    $items = get_posts(array('post_type' => 'post', 'post_status' => array('draft', 'pending'), 'posts_per_page' => 1, 'meta_key' => SSF_EXTERNAL_NEWS_HASH, 'meta_value' => hash('sha256', $token)));
    if (! $items) { return null; }
    $post = $items[0];
    $stored = (string) get_post_meta($post->ID, SSF_EXTERNAL_NEWS_HASH, true);
    if (! hash_equals($stored, hash('sha256', $token)) || get_post_meta($post->ID, SSF_EXTERNAL_NEWS_REVOKED, true) || (int) get_post_meta($post->ID, SSF_EXTERNAL_NEWS_EXPIRES, true) < time()) { return null; }
    return $post;
}
function ssf_external_news_register_route(): void {
    add_rewrite_rule('^skriv-nyhet/([^/]+)/?$', 'index.php?ssf_external_news_token=$matches[1]', 'top');
    if ('1' !== get_option('ssf_external_news_rewrite_version')) { flush_rewrite_rules(false); update_option('ssf_external_news_rewrite_version', '1', false); }
}
add_action('init', 'ssf_external_news_register_route');
add_filter('query_vars', static function(array $vars): array { $vars[] = 'ssf_external_news_token'; return $vars; });
add_filter('wp_robots', static function(array $robots): array { if (get_query_var('ssf_external_news_token')) { $robots['noindex'] = true; $robots['nofollow'] = true; } return $robots; });
add_action('template_redirect', 'ssf_external_news_render_editor', 0);

function ssf_external_news_render_editor(): void {
    $token = (string) get_query_var('ssf_external_news_token');
    if (! $token) { return; }
    nocache_headers(); header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    $post = ssf_external_news_find($token);
    status_header($post ? 200 : 404); get_header();
    echo '<main class="ssf-external-news"><section class="ssf-external-news__card"><p class="ssf-external-news__brand">Sveriges Segelfartygsförbund</p><h1>Skicka in nyhet</h1>';
    if (! $post) { echo '<p>Länken är inte längre giltig. Kontakta Sveriges Segelfartygsförbund om du behöver en ny länk.</p></section></main>'; get_footer(); exit; }
    $submitted = 'pending' === $post->post_status;
    $notice = sanitize_key((string) ($_GET['ssf_external_news_notice'] ?? ''));
    if ('saved' === $notice) { echo '<div class="ssf-external-news__notice">✓ Utkastet är sparat</div>'; }
    if ('submitted' === $notice) { echo '<div class="ssf-external-news__notice">✓ Nyheten har skickats till SSF för granskning</div>'; }
    echo '<p>Du har fått den här länken för att skriva ett nyhetsutkast till SSF. Du behöver inget konto. Spara länken tills texten är inskickad.</p>';
    if ($submitted) { echo '<p class="ssf-external-news__notice">Nyheten väntar på granskning. Kontakta SSF om den behöver öppnas igen.</p></section></main>'; get_footer(); exit; }
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="ssf-external-news__form"><input type="hidden" name="action" value="ssf_external_news_save"><input type="hidden" name="token" value="' . esc_attr($token) . '">';
    wp_nonce_field('ssf_external_news_' . hash('sha256', $token), 'ssf_external_news_nonce');
    echo '<label>Rubrik<input type="text" name="title" required value="' . esc_attr($post->post_title) . '"></label><label>Ingress / sammanfattning<textarea name="excerpt" rows="3">' . esc_textarea($post->post_excerpt) . '</textarea></label><label>Nyhetstext</label>';
    wp_editor($post->post_content, 'ssf_external_news_content', array('textarea_name' => 'content', 'media_buttons' => false, 'textarea_rows' => 14, 'teeny' => true));
    echo '<label>Huvudbild<input type="file" name="featured_image" accept="image/jpeg,image/png,image/webp"></label><label>Fler bilder<input type="file" name="gallery_images[]" accept="image/jpeg,image/png,image/webp" multiple></label><p class="description">Endast JPEG, PNG och WebP-bilder kan laddas upp.</p><div><button type="submit" name="intent" value="save" class="ssf-button">Spara utkast</button><button type="submit" name="intent" value="submit" class="ssf-button ssf-button--primary">Skicka till SSF</button></div></form></section></main>';
    get_footer(); exit;
}

function ssf_external_news_upload(int $post_id, array $file): int {
    if (empty($file['tmp_name']) || UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) || ! is_uploaded_file($file['tmp_name']) || (int) $file['size'] > wp_max_upload_size()) { return 0; }
    $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    if (! in_array($checked['type'] ?? '', array('image/jpeg', 'image/png', 'image/webp'), true) || ! in_array(strtolower((string) ($checked['ext'] ?? '')), array('jpg', 'jpeg', 'png', 'webp'), true)) { return 0; }
    require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php'; require_once ABSPATH . 'wp-admin/includes/media.php';
    $attachment = media_handle_sideload($file, $post_id);
    return is_wp_error($attachment) ? 0 : (int) $attachment;
}
function ssf_external_news_save(): void {
    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? '')); $post = ssf_external_news_find($token);
    if (! $post || ! isset($_POST['ssf_external_news_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_external_news_nonce'])), 'ssf_external_news_' . hash('sha256', $token))) { wp_die('Länken är inte längre giltig. Kontakta Sveriges Segelfartygsförbund om du behöver en ny länk.', 403); }
    $intent = 'submit' === sanitize_key(wp_unslash($_POST['intent'] ?? 'save')) ? 'submit' : 'save';
    wp_update_post(array('ID' => $post->ID, 'post_title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 'post_excerpt' => sanitize_textarea_field(wp_unslash($_POST['excerpt'] ?? '')), 'post_content' => wp_kses_post(wp_unslash($_POST['content'] ?? '')), 'post_status' => 'submit' === $intent ? 'pending' : 'draft'));
    if (! empty($_FILES['featured_image'])) { $image = ssf_external_news_upload($post->ID, $_FILES['featured_image']); if ($image) { set_post_thumbnail($post->ID, $image); ssf_external_news_audit($post->ID, 'image_uploaded'); } }
    foreach ((array) ($_FILES['gallery_images']['name'] ?? array()) as $i => $name) { $image = ssf_external_news_upload($post->ID, array('name' => $name, 'type' => $_FILES['gallery_images']['type'][$i] ?? '', 'tmp_name' => $_FILES['gallery_images']['tmp_name'][$i] ?? '', 'error' => $_FILES['gallery_images']['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $_FILES['gallery_images']['size'][$i] ?? 0)); if ($image) { $gallery[] = $image; } }
    if (! empty($gallery)) { update_post_meta($post->ID, SSF_EXTERNAL_NEWS_GALLERY, array_merge((array) get_post_meta($post->ID, SSF_EXTERNAL_NEWS_GALLERY, true), $gallery)); ssf_external_news_audit($post->ID, 'images_uploaded'); }
    ssf_external_news_audit($post->ID, 'submit' === $intent ? 'submitted' : 'saved');
    wp_safe_redirect(add_query_arg('ssf_external_news_notice', 'submit' === $intent ? 'submitted' : 'saved', ssf_external_news_url($token)) . '#ssf-external-news'); exit;
}
add_action('admin_post_nopriv_ssf_external_news_save', 'ssf_external_news_save'); add_action('admin_post_ssf_external_news_save', 'ssf_external_news_save');

function ssf_external_news_admin_menu(): void {
    $parent = class_exists('SSF_Admin_Navigation') ? SSF_Admin_Navigation::CONTENT : 'edit.php';
    add_submenu_page($parent, 'Extern skribentlänk', 'Extern skribentlänk', 'edit_posts', 'ssf-external-news', 'ssf_external_news_admin_page', 35);
}
add_action('admin_menu', 'ssf_external_news_admin_menu');
function ssf_external_news_admin_page(): void {
    if (! current_user_can('edit_posts')) { wp_die('Du saknar behörighet.'); }
    $created = get_transient('ssf_external_news_created_' . get_current_user_id()); if ($created) { delete_transient('ssf_external_news_created_' . get_current_user_id()); }
    echo '<div class="wrap"><h1>Extern skribentlänk</h1><p>Skapa ett begränsat utkast för en extern skribent. Länken är giltig i 14 dagar.</p>';
    if ($created) { echo '<div class="notice notice-success"><p><strong>Extern skribentlänk</strong><br><input class="large-text code" readonly value="' . esc_attr(ssf_external_news_url($created)) . '"></p></div>'; }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_external_news_create">'; wp_nonce_field('ssf_external_news_create'); submit_button('Skapa extern skribentlänk'); echo '</form><h2>Externa utkast</h2><table class="widefat striped"><thead><tr><th>Utkast</th><th>Status</th><th>Gäller till</th><th>Åtgärd</th></tr></thead><tbody>';
    $drafts = get_posts(array('post_type' => 'post', 'post_status' => array('draft', 'pending', 'publish'), 'posts_per_page' => 100, 'meta_key' => SSF_EXTERNAL_NEWS_HASH));
    foreach ($drafts as $draft) { $revoked = (bool) get_post_meta($draft->ID, SSF_EXTERNAL_NEWS_REVOKED, true); $expired = (int) get_post_meta($draft->ID, SSF_EXTERNAL_NEWS_EXPIRES, true) < time(); $state = $revoked ? 'Länk återkallad' : ($expired ? 'Länk utgången' : ('pending' === $draft->post_status ? 'Inskickad' : ('publish' === $draft->post_status ? 'Publicerad' : 'Utkast'))); echo '<tr><td><a href="' . esc_url(get_edit_post_link($draft->ID)) . '">' . esc_html($draft->post_title ?: 'Namnlöst utkast') . '</a></td><td>' . esc_html($state) . '</td><td>' . esc_html(wp_date('Y-m-d H:i', (int) get_post_meta($draft->ID, SSF_EXTERNAL_NEWS_EXPIRES, true))) . '</td><td>'; if (! $revoked && ! $expired && 'pending' !== $draft->post_status) { echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_external_news_revoke"><input type="hidden" name="post_id" value="' . esc_attr((string) $draft->ID) . '">'; wp_nonce_field('ssf_external_news_revoke_' . $draft->ID); echo '<button class="button-link-delete" type="submit">Återkalla länk</button></form>'; } echo '</td></tr>'; }
    echo '</tbody></table></div>';
}
function ssf_external_news_create(): void {
    if (! current_user_can('edit_posts') || ! check_admin_referer('ssf_external_news_create')) { wp_die('Du saknar behörighet.'); }
    $post_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Namnlöst utkast', 'post_author' => get_current_user_id()), true); if (is_wp_error($post_id)) { wp_die('Kunde inte skapa utkastet.'); }
    $token = ssf_external_news_token(); update_post_meta($post_id, SSF_EXTERNAL_NEWS_HASH, hash('sha256', $token)); update_post_meta($post_id, SSF_EXTERNAL_NEWS_EXPIRES, time() + 14 * DAY_IN_SECONDS); update_post_meta($post_id, SSF_EXTERNAL_NEWS_REVOKED, '0'); ssf_external_news_audit((int) $post_id, 'link_created'); set_transient('ssf_external_news_created_' . get_current_user_id(), $token, 10 * MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url('admin.php?page=ssf-external-news')); exit;
}
add_action('admin_post_ssf_external_news_create', 'ssf_external_news_create');
function ssf_external_news_revoke(): void {
    $post_id = absint($_POST['post_id'] ?? 0);
    if (! $post_id || ! current_user_can('edit_post', $post_id) || ! check_admin_referer('ssf_external_news_revoke_' . $post_id) || ! get_post_meta($post_id, SSF_EXTERNAL_NEWS_HASH, true)) { wp_die('Du saknar behörighet.'); }
    update_post_meta($post_id, SSF_EXTERNAL_NEWS_REVOKED, '1'); ssf_external_news_audit($post_id, 'link_revoked'); wp_safe_redirect(admin_url('admin.php?page=ssf-external-news')); exit;
}
add_action('admin_post_ssf_external_news_revoke', 'ssf_external_news_revoke');
add_action('transition_post_status', static function(string $new_status, string $old_status, WP_Post $post): void {
    if ('post' === $post->post_type && 'publish' === $new_status && 'publish' !== $old_status && get_post_meta($post->ID, SSF_EXTERNAL_NEWS_HASH, true)) { ssf_external_news_audit($post->ID, 'admin_published'); }
}, 10, 3);
