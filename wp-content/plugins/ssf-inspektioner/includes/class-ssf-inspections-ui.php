<?php
if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Inspections_UI
{
    public static function routes(): void
    {
        add_rewrite_rule('^inspektioner/?$', 'index.php?ssf_inspection_route=home', 'top');
        add_rewrite_rule('^inspektioner/ny/?$', 'index.php?ssf_inspection_route=new', 'top');
        add_rewrite_rule('^inspektioner/([0-9]+)/sektion/([^/]+)/?$', 'index.php?ssf_inspection_route=section&ssf_inspection_id=$matches[1]&ssf_inspection_section=$matches[2]', 'top');
        add_rewrite_rule('^inspektioner/([0-9]+)/sammanfattning/?$', 'index.php?ssf_inspection_route=summary&ssf_inspection_id=$matches[1]', 'top');
        add_rewrite_rule('^inspektioner/([0-9]+)/rapport/?$', 'index.php?ssf_inspection_route=report&ssf_inspection_id=$matches[1]', 'top');
        add_rewrite_rule('^inspektioner/([0-9]+)/?$', 'index.php?ssf_inspection_route=inspection&ssf_inspection_id=$matches[1]', 'top');
    }

    public static function query_vars(array $vars): array
    {
        return array_merge($vars, array('ssf_inspection_route', 'ssf_inspection_id', 'ssf_inspection_section'));
    }

    public static function url(int $id = 0, string $suffix = ''): string
    {
        return home_url('/inspektioner/' . ($id ? $id . '/' : '') . ltrim($suffix, '/'));
    }

    public static function dispatch(): void
    {
        if (isset($_GET['ssf_inspection_photo'])) {
            self::photo_response();
        }
        $route = (string) get_query_var('ssf_inspection_route');
        if (! $route) {
            return;
        }
        if (! is_user_logged_in()) {
            auth_redirect();
            exit;
        }
        if (! SSF_Inspections_Core::inspector()) {
            status_header(403);
            wp_die('Du saknar behörighet till inspektioner.', 'Åtkomst nekad', array('response' => 403));
        }
        $id = absint(get_query_var('ssf_inspection_id'));
        if ($id && ! SSF_Inspections_Core::can_read($id)) {
            status_header(403);
            wp_die('Du har inte åtkomst till denna inspektion.', 'Åtkomst nekad', array('response' => 403));
        }
        nocache_headers();
        if ('new' === $route) {
            self::shell('Ny inspektion', array(__CLASS__, 'new_page'));
        } elseif ('inspection' === $route) {
            self::shell('Inspektion', fn () => self::overview($id));
        } elseif ('section' === $route) {
            self::shell('Kontrollpunkter', fn () => self::section($id, sanitize_text_field(get_query_var('ssf_inspection_section'))));
        } elseif ('summary' === $route) {
            self::shell('Sammanfattning', fn () => self::summary($id));
        } elseif ('report' === $route) {
            self::shell('Inspektionsrapport', fn () => self::report($id));
        } else {
            self::shell('Mina inspektioner', array(__CLASS__, 'home'));
        }
        exit;
    }

    private static function shell(string $title, callable $render): void
    {
        $css = SSF_INSPECTIONS_URL . 'assets/inspection.css?ver=' . rawurlencode(SSF_INSPECTIONS_VERSION);
        $js = SSF_INSPECTIONS_URL . 'assets/inspection.js?ver=' . rawurlencode(SSF_INSPECTIONS_VERSION);
        echo '<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#092b53"><title>' . esc_html($title) . ' – SSF</title><link rel="stylesheet" href="' . esc_url($css) . '"></head><body class="ssf-inspection-app">';
        echo '<header class="si-header"><a href="' . esc_url(self::url()) . '">SSF · Inspektioner</a><span>' . esc_html(wp_get_current_user()->display_name) . '</span></header><main class="si-main">';
        $render();
        echo '</main><div id="si-sync" class="si-sync" role="status" aria-live="polite">✓ Sparat</div>';
        echo '<script>window.SSFInspection=' . wp_json_encode(array('rest' => esc_url_raw(rest_url('ssf-inspections/v1/')), 'nonce' => wp_create_nonce('wp_rest'), 'home' => self::url(), 'user' => get_current_user_id())) . ';</script>';
        echo '<script defer src="' . esc_url($js) . '"></script></body></html>';
    }

    private static function home(): void
    {
        global $wpdb;
        $where = SSF_Inspections_Core::manager() ? '' : $wpdb->prepare(' AND i.inspector_user_id=%d', get_current_user_id());
        $rows = $wpdb->get_results('SELECT i.* FROM ' . SSF_Inspections_DB::table('inspections') . " i WHERE 1=1 $where ORDER BY (i.status='in_progress') DESC,i.updated_at DESC LIMIT 100", ARRAY_A);
        echo '<div class="si-top"><h1>Mina inspektioner</h1><a class="si-button" href="' . esc_url(self::url(0, 'ny/')) . '">Starta inspektion</a></div>';
        foreach (array('in_progress' => 'Pågående inspektioner', 'completed' => 'Avslutade inspektioner') as $status => $heading) {
            echo '<h2>' . esc_html($heading) . '</h2><div class="si-list">';
            foreach ($rows as $row) {
                if ($row['status'] !== $status) {
                    continue;
                }
                $items = SSF_Inspections_Core::snapshots((int) $row['id']);
                $answered = count(array_filter($items, fn ($item) => ! empty($item['assessment'])));
                $percent = $items ? (int) round(100 * $answered / count($items)) : 0;
                $target = $status === 'in_progress' && $row['last_active_section'] ? self::url((int) $row['id'], 'sektion/' . $row['last_active_section'] . ((int) $row['last_active_item'] ? '#item-' . (int) $row['last_active_item'] : '')) : self::url((int) $row['id']);
                echo '<a class="si-card si-card-link" href="' . esc_url($target) . '"><strong>' . esc_html(get_the_title((int) $row['ship_id'])) . '</strong><span>' . esc_html($row['inspection_type']) . ' · ' . esc_html($row['inspection_date']) . '</span><span>' . esc_html($percent) . ' % klar · ' . esc_html($status === 'completed' ? 'Avslutad' : 'Fortsätt där jag var') . '</span></a>';
            }
            echo '</div>';
        }
    }

    private static function new_page(): void
    {
        global $wpdb;
        $ships = get_posts(array('post_type' => 'medlemsfartyg', 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC'));
        $versions = $wpdb->get_results($wpdb->prepare('SELECT v.id,v.version,t.name FROM ' . SSF_Inspections_DB::table('versions') . ' v JOIN ' . SSF_Inspections_DB::table('templates') . ' t ON t.id=v.template_id WHERE v.status=%s AND t.status=%s ORDER BY t.name,v.version DESC', 'published', 'active'), ARRAY_A);
        echo '<a href="' . esc_url(self::url()) . '">← Tillbaka</a><h1>Starta inspektion</h1><form id="si-create" class="si-card"><label>Fartyg<select name="ship_id" required><option value="">Välj fartyg</option>';
        foreach ($ships as $ship) {
            echo '<option value="' . (int) $ship->ID . '">' . esc_html($ship->post_title) . '</option>';
        }
        echo '</select></label><label>Mall<select name="version_id" required><option value="">Välj mall</option>';
        foreach ($versions as $version) {
            echo '<option value="' . (int) $version['id'] . '">' . esc_html($version['name']) . ' · v' . (int) $version['version'] . '</option>';
        }
        echo '</select></label><label>Inspektionstyp<input name="type" required value="Ordinarie medlemsinspektion"></label><label>Datum<input name="date" type="date" required value="' . esc_attr(wp_date('Y-m-d')) . '"></label><label>Plats<input name="location" required></label><label>Närvarande representant<input name="representative"></label><button class="si-button" type="submit">Starta inspektion</button></form>';
    }

    private static function overview(int $id): void
    {
        $row = SSF_Inspections_Core::inspection($id);
        $items = SSF_Inspections_Core::snapshots($id);
        $groups = array();
        foreach ($items as $item) {
            $key = $item['section_order'];
            if (! isset($groups[$key])) {
                $groups[$key] = array('title' => $item['section_title'], 'total' => 0, 'done' => 0, 'remarks' => 0);
            }
            ++$groups[$key]['total'];
            $groups[$key]['done'] += ! empty($item['assessment']) ? 1 : 0;
            $groups[$key]['remarks'] += in_array($item['assessment'], array('remark', 'serious_remark'), true) ? 1 : 0;
        }
        $done = count(array_filter($items, fn ($item) => ! empty($item['assessment'])));
        $percent = $items ? (int) round(100 * $done / count($items)) : 0;
        echo '<a href="' . esc_url(self::url()) . '">← Mina inspektioner</a><h1>' . esc_html(get_the_title((int) $row['ship_id'])) . '</h1><p>' . esc_html($row['inspection_type']) . ' · ' . esc_html($row['inspection_date']) . '</p><div class="si-progress"><strong>' . $percent . ' %</strong><progress max="100" value="' . $percent . '"></progress></div>';
        if ($row['last_active_section'] && 'in_progress' === $row['status']) {
            echo '<a class="si-button" href="' . esc_url(self::url($id, 'sektion/' . $row['last_active_section'] . ((int) $row['last_active_item'] ? '#item-' . (int) $row['last_active_item'] : ''))) . '">Fortsätt där jag var</a>';
        }
        echo '<div class="si-list">';
        foreach ($groups as $key => $group) {
            $symbol = $group['done'] === $group['total'] ? '✓' : ($group['done'] ? '◐' : '○');
            echo '<a class="si-card si-card-link" href="' . esc_url(self::url($id, 'sektion/' . $key)) . '"><strong>' . esc_html($symbol . ' ' . $group['title']) . '</strong><span>' . (int) $group['done'] . '/' . (int) $group['total'] . ($group['remarks'] ? ' · ' . (int) $group['remarks'] . ' anmärkningar' : '') . '</span></a>';
        }
        echo '</div><a class="si-button si-secondary" href="' . esc_url(self::url($id, 'sammanfattning/')) . '">Sammanfattning</a>';
        if ('completed' === $row['status']) {
            echo ' <a class="si-button si-secondary" href="' . esc_url(self::url($id, 'rapport/')) . '">Visa rapport</a><button type="button" class="si-button si-secondary" id="si-followup" data-id="' . $id . '">Skapa uppföljningsinspektion</button>';
            if (SSF_Inspections_Core::manager()) {
                echo '<form id="si-reopen" data-id="' . $id . '"><label>Anledning till återöppning<textarea name="reason" required></textarea></label><button class="si-button si-secondary">Återöppna för komplettering</button></form>';
            }
        }
    }

    private static function section(int $id, string $section): void
    {
        $row = SSF_Inspections_Core::inspection($id);
        $items = array_values(array_filter(SSF_Inspections_Core::snapshots($id), fn ($item) => (string) $item['section_order'] === $section));
        if (! $items) {
            status_header(404);
            echo '<p>Sektionen finns inte.</p>';
            return;
        }
        echo '<a href="' . esc_url(self::url($id)) . '">← Sektionsöversikt</a><h1>' . esc_html($items[0]['section_title']) . '</h1><p>' . esc_html(get_the_title((int) $row['ship_id'])) . '</p>';
        if ((int) $row['parent_inspection_id']) {
            echo '<p class="si-note">Uppföljning av <a href="' . esc_url(self::url((int) $row['parent_inspection_id'])) . '">inspektion #' . (int) $row['parent_inspection_id'] . '</a>. Tidigare anmärkningar visas under respektive punkt.</p>';
        }
        foreach ($items as $item) {
            $read_only = 'completed' === $row['status'];
            echo '<article class="si-card si-item" id="item-' . (int) $item['id'] . '" data-inspection="' . $id . '" data-snapshot="' . (int) $item['id'] . '" data-readonly="' . ($read_only ? '1' : '0') . '"><small>Kontrollpunkt</small><h2>' . esc_html($item['title']) . '</h2>';
            if ($item['help_text']) {
                echo '<p>' . esc_html($item['help_text']) . '</p>';
            }
            if ($row['parent_inspection_id']) {
                self::previous_remark((int) $row['parent_inspection_id'], $item['item_key']);
            }
            echo '<fieldset ' . ($read_only ? 'disabled' : '') . '><legend>Bedömning' . ($item['is_required'] ? ' *' : '') . '</legend><div class="si-choices">';
            foreach (array('approved' => '✓ Godkänd', 'remark' => '! Anmärkning', 'serious_remark' => '‼ Allvarlig anmärkning', 'not_checked' => '– Ej kontrollerad', 'not_applicable' => '○ Ej relevant') as $value => $label) {
                echo '<label><input type="radio" name="assessment-' . (int) $item['id'] . '" value="' . esc_attr($value) . '" ' . checked($item['assessment'], $value, false) . '><span>' . esc_html($label) . '</span></label>';
            }
            echo '</div><div class="si-details"><label>Beskriv problemet<textarea name="comment">' . esc_textarea($item['comment'] ?? '') . '</textarea></label><label>Föreslagen åtgärd<textarea name="proposed_action">' . esc_textarea($item['proposed_action'] ?? '') . '</textarea></label><label>Prioritet<select name="priority"><option value="">Välj</option>';
            foreach (array('low' => 'Låg', 'medium' => 'Medel', 'high' => 'Hög') as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($item['priority'], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label></div>';
            if ($row['parent_inspection_id']) {
                echo '<label>Uppföljningsstatus<select name="follow_up_status"><option value="">Välj</option>';
                foreach (array('fixed' => 'Åtgärdad', 'remains' => 'Kvarstår', 'worsened' => 'Förvärrad', 'not_assessable' => 'Kan ej bedömas') as $value => $label) {
                    echo '<option value="' . esc_attr($value) . '" ' . selected($item['follow_up_status'], $value, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select></label>';
            }
            echo '</fieldset><div class="si-photos" data-snapshot="' . (int) $item['id'] . '">';
            foreach (SSF_Inspections_Core::photos($id, (int) $item['id']) as $photo) {
                self::photo_card($photo, ! $read_only);
            }
            echo '</div>';
            if (! $read_only) {
                if ('none' !== $item['photo_policy']) {
                    echo '<div class="si-photo-actions"><label class="si-button si-secondary">📷 Ta foto<input class="si-photo-input" type="file" accept="image/*" capture="environment" hidden></label><label class="si-button si-secondary">+ Välj bild<input class="si-photo-input" type="file" accept="image/*" multiple hidden></label></div>';
                }
                echo '<button type="button" class="si-button si-save-next">Spara & nästa</button>';
            }
            echo '</article>';
        }
        echo '<a class="si-button si-secondary" href="' . esc_url(self::url($id, 'sammanfattning/')) . '">Sammanfattning</a>';
    }

    private static function previous_remark(int $parent_id, string $item_key): void
    {
        foreach (SSF_Inspections_Core::snapshots($parent_id) as $old) {
            if ($old['item_key'] !== $item_key || ! in_array($old['assessment'], array('remark', 'serious_remark'), true)) {
                continue;
            }
            echo '<aside class="si-previous"><strong>Tidigare anmärkning</strong><p>' . esc_html($old['comment']) . '</p><p>Prioritet: ' . esc_html($old['priority']) . '</p>';
            foreach (SSF_Inspections_Core::photos($parent_id, (int) $old['id']) as $photo) {
                echo '<a href="' . esc_url(self::photo_url((int) $photo['id'])) . '" target="_blank" rel="noopener">Tidigare bild #' . (int) $photo['id'] . '</a> ';
            }
            echo '</aside>';
        }
    }

    private static function photo_card(array $photo, bool $editable): void
    {
        echo '<figure class="si-photo" data-photo="' . (int) $photo['id'] . '"><img src="' . esc_url(self::photo_url((int) $photo['id'])) . '" alt="Inspektionsbild"><figcaption><span>✓ Uppladdad</span>';
        if ($editable) {
            echo '<input aria-label="Bildtext" class="si-caption" value="' . esc_attr($photo['caption']) . '" placeholder="Bildtext"><button type="button" class="si-delete-photo" data-photo="' . (int) $photo['id'] . '">Ta bort</button>';
        } else {
            echo ' ' . esc_html($photo['caption']);
        }
        echo '</figcaption></figure>';
    }

    public static function photo_url(int $photo_id): string
    {
        return add_query_arg(array('ssf_inspection_photo' => $photo_id, '_wpnonce' => wp_create_nonce('ssf_inspection_photo_' . $photo_id)), home_url('/'));
    }

    private static function photo_response(): void
    {
        global $wpdb;
        $photo_id = absint($_GET['ssf_inspection_photo']);
        $photo = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('photos') . ' WHERE id=%d AND deleted_at IS NULL', $photo_id), ARRAY_A);
        if (! $photo || ! is_user_logged_in() || ! SSF_Inspections_Core::can_read((int) $photo['inspection_id']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'ssf_inspection_photo_' . $photo_id)) {
            status_header(403);
            exit;
        }
        nocache_headers();
        header('Content-Type: image/jpeg');
        header('Content-Disposition: inline; filename="inspection-photo-' . $photo_id . '.jpg"');
        header('X-Content-Type-Options: nosniff');
        echo $photo['body']; // Authenticated private binary, never a public media URL.
        exit;
    }

    private static function summary(int $id): void
    {
        $row = SSF_Inspections_Core::inspection($id);
        $items = SSF_Inspections_Core::snapshots($id);
        $counts = array_count_values(array_map(fn ($item) => $item['assessment'] ?: 'missing', $items));
        $photos = SSF_Inspections_Core::photos($id);
        $problems = SSF_Inspections_Core::preflight($id);
        echo '<a href="' . esc_url(self::url($id)) . '">← Sektionsöversikt</a><h1>Sammanfattning</h1><p>' . esc_html(get_the_title((int) $row['ship_id'])) . '</p><div class="si-stats">';
        foreach (array('approved' => 'Godkända', 'remark' => 'Anmärkningar', 'serious_remark' => 'Allvarliga', 'not_checked' => 'Ej kontrollerade', 'not_applicable' => 'Ej relevanta') as $key => $label) {
            echo '<div><strong>' . (int) ($counts[$key] ?? 0) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '<div><strong>' . count($photos) . '</strong><span>Bilder</span></div></div><h2>Åtgärdslista</h2>';
        foreach ($items as $item) {
            if (! in_array($item['assessment'], array('remark', 'serious_remark'), true)) {
                continue;
            }
            echo '<div class="si-card"><strong>' . esc_html($item['section_title'] . ' → ' . $item['title']) . '</strong><p>' . esc_html($item['assessment'] === 'serious_remark' ? 'Allvarlig anmärkning' : 'Anmärkning') . ' · ' . esc_html($item['priority']) . '</p><p>' . nl2br(esc_html($item['comment'])) . '</p><p>' . nl2br(esc_html($item['proposed_action'])) . '</p></div>';
        }
        echo '<h2>Förkontroll</h2><div id="si-preflight">';
        if ($problems) {
            echo '<strong>Kan inte slutföras</strong><ul>';
            foreach ($problems as $problem) {
                $item = current(array_filter($items, fn ($candidate) => (int) $candidate['id'] === $problem['snapshot_id']));
                echo '<li><a href="' . esc_url(self::url($id, 'sektion/' . $item['section_order'] . '#item-' . $item['id'])) . '">' . esc_html($problem['message']) . '</a></li>';
            }
            echo '</ul>';
        } else {
            echo '<p>Alla obligatoriska kontrollpunkter är klara. Kontrollera också att alla lokala ändringar och bilder är synkade.</p>';
        }
        echo '</div>';
        if ('in_progress' === $row['status']) {
            echo '<form id="si-complete" data-id="' . $id . '"><label>Slutbedömning<select name="assessment" required><option value="">Välj</option>';
            foreach (array('approved' => 'Godkänd', 'approved_with_remarks' => 'Godkänd med anmärkningar', 'supplement_required' => 'Komplettering krävs', 'not_approved' => 'Ej godkänd', 'special_review' => 'Särskild prövning') as $key => $label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></label><label>Sammanfattande kommentar<textarea name="summary"></textarea></label><label class="si-confirm"><input type="checkbox" name="confirmed" required> Jag intygar att rapporten återger den genomförda inspektionen.</label><button class="si-button" ' . ($problems ? 'disabled' : '') . '>Slutför inspektion</button></form>';
        } else {
            echo '<a class="si-button" href="' . esc_url(self::url($id, 'rapport/')) . '">Visa rapport</a>';
        }
    }

    private static function report(int $id): void
    {
        $row = SSF_Inspections_Core::inspection($id);
        $items = SSF_Inspections_Core::snapshots($id);
        global $wpdb;
        $version = $wpdb->get_row($wpdb->prepare('SELECT v.version,t.name FROM ' . SSF_Inspections_DB::table('versions') . ' v JOIN ' . SSF_Inspections_DB::table('templates') . ' t ON t.id=v.template_id WHERE v.id=%d', $row['template_version_id']), ARRAY_A);
        echo '<div class="si-print-hide"><a href="' . esc_url(self::url($id)) . '">← Inspektion</a> <button onclick="window.print()" class="si-button">Skriv ut rapport</button></div><div class="si-report"><header><strong>SVERIGES SEGELFARTYGSFÖRBUND</strong><h1>Inspektionsrapport</h1></header>';
        foreach (array('Fartyg' => get_the_title((int) $row['ship_id']), 'Datum' => $row['inspection_date'], 'Plats' => $row['location'], 'Inspektör' => get_the_author_meta('display_name', (int) $row['inspector_user_id']), 'Representant' => $row['representative'], 'Mall' => ($version['name'] ?? '') . ' v' . ($version['version'] ?? ''), 'Slutbedömning' => $row['final_assessment'], 'Sammanfattning' => $row['summary'], 'Signering' => ($row['signed_name'] ?? '') . ' · ' . ($row['signed_at'] ?? ''), 'Revision' => '#' . $row['revision']) as $label => $value) {
            echo '<p><strong>' . esc_html($label) . ':</strong> ' . nl2br(esc_html($value)) . '</p>';
        }
        $section = null;
        foreach ($items as $item) {
            if ($item['section_key'] !== $section) {
                $section = $item['section_key'];
                echo '<h2>' . esc_html($item['section_title']) . '</h2>';
            }
            echo '<div class="si-report-item"><strong>' . esc_html($item['title']) . '</strong> · ' . esc_html($item['assessment'] ?: 'Ej bedömd');
            if (in_array($item['assessment'], array('remark', 'serious_remark'), true)) {
                echo '<p>' . nl2br(esc_html($item['comment'])) . '</p><p>Åtgärd: ' . nl2br(esc_html($item['proposed_action'])) . ' · Prioritet: ' . esc_html($item['priority']) . '</p>';
            }
            foreach (SSF_Inspections_Core::photos($id, (int) $item['id']) as $photo) {
                echo '<figure><img src="' . esc_url(self::photo_url((int) $photo['id'])) . '" alt="Inspektionsbild"><figcaption>' . esc_html($photo['caption']) . '</figcaption></figure>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    public static function rest_routes(): void
    {
        $base = 'ssf-inspections/v1';
        $permission = fn () => SSF_Inspections_Core::inspector() && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'] ?? '', 'wp_rest');
        register_rest_route($base, '/create', array('methods' => 'POST', 'callback' => array(__CLASS__, 'rest_create'), 'permission_callback' => $permission));
        register_rest_route($base, '/(?P<id>\d+)/answer', array('methods' => 'POST', 'callback' => array(__CLASS__, 'rest_answer'), 'permission_callback' => $permission));
        register_rest_route($base, '/(?P<id>\d+)/photo', array('methods' => 'POST', 'callback' => array(__CLASS__, 'rest_photo'), 'permission_callback' => $permission));
        register_rest_route($base, '/(?P<id>\d+)/photo/(?P<photo>\d+)', array('methods' => 'DELETE,POST', 'callback' => array(__CLASS__, 'rest_photo_edit'), 'permission_callback' => $permission));
        register_rest_route($base, '/(?P<id>\d+)/preflight', array('methods' => 'GET', 'callback' => array(__CLASS__, 'rest_preflight'), 'permission_callback' => $permission));
        register_rest_route($base, '/(?P<id>\d+)/complete', array('methods' => 'POST', 'callback' => array(__CLASS__, 'rest_complete'), 'permission_callback' => $permission));
        register_rest_route($base, '/(?P<id>\d+)/followup', array('methods' => 'POST', 'callback' => array(__CLASS__, 'rest_followup'), 'permission_callback' => $permission));
        register_rest_route($base, '/(?P<id>\d+)/reopen', array('methods' => 'POST', 'callback' => array(__CLASS__, 'rest_reopen'), 'permission_callback' => $permission));
    }

    public static function rest_create(WP_REST_Request $request)
    {
        $id = SSF_Inspections_Core::create(absint($request['ship_id']), absint($request['version_id']), (string) $request['type'], (string) $request['date'], (string) $request['location'], (string) $request['representative']);
        return is_wp_error($id) ? $id : array('id' => $id, 'url' => self::url($id));
    }

    public static function rest_answer(WP_REST_Request $request)
    {
        $result = SSF_Inspections_Core::save_answer(absint($request['id']), absint($request['snapshot_id']), (array) $request->get_json_params(), (string) $request['operation_id']);
        return is_wp_error($result) ? $result : array('revision' => $result);
    }

    public static function rest_photo(WP_REST_Request $request)
    {
        $file = $request->get_file_params()['photo'] ?? array();
        $id = SSF_Inspections_Core::add_photo(absint($request['id']), absint($request['snapshot_id']), (string) $request['operation_id'], $file);
        return is_wp_error($id) ? $id : array('id' => $id, 'url' => self::photo_url($id));
    }

    public static function rest_photo_edit(WP_REST_Request $request)
    {
        global $wpdb;
        $id = absint($request['id']);
        $photo_id = absint($request['photo']);
        if (! SSF_Inspections_Core::can_edit($id)) {
            return new WP_Error('forbidden', 'Bilden kan inte ändras.', array('status' => 403));
        }
        $where = array('id' => $photo_id, 'inspection_id' => $id, 'deleted_at' => null);
        if ('DELETE' === $request->get_method()) {
            $ok = $wpdb->update(SSF_Inspections_DB::table('photos'), array('deleted_at' => current_time('mysql', true), 'body' => ''), $where);
            if ($ok) {
                SSF_Inspections_Core::event($id, 'photo_deleted', array('photo_id' => $photo_id));
            }
        } else {
            $ok = $wpdb->update(SSF_Inspections_DB::table('photos'), array('caption' => sanitize_text_field($request['caption'])), $where);
        }
        return $ok ? array('ok' => true) : new WP_Error('photo', 'Bilden hittades inte.', array('status' => 404));
    }

    public static function rest_preflight(WP_REST_Request $request)
    {
        $id = absint($request['id']);
        return SSF_Inspections_Core::can_read($id) ? array('problems' => SSF_Inspections_Core::preflight($id)) : new WP_Error('forbidden', 'Åtkomst nekad.', array('status' => 403));
    }

    public static function rest_complete(WP_REST_Request $request)
    {
        if (absint($request['pending_count']) > 0 || ! isset($request['pending_count'])) {
            return new WP_Error('pending', 'Lokala ändringar väntar på synkning.', array('status' => 409));
        }
        $result = SSF_Inspections_Core::complete(absint($request['id']), (string) $request['assessment'], (string) $request['summary'], (bool) $request['confirmed']);
        return is_wp_error($result) ? $result : array('ok' => true, 'url' => self::url(absint($request['id']), 'rapport/'));
    }

    public static function rest_followup(WP_REST_Request $request)
    {
        $parent_id = absint($request['id']);
        $parent = SSF_Inspections_Core::inspection($parent_id);
        if (! $parent || ! SSF_Inspections_Core::can_read($parent_id) || 'completed' !== $parent['status']) {
            return new WP_Error('forbidden', 'Uppföljning nekades.', array('status' => 403));
        }
        $id = SSF_Inspections_Core::create((int) $parent['ship_id'], (int) $parent['template_version_id'], 'Uppföljningsinspektion', wp_date('Y-m-d'), $parent['location'], $parent['representative'], $parent_id);
        return is_wp_error($id) ? $id : array('id' => $id, 'url' => self::url($id));
    }

    public static function rest_reopen(WP_REST_Request $request)
    {
        $result = SSF_Inspections_Core::reopen(absint($request['id']), (string) $request['reason']);
        return is_wp_error($result) ? $result : array('ok' => true);
    }

    public static function admin_menu(): void
    {
        add_submenu_page('ssf-overview', 'Inspektioner', 'Inspektioner', 'ssf_inspect_v2', 'ssf-inspections', array(__CLASS__, 'admin_inspections'));
        add_submenu_page('ssf-overview', 'Inspektionsmallar', 'Inspektionsmallar', 'ssf_manage_inspections', 'ssf-inspection-templates', array(__CLASS__, 'admin_templates'));
    }

    public static function admin_inspections(): void
    {
        if (! SSF_Inspections_Core::inspector()) {
            wp_die('Åtkomst nekad.');
        }
        global $wpdb;
        $where = SSF_Inspections_Core::manager() ? '' : $wpdb->prepare(' WHERE i.inspector_user_id=%d', get_current_user_id());
        $rows = $wpdb->get_results('SELECT i.* FROM ' . SSF_Inspections_DB::table('inspections') . " i $where ORDER BY i.updated_at DESC LIMIT 200", ARRAY_A);
        echo '<div class="wrap"><h1>Inspektioner</h1><p><a class="button button-primary" href="' . esc_url(self::url()) . '">Öppna mobil arbetsyta</a></p>';
        foreach (array('in_progress' => 'Pågående', 'needs_action' => 'Behöver åtgärd', 'completed' => 'Avslutade') as $group => $title) {
            echo '<h2>' . esc_html($title) . '</h2><table class="widefat striped"><thead><tr><th>Fartyg</th><th>Typ</th><th>Inspektör</th><th>Datum</th><th>Progress/status</th><th>Anmärkningar</th><th>Senast ändrad</th></tr></thead><tbody>';
            $shown = 0;
            foreach ($rows as $row) {
                $items = SSF_Inspections_Core::snapshots((int) $row['id']);
                $done = count(array_filter($items, fn ($item) => ! empty($item['assessment'])));
                $remarks = count(array_filter($items, fn ($item) => in_array($item['assessment'], array('remark', 'serious_remark'), true)));
                $bucket = 'completed' === $row['status'] ? 'completed' : ($remarks ? 'needs_action' : 'in_progress');
                if ($bucket !== $group) {
                    continue;
                }
                ++$shown;
                $percent = $items ? (int) round($done * 100 / count($items)) : 0;
                echo '<tr><td><a href="' . esc_url(self::url((int) $row['id'])) . '">' . esc_html(get_the_title((int) $row['ship_id'])) . '</a></td><td>' . esc_html($row['inspection_type']) . '</td><td>' . esc_html(get_the_author_meta('display_name', (int) $row['inspector_user_id'])) . '</td><td>' . esc_html($row['inspection_date']) . '</td><td>' . $percent . ' % · ' . esc_html($row['status']) . '</td><td>' . $remarks . '</td><td>' . esc_html($row['updated_at']) . '</td></tr>';
            }
            if (! $shown) {
                echo '<tr><td colspan="7">Inga inspektioner.</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public static function admin_templates(): void
    {
        require SSF_INSPECTIONS_PATH . 'includes/admin-templates.php';
    }
}
