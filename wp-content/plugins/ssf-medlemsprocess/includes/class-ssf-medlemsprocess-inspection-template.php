<?php
/**
 * Versioned inspection-template registry and block editor.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) { exit; }

final class SSF_Medlemsprocess_Inspection_Template
{
    public const TYPE_PHYSICAL = 'physical_membership_inspection';
    private const OPTION = 'ssf_membership_inspection_templates';
    private const PAGE = 'ssf-inspection-template';

    public function __construct()
    {
        add_action('admin_post_ssf_inspection_template_action', array($this, 'handle_action'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_editor'));
    }

    public static function can_manage(): bool
    {
        // manage_options is deliberately a break-glass path independent of SSF groups.
        return current_user_can('manage_options') || current_user_can('ssf_manage_application_settings');
    }

    public static function application_fields(): array
    {
        return array(
            'ship_name' => 'Fartyg', 'applicant_name' => 'Fartygsombud', 'applicant_organization' => 'Ägare / organisation',
            'registry_number' => 'Signal / reg.nr', 'hull_type' => 'Skrovtyp', 'material' => 'Material',
            'build_year' => 'Byggår', 'build_place' => 'Byggplats', 'main_deck_length' => 'Längd i huvuddäck',
            'beam' => 'Bredd', 'registration' => 'Registrering', 'previous_use' => 'Tidigare användning',
            'history' => 'Historik', 'original_rig' => 'Ursprunglig rigg', 'current_rig' => 'Nuvarande rigg',
            'masts' => 'Master', 'sail_area' => 'Segelyta', 'aux_engine' => 'Hjälpmaskin',
            'rig_description' => 'Riggbeskrivning', 'restoration_condition' => 'Restaureringsläge',
            'restoration_goal' => 'Restaureringsmål', 'preservation_plan' => 'Bevarandeplan',
            'restoration_timeline' => 'Tidplan', 'application_documents' => 'Ansökningshandlingar',
        );
    }

    public static function normalize(array $template): array
    {
        $template = wp_parse_args($template, array(
            'id' => '', 'slug' => '', 'name' => 'Namnlös inspektionsmall', 'protocol_title' => '',
            'version' => '0.1', 'type' => self::TYPE_PHYSICAL, 'type_label' => 'Fysisk inspektion ombord',
            'status' => 'draft', 'is_default' => false, 'blocks' => array(), 'sections' => array(),
            'overview_photos' => array(), 'disclaimer' => '', 'created_at' => '', 'updated_at' => '',
        ));
        if ('Fysisk inspektion ombord' === $template['type']) { $template['type'] = self::TYPE_PHYSICAL; }
        $template['id'] = $template['slug'] . '@' . $template['version'];
        if (empty($template['blocks']) && ! empty($template['sections'])) {
            $template['blocks'] = self::blocks_from_sections((array) $template['sections']);
        }
        if (! empty($template['blocks'])) {
            $template['sections'] = self::sections_from_blocks((array) $template['blocks']);
        }
        return $template;
    }

    private static function blocks_from_sections(array $sections): array
    {
        $blocks = array(array('id' => 'application-context', 'type' => 'application_context', 'title' => 'Ansökningsuppgifter', 'fields' => array('ship_name', 'applicant_name', 'registry_number')));
        foreach ($sections as $section) {
            $blocks[] = array('id' => 'section-' . sanitize_key((string) ($section['id'] ?? wp_generate_uuid4())), 'type' => 'section', 'title' => (string) ($section['title'] ?? 'Avsnitt'), 'step' => absint($section['step'] ?? 1));
            foreach ((array) ($section['questions'] ?? array()) as $question) {
                $question_id = sanitize_key((string) ($question['id'] ?? wp_generate_uuid4()));
                $options = array(); $required_ids = array();
                foreach ((array) ($question['options'] ?? array()) as $index => $label) {
                    $option_id = 'option-' . $question_id . '-' . ($index + 1);
                    $options[] = array('id' => $option_id, 'label' => (string) $label);
                    if (in_array($label, (array) ($question['comment_required_for'] ?? array()), true)) { $required_ids[] = $option_id; }
                }
                $blocks[] = array(
                    'id' => 'question-' . $question_id, 'type' => 'question', 'question_id' => $question_id,
                    'title' => (string) ($question['text'] ?? ''), 'help' => (string) ($question['help'] ?? ''),
                    'required' => ! empty($question['required']), 'options' => $options,
                    'context_fields' => array_values((array) ($question['context'] ?? array())),
                    'comment_rule' => $required_ids ? 'selected' : 'optional', 'comment_option_ids' => $required_ids,
                    'photo_rule' => ! empty($question['photo_allowed']) ? 'allowed' : 'none', 'photo_option_ids' => array(),
                );
            }
        }
        $blocks[] = array('id' => 'overview-photos', 'type' => 'photo', 'title' => 'Översiktsbilder', 'photo_rule' => 'recommended');
        $blocks[] = array('id' => 'inspection-summary', 'type' => 'summary', 'title' => 'Kort sammanfattning / motivering', 'required' => true);
        $blocks[] = array('id' => 'inspection-confirmation', 'type' => 'confirmation', 'title' => 'Bekräftelse och färdigställande');
        return $blocks;
    }

    private static function sections_from_blocks(array $blocks): array
    {
        $sections = array(); $section_index = -1;
        foreach ($blocks as $block) {
            if ('section' === ($block['type'] ?? '')) {
                $section_index++;
                $sections[$section_index] = array('id' => sanitize_key(str_replace('section-', '', (string) $block['id'])), 'title' => (string) ($block['title'] ?? 'Avsnitt'), 'step' => absint($block['step'] ?? ($section_index + 1)), 'questions' => array(), 'blocks' => array());
                continue;
            }
            if ($section_index < 0) { continue; }
            $sections[$section_index]['blocks'][] = $block;
            if ('question' !== ($block['type'] ?? '')) { continue; }
            $labels = array(); $label_by_id = array();
            foreach ((array) ($block['options'] ?? array()) as $option) {
                $labels[] = (string) ($option['label'] ?? '');
                $label_by_id[(string) ($option['id'] ?? '')] = (string) ($option['label'] ?? '');
            }
            $comment_required = array();
            if ('always' === ($block['comment_rule'] ?? '')) { $comment_required = $labels; }
            if ('selected' === ($block['comment_rule'] ?? '')) {
                foreach ((array) ($block['comment_option_ids'] ?? array()) as $id) { if (isset($label_by_id[$id])) { $comment_required[] = $label_by_id[$id]; } }
            }
            $sections[$section_index]['questions'][] = array(
                'id' => (string) ($block['question_id'] ?? $block['id']), 'block_id' => (string) $block['id'],
                'text' => (string) ($block['title'] ?? ''), 'help' => (string) ($block['help'] ?? ''),
                'options' => $labels, 'option_ids' => array_keys($label_by_id), 'required' => ! empty($block['required']),
                'context' => array_values((array) ($block['context_fields'] ?? array())),
                'comment_rule' => (string) ($block['comment_rule'] ?? 'optional'), 'comment_required_for' => $comment_required,
                'photo_rule' => (string) ($block['photo_rule'] ?? 'allowed'), 'photo_allowed' => 'none' !== ($block['photo_rule'] ?? 'allowed'),
                'photo_option_ids' => array_values((array) ($block['photo_option_ids'] ?? array())),
            );
        }
        return array_values($sections);
    }

    public static function all(): array
    {
        $templates = array();
        foreach ((array) get_option(self::OPTION, array()) as $key => $template) {
            if (! is_array($template) || empty($template['slug']) || empty($template['version'])) { continue; }
            $normalized = self::normalize($template);
            $templates[$normalized['id']] = $normalized;
        }
        uasort($templates, static function (array $a, array $b): int {
            $family = strcasecmp((string) $a['name'], (string) $b['name']);
            return $family ?: -version_compare((string) $a['version'], (string) $b['version']);
        });
        return $templates;
    }

    public static function eligible(string $type = self::TYPE_PHYSICAL): array
    {
        return array_filter(self::all(), static function (array $template) use ($type): bool {
            return $type === ($template['type'] ?? '') && 'published' === ($template['status'] ?? '');
        });
    }

    public static function default_for_type(string $type): array
    {
        $eligible = self::eligible($type);
        foreach ($eligible as $template) { if (! empty($template['is_default'])) { return $template; } }
        return $eligible ? reset($eligible) : array();
    }

    public static function resolve_eligible(string $key, string $type): array
    {
        $eligible = self::eligible($type);
        if ($key && isset($eligible[$key])) { return $eligible[$key]; }
        if ($key) { return array(); } // Fail closed for crafted draft/archive identifiers.
        return self::default_for_type($type);
    }

    public static function duplicate(string $source_key, string $new_version): bool
    {
        if (! self::can_manage() || ! preg_match('/^\d+\.\d+(?:\.\d+)?$/', $new_version)) { return false; }
        $stored = (array) get_option(self::OPTION, array());
        $source = self::all()[$source_key] ?? array();
        if (! $source) { return false; }
        $new_key = $source['slug'] . '@' . $new_version;
        if (isset($stored[$new_key])) { return false; }
        $copy = $source;
        $copy['id'] = $new_key; $copy['version'] = $new_version; $copy['status'] = 'draft'; $copy['is_default'] = false;
        $copy['created_at'] = current_time('mysql'); $copy['updated_at'] = current_time('mysql');
        $stored[$new_key] = $copy;
        return update_option(self::OPTION, $stored, false);
    }

    public static function save_draft(string $key, array $input, string $expected_updated_at): array
    {
        if (! self::can_manage()) { return array('ok' => false, 'message' => 'Du saknar behörighet.'); }
        $stored = (array) get_option(self::OPTION, array()); $current = isset($stored[$key]) ? self::normalize((array) $stored[$key]) : array();
        if (! $current || 'draft' !== $current['status']) { return array('ok' => false, 'message' => 'Endast utkast kan redigeras.'); }
        if (! $expected_updated_at || ! hash_equals((string) $current['updated_at'], $expected_updated_at)) { return array('ok' => false, 'message' => 'Mallen har ändrats i en annan flik. Ladda om sidan.'); }
        $clean = self::sanitize_template($input, $current);
        $errors = self::validate($clean, false);
        if ($errors) { return array('ok' => false, 'message' => implode(' ', $errors)); }
        $clean['updated_at'] = current_time('mysql'); $stored[$key] = $clean;
        $saved = update_option(self::OPTION, $stored, false);
        return array('ok' => $saved || (array) get_option(self::OPTION, array()) === $stored, 'template' => $clean);
    }

    public static function publish(string $key): array
    {
        if (! self::can_manage()) { return array('ok' => false, 'message' => 'Du saknar behörighet.'); }
        $stored = (array) get_option(self::OPTION, array()); $template = isset($stored[$key]) ? self::normalize((array) $stored[$key]) : array();
        if (! $template || 'draft' !== $template['status']) { return array('ok' => false, 'message' => 'Endast utkast kan publiceras.'); }
        $errors = self::validate($template, true);
        if ($errors) { return array('ok' => false, 'message' => implode(' ', $errors)); }
        $template['status'] = 'published'; $template['published_at'] = current_time('mysql'); $template['updated_at'] = current_time('mysql');
        $stored[$key] = $template;
        $saved = update_option(self::OPTION, $stored, false);
        return array('ok' => $saved || (array) get_option(self::OPTION, array()) === $stored);
    }

    private static function sanitize_template(array $input, array $current): array
    {
        $clean = $current;
        $clean['name'] = sanitize_text_field((string) ($input['name'] ?? $current['name']));
        $clean['protocol_title'] = sanitize_text_field((string) ($input['protocol_title'] ?? $current['protocol_title']));
        $clean['type'] = self::TYPE_PHYSICAL;
        $clean['type_label'] = 'Fysisk inspektion ombord';
        $clean['disclaimer'] = sanitize_textarea_field((string) ($input['disclaimer'] ?? $current['disclaimer']));
        $decoded = $input['blocks'] ?? array();
        if (is_string($decoded)) { $decoded = json_decode($decoded, true); }
        $clean['blocks'] = array(); $ids = array();
        $allowed = array('section', 'info', 'question', 'application_context', 'textarea', 'photo', 'summary', 'recommendation', 'confirmation');
        foreach ((array) $decoded as $block) {
            $type = sanitize_key((string) ($block['type'] ?? '')); $id = sanitize_key((string) ($block['id'] ?? ''));
            if (! in_array($type, $allowed, true) || ! $id || isset($ids[$id])) { continue; }
            $ids[$id] = true;
            $item = array('id' => $id, 'type' => $type, 'title' => sanitize_text_field((string) ($block['title'] ?? '')));
            if ('section' === $type) { $item['step'] = max(1, absint($block['step'] ?? 1)); }
            if (in_array($type, array('info', 'textarea', 'summary', 'recommendation', 'confirmation'), true)) {
                $item['content'] = sanitize_textarea_field((string) ($block['content'] ?? ''));
                $item['required'] = ! empty($block['required']);
            }
            if ('application_context' === $type) {
                $item['fields'] = array_values(array_intersect(array_keys(self::application_fields()), array_map('sanitize_key', (array) ($block['fields'] ?? array()))));
            }
            if ('photo' === $type) { $item['photo_rule'] = self::one_of($block['photo_rule'] ?? '', array('allowed', 'recommended', 'required'), 'allowed'); }
            if ('question' === $type) {
                $item['question_id'] = sanitize_key((string) ($block['question_id'] ?? $id));
                $item['help'] = sanitize_textarea_field((string) ($block['help'] ?? ''));
                $item['required'] = ! empty($block['required']);
                $item['context_fields'] = array_values(array_intersect(array_keys(self::application_fields()), array_map('sanitize_key', (array) ($block['context_fields'] ?? array()))));
                $item['comment_rule'] = self::one_of($block['comment_rule'] ?? '', array('none', 'optional', 'always', 'selected'), 'optional');
                $item['photo_rule'] = self::one_of($block['photo_rule'] ?? '', array('none', 'allowed', 'recommended', 'required', 'selected'), 'allowed');
                $item['options'] = array(); $option_ids = array();
                foreach ((array) ($block['options'] ?? array()) as $option) {
                    $option_id = sanitize_key((string) ($option['id'] ?? '')); $label = sanitize_text_field((string) ($option['label'] ?? ''));
                    if (! $option_id || ! $label || isset($option_ids[$option_id])) { continue; }
                    $option_ids[$option_id] = true; $item['options'][] = array('id' => $option_id, 'label' => $label);
                }
                $item['comment_option_ids'] = array_values(array_intersect(array_keys($option_ids), array_map('sanitize_key', (array) ($block['comment_option_ids'] ?? array()))));
                $item['photo_option_ids'] = array_values(array_intersect(array_keys($option_ids), array_map('sanitize_key', (array) ($block['photo_option_ids'] ?? array()))));
            }
            $clean['blocks'][] = $item;
        }
        return self::normalize($clean);
    }

    private static function one_of($value, array $allowed, string $fallback): string
    {
        $value = sanitize_key((string) $value);
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function validate(array $template, bool $publish): array
    {
        $errors = array(); $ids = array(); $question_ids = array(); $has_section = false; $question_count = 0;
        if (! trim((string) $template['name'])) { $errors[] = 'Namn saknas.'; }
        foreach ((array) $template['blocks'] as $block) {
            $id = (string) ($block['id'] ?? '');
            if (! $id || isset($ids[$id])) { $errors[] = 'Block-ID måste vara unika.'; } $ids[$id] = true;
            if ('section' === ($block['type'] ?? '')) { $has_section = true; }
            if ('question' === ($block['type'] ?? '')) {
                $question_count++; $qid = (string) ($block['question_id'] ?? '');
                if (! $qid || isset($question_ids[$qid])) { $errors[] = 'Fråge-ID måste vara unika.'; } $question_ids[$qid] = true;
                if (! trim((string) ($block['title'] ?? '')) || count((array) ($block['options'] ?? array())) < 2) { $errors[] = 'Varje fråga behöver text och minst två svar.'; }
            }
        }
        if ($publish && (! $has_section || ! $question_count)) { $errors[] = 'En publicerad mall behöver minst ett avsnitt och en fråga.'; }
        return array_values(array_unique($errors));
    }

    public static function usage_count(string $key): int
    {
        $count = 0;
        $ids = get_posts(array('post_type' => SSF_Medlemsprocess_Inspection::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true));
        foreach ($ids as $id) {
            $record = SSF_Medlemsprocess_Inspection::record((int) $id);
            $record_key = (string) ($record['template_id'] ?? (($record['template_slug'] ?? '') . '@' . ($record['template_version'] ?? '')));
            if ($key === $record_key) { $count++; }
        }
        return $count;
    }

    public function enqueue_editor(string $hook): void
    {
        if (empty($_GET['page']) || self::PAGE !== sanitize_key(wp_unslash($_GET['page']))) { return; }
        wp_enqueue_style('ssf-inspection-template-editor', SSF_MEDLEMSPROCESS_URL . 'assets/css/ssf-inspection-template-editor.css', array(), SSF_MEDLEMSPROCESS_VERSION);
        wp_enqueue_script('ssf-inspection-template-editor', SSF_MEDLEMSPROCESS_URL . 'assets/js/ssf-inspection-template-editor.js', array(), SSF_MEDLEMSPROCESS_VERSION, true);
        wp_localize_script('ssf-inspection-template-editor', 'ssfInspectionTemplateEditor', array('fields' => self::application_fields()));
    }

    public function render_admin_page(): void
    {
        if (! self::can_manage()) { wp_die('Du saknar behörighet.'); }
        $key = sanitize_text_field(wp_unslash($_GET['template'] ?? ''));
        if ($key) { $this->render_editor($key); return; }
        $filter = sanitize_key(wp_unslash($_GET['status'] ?? 'all')); $templates = self::all();
        echo '<div class="wrap ssf-template-library"><h1 class="wp-heading-inline">Inspektionsmallar</h1><p>Publicerade mallar är låsta och används via versionsbundna snapshots. Ändringar görs i ett nytt utkast.</p>';
        $this->notice();
        echo '<nav class="nav-tab-wrapper">';
        foreach (array('all' => 'Alla', 'published' => 'Publicerade', 'draft' => 'Utkast', 'archived' => 'Arkiverade') as $value => $label) {
            echo '<a class="nav-tab ' . ($filter === $value ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(array('page' => self::PAGE, 'status' => $value), admin_url('admin.php'))) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav><form class="ssf-template-new" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><h2>Ny tom mall</h2><input type="hidden" name="action" value="ssf_inspection_template_action"><input type="hidden" name="operation" value="create">'; wp_nonce_field('ssf_inspection_template_create');
        echo '<label>Namn <input name="name" required></label><label>Familjenyckel <input name="slug" pattern="[a-z0-9-]+" required></label><label>Version <input name="version" value="0.1" pattern="[0-9]+\.[0-9]+(?:\.[0-9]+)?" required></label><button class="button button-primary">Skapa utkast</button></form>';
        echo '<table class="widefat striped"><thead><tr><th>Namn</th><th>Version</th><th>Status</th><th>Typ</th><th>Standard</th><th>Uppdaterad</th><th>Användning</th><th>Åtgärder</th></tr></thead><tbody>';
        foreach ($templates as $template) {
            if ('all' !== $filter && $filter !== $template['status']) { continue; }
            $usage = self::usage_count($template['id']); $edit_url = add_query_arg(array('page' => self::PAGE, 'template' => $template['id']), admin_url('admin.php'));
            echo '<tr><td><strong><a href="' . esc_url($edit_url) . '">' . esc_html($template['name']) . '</a></strong><br><code>' . esc_html($template['slug']) . '</code></td><td>' . esc_html($template['version']) . '</td><td>' . esc_html($this->status_label($template['status'])) . '</td><td>' . esc_html($template['type_label']) . '</td><td>' . (! empty($template['is_default']) ? 'Ja' : '–') . '</td><td>' . esc_html($template['updated_at'] ?: '–') . '</td><td>' . esc_html((string) $usage) . '</td><td><a class="button button-small" href="' . esc_url($edit_url) . '">Visa</a> ';
            if ('published' === $template['status']) { $this->inline_action('duplicate', $template['id'], 'Duplicera', '<input class="small-text" name="new_version" placeholder="1.1" required>'); }
            if ('published' === $template['status'] && empty($template['is_default'])) { $this->inline_action('default', $template['id'], 'Sätt standard'); $this->inline_action('archive', $template['id'], 'Arkivera'); }
            if ('draft' === $template['status'] && 0 === $usage) { $this->inline_action('delete', $template['id'], 'Radera'); }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function render_editor(string $key): void
    {
        $template = self::all()[$key] ?? array(); if (! $template) { wp_die('Mallen hittades inte.'); }
        $draft = 'draft' === $template['status'];
        $preview_applications = get_posts(array('post_type' => SSF_Medlemsprocess_Application::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 100, 'orderby' => 'modified', 'order' => 'DESC'));
        echo '<div class="wrap ssf-template-editor-wrap"><p><a href="' . esc_url(add_query_arg('page', self::PAGE, admin_url('admin.php'))) . '">← Mallbibliotek</a></p><h1>' . esc_html($template['name'] . ' · ' . $template['version']) . '</h1>';
        $this->notice(); echo '<p><span class="ssf-template-status status-' . esc_attr($template['status']) . '">' . esc_html($this->status_label($template['status'])) . '</span>' . (! empty($template['is_default']) ? ' <strong>Standardmall</strong>' : '') . '</p>';
        if (! $draft) { echo '<div class="notice notice-info inline"><p>Publicerade och arkiverade versioner är skrivskyddade. Duplicera versionen för att ändra den.</p></div>'; }
        echo '<form id="ssf-template-editor" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-readonly="' . ($draft ? '0' : '1') . '"><input type="hidden" name="action" value="ssf_inspection_template_action"><input type="hidden" name="operation" value="save"><input type="hidden" name="template_id" value="' . esc_attr($key) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr($template['updated_at']) . '">'; wp_nonce_field('ssf_inspection_template_' . $key);
        echo '<input type="hidden" name="blocks" id="ssf-template-blocks-json" value="' . esc_attr(wp_json_encode($template['blocks'], JSON_UNESCAPED_UNICODE)) . '"><div class="ssf-template-meta"><label>Namn<input name="name" value="' . esc_attr($template['name']) . '" ' . disabled(! $draft, true, false) . '></label><label>Protokollrubrik<input name="protocol_title" value="' . esc_attr($template['protocol_title']) . '" ' . disabled(! $draft, true, false) . '></label><label>Typ<input value="' . esc_attr($template['type_label']) . '" disabled></label></div>';
        echo '<div class="ssf-block-editor"><aside class="ssf-block-palette"><h2>Lägg till block</h2>'; foreach (array('section'=>'Avsnitt','info'=>'Information','question'=>'Fråga','application_context'=>'Ansökningsdata','textarea'=>'Textfält','photo'=>'Foto','summary'=>'Sammanfattning','recommendation'=>'Rekommendation','confirmation'=>'Bekräftelse') as $type=>$label) { echo '<button type="button" class="button" data-add-block="' . esc_attr($type) . '" ' . disabled(! $draft, true, false) . '>' . esc_html($label) . '</button>'; } echo '</aside><main><div id="ssf-template-canvas" class="ssf-template-canvas"></div><p class="description">Markera ett block för inställningar. Dra inte: använd pilarna för stabil ordning och tangentbordsstöd.</p></main><aside id="ssf-block-settings" class="ssf-block-settings"><h2>Blockinställningar</h2><p>Välj ett block.</p></aside></div>';
        echo '<label class="ssf-template-disclaimer">Ansvarsfriskrivning<textarea name="disclaimer" rows="3" ' . disabled(! $draft, true, false) . '>' . esc_textarea($template['disclaimer']) . '</textarea></label><section class="ssf-template-preview"><h2>Förhandsvisning</h2><div class="ssf-preview-toolbar"><label>Ansökan <select id="ssf-preview-application"><option value="" data-snapshot="{}">Exempeldata</option>';
        foreach ($preview_applications as $application) { $snapshot = SSF_Medlemsprocess_Inspection::application_snapshot((int) $application->ID); echo '<option value="' . esc_attr((string) $application->ID) . '" data-snapshot="' . esc_attr(wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE)) . '">' . esc_html(($snapshot['number'] ?? '') . ' · ' . ($snapshot['ship_name'] ?? $application->post_title)) . '</option>'; }
        echo '</select></label> <label>Vy <select id="ssf-preview-viewport"><option value="desktop">Dator</option><option value="tablet">Surfplatta</option><option value="mobile">Mobil</option></select></label></div><div id="ssf-template-preview"></div></section>';
        if ($draft) { echo '<div class="ssf-template-savebar"><span data-save-state>Inga osparade ändringar</span><button class="button button-primary" type="submit">Spara utkast</button></div>'; }
        echo '</form><div class="ssf-template-actions">';
        if ($draft) { $this->inline_action('publish', $key, 'Publicera version', '', 'button button-primary'); }
        else { $this->inline_action('duplicate', $key, 'Duplicera till ny version', '<input class="small-text" name="new_version" placeholder="1.1" required>'); }
        echo '</div></div>';
    }

    private function inline_action(string $operation, string $key, string $label, string $extra = '', string $class = 'button button-small'): void
    {
        echo '<form class="ssf-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_inspection_template_action"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="template_id" value="' . esc_attr($key) . '">' . $extra; wp_nonce_field('ssf_inspection_template_' . $key); echo '<button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form> ';
    }

    public function handle_action(): void
    {
        if (! self::can_manage()) { wp_die('Du saknar behörighet.', '', array('response' => 403)); }
        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? '')); $key = sanitize_text_field(wp_unslash($_POST['template_id'] ?? ''));
        if ('create' === $operation) {
            check_admin_referer('ssf_inspection_template_create');
            $slug = sanitize_title(wp_unslash($_POST['slug'] ?? '')); $version = sanitize_text_field(wp_unslash($_POST['version'] ?? ''));
            $key = $slug . '@' . $version; $stored = (array) get_option(self::OPTION, array());
            if (! $slug || ! preg_match('/^\d+\.\d+(?:\.\d+)?$/', $version) || isset($stored[$key])) { wp_die('Mallfamilj eller version är ogiltig eller finns redan.'); }
            $now = current_time('mysql'); $stored[$key] = self::normalize(array('slug'=>$slug,'version'=>$version,'name'=>sanitize_text_field(wp_unslash($_POST['name'] ?? '')),'status'=>'draft','created_at'=>$now,'updated_at'=>$now,'blocks'=>array()));
            update_option(self::OPTION, $stored, false); $this->redirect('created', $key);
        }
        check_admin_referer('ssf_inspection_template_' . $key); $stored = (array) get_option(self::OPTION, array()); $template = self::all()[$key] ?? array();
        if (! $template) { wp_die('Mallen hittades inte.'); }
        $ok = false; $message = '';
        if ('save' === $operation) {
            $result = self::save_draft($key, array('name'=>wp_unslash($_POST['name'] ?? ''),'protocol_title'=>wp_unslash($_POST['protocol_title'] ?? ''),'disclaimer'=>wp_unslash($_POST['disclaimer'] ?? ''),'blocks'=>wp_unslash($_POST['blocks'] ?? '[]')), sanitize_text_field(wp_unslash($_POST['expected_updated_at'] ?? '')));
            $ok = ! empty($result['ok']); $message = (string) ($result['message'] ?? '');
        } elseif ('duplicate' === $operation) { $ok = self::duplicate($key, sanitize_text_field(wp_unslash($_POST['new_version'] ?? ''))); }
        elseif ('publish' === $operation) { $result = self::publish($key); $ok = ! empty($result['ok']); $message = (string) ($result['message'] ?? ''); }
        elseif ('default' === $operation && 'published' === $template['status']) {
            foreach ($stored as $stored_key => &$item) { if (($item['type'] ?? self::TYPE_PHYSICAL) === $template['type']) { $item['is_default'] = $stored_key === $key; } } unset($item); $ok = update_option(self::OPTION, $stored, false);
        } elseif ('archive' === $operation && 'published' === $template['status'] && empty($template['is_default'])) { $stored[$key]['status'] = 'archived'; $stored[$key]['updated_at'] = current_time('mysql'); $ok = update_option(self::OPTION, $stored, false); }
        elseif ('delete' === $operation && 'draft' === $template['status'] && 0 === self::usage_count($key)) { unset($stored[$key]); $ok = update_option(self::OPTION, $stored, false); }
        if (! $ok) { wp_die($message ?: 'Åtgärden kunde inte genomföras.'); }
        $this->redirect($operation, 'save' === $operation ? $key : '');
    }

    private function redirect(string $notice, string $key = ''): void
    {
        $args = array('page' => self::PAGE, 'ssf_notice' => $notice); if ($key) { $args['template'] = $key; }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php'))); exit;
    }

    private function notice(): void
    {
        $notice = sanitize_key(wp_unslash($_GET['ssf_notice'] ?? '')); if (! $notice) { return; }
        $labels = array('save'=>'Utkastet sparades.','publish'=>'Versionen publicerades.','duplicate'=>'Utkastet skapades.','created'=>'Mallen skapades.','default'=>'Standardmallen ändrades.','archive'=>'Mallen arkiverades.','delete'=>'Utkastet raderades.');
        if (isset($labels[$notice])) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($labels[$notice]) . '</p></div>'; }
    }

    private function status_label(string $status): string
    {
        return array('draft'=>'Utkast','published'=>'Publicerad','archived'=>'Arkiverad')[$status] ?? $status;
    }
}
