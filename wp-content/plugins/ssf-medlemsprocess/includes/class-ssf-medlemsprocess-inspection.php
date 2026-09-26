<?php
/**
 * Shared inspection domain model for real and QA membership inspections.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Medlemsprocess_Inspection
{
    public const POST_TYPE = 'ssf_membership_insp';
    public const TEMPLATE_SLUG = 'membership-vessel-inspection';
    public const TEMPLATE_VERSION = '1.0';
    private const TEMPLATE_OPTION = 'ssf_membership_inspection_templates';
    private const META = '_ssf_membership_inspection';

    public function __construct()
    {
        add_action('init', array($this, 'register_post_type'));
        // Runs on every installed site, not only during plugin activation. This makes
        // the published 1.0 template available after an ordinary code deployment.
        add_action('init', array($this, 'seed_default_template'), 20);
        add_action('admin_menu', array($this, 'register_test_page'), 40);
        add_action('admin_post_ssf_start_test_inspection', array($this, 'start_test'));
        add_action('admin_post_ssf_delete_test_inspection', array($this, 'delete_test'));
        add_action('admin_post_ssf_duplicate_inspection_template', array($this, 'duplicate_template_action'));
        add_action('admin_post_ssf_save_inspection_template', array($this, 'save_template_action'));
        add_action('admin_post_ssf_publish_inspection_template', array($this, 'publish_template_action'));
    }

    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array('name' => 'Inspektionsprotokoll', 'singular_name' => 'Inspektionsprotokoll'),
            'public' => false,
            'show_ui' => false,
            'supports' => array('title'),
            'map_meta_cap' => true,
        ));
    }

    /** Published business template. A new version must be added instead of mutating this definition. */
    public static function official_template(): array
    {
        return array(
            'id' => self::TEMPLATE_SLUG . '@' . self::TEMPLATE_VERSION,
            'slug' => self::TEMPLATE_SLUG,
            'name' => 'Medlemsprövning av fartyg',
            'protocol_title' => 'INSPEKTIONSPROTOKOLL – MEDLEMSPRÖVNING AV FARTYG',
            'version' => self::TEMPLATE_VERSION,
            'type' => 'Fysisk inspektion ombord',
            'status' => 'published',
            'sections' => array(
                array('id' => 'a', 'title' => 'A. Stadgekrav och grundbedömning', 'step' => 1, 'questions' => array(
                    self::question('1', 'Är fartyget ett segelfartyg eller segelfartyg med hjälpmaskin?', array('Ja, segelfartyg', 'Ja, segelfartyg med hjälpmaskin', 'Delvis, rigg/segel saknas men återställningsplan finns', 'Nej', 'Går ej att bedöma'), array('current_rig', 'aux_engine', 'rig_description')),
                    self::question('2', 'Används eller har fartyget tidigare använts som seglande arbetsfartyg?', array('Ja, fraktfartyg', 'Ja, fiskefartyg', 'Ja, annat arbetsfartyg', 'Möjligen, men behöver styrkas', 'Nej', 'Går ej att bedöma'), array('previous_use', 'history')),
                    self::question('3', 'Är fartygets historiska arbetsfunktion styrkt?', array('Ja, stark dokumentation', 'Ja, delvis styrkt', 'Endast muntliga uppgifter', 'Svagt eller motsägelsefullt underlag', 'Nej, inte styrkt'), array('history', 'application_documents')),
                    self::question('4', 'Uppfyller fartyget huvudregeln för storlek (över 12 m på huvuddäck och över 4 m bredd)?', array('Ja, båda måtten är uppfyllda och styrkta', 'Ja, enligt uppgift men dokumentation saknas', 'Nej, ett av måtten saknas', 'Nej, båda måtten saknas', 'Går ej att bedöma'), array('main_deck_length', 'beam')),
                    self::question('5', 'Om storlekskravet inte uppfylls: är fartyget registrerat i svenska fartygsregistret?', array('Ja, registrering styrkt', 'Ja, enligt uppgift men underlag saknas', 'Nej', 'Ej tillämpligt', 'Går ej att bedöma'), array('registration', 'registry_number')),
                )),
                array('id' => 'b', 'title' => 'B. Historisk karaktär och bevarande', 'step' => 2, 'questions' => array(
                    self::question('6', 'Är fartygets traditionella karaktär tydligt igenkännbar?', array('Ja, i hög grad', 'Ja, tillräckligt trots vissa förändringar', 'Delvis, större förändringar behöver beskrivas', 'Svagt, karaktären är svår att läsa', 'Nej')),
                    self::question('7a', 'Stämmer skrovtyp, material och huvudform med fartygets uppgivna historik?', array('Ja, stämmer väl', 'Ja, med rimliga förändringar', 'Delvis, större förändringar behöver beskrivas', 'Nej, avviker tydligt', 'Går ej att bedöma'), array('hull_type', 'material', 'build_year', 'build_place')),
                    self::question('7b', 'Stämmer riggtyp och segelföring med fartygets historik och fartygstyp?', array('Ja, stämmer väl', 'Ja, med rimliga förändringar', 'Rigg saknas men återställningsplan finns', 'Delvis, oklart eller ändrat', 'Nej', 'Går ej att bedöma'), array('original_rig', 'current_rig', 'masts', 'sail_area')),
                    self::question('7c', 'Stämmer däckslayout, luckor, ruffar och arbetsytor med fartygets historiska användning?', array('Ja, i hög grad', 'Ja, tillräckligt trots moderniseringar', 'Delvis, större förändringar finns', 'Nej, däcksmiljön är kraftigt förändrad', 'Går ej att bedöma'), array(), 'Beakta även större överbyggnader och karakteristiska utrustningsdetaljer.'),
                    self::question('8', 'Om fartyget är under restaurering: finns en trovärdig plan för att återföra eller bevara fartyget som segelfartyg?', array('Ja, tydlig plan med mål, tidplan och ansvariga', 'Ja, men planen behöver kompletteras', 'Delvis, ambition finns men underlaget är svagt', 'Nej', 'Ej tillämpligt'), array('restoration_condition', 'restoration_goal', 'preservation_plan', 'restoration_timeline')),
                    self::question('9', 'Finns det några uppenbara omständigheter som talar emot medlemskap?', array('Nej, inga uppenbara hinder', 'Ja, oklar historik', 'Ja, oklar identitet / registerstatus', 'Ja, fartyget verkar inte längre vara ett segelfartyg', 'Ja, restaureringsprojektet verkar orealistiskt', 'Ja, annat')),
                )),
                array('id' => 'c', 'title' => 'C. Samlad bedömning', 'step' => 3, 'questions' => array(
                    self::question('10', 'Inspektörens rekommendation till styrelsen', array('Godkänn som medlemsfartyg', 'Godkänn efter mindre komplettering', 'Skicka till särskild prövning', 'Avvakta – underlaget är för svagt', 'Avslå som medlemsfartyg', 'Föreslå stödmedlemskap i stället')),
                )),
            ),
            'overview_photos' => array('side' => 'Fartyget från sidan', 'deck' => 'Däck', 'rig' => 'Rigg', 'fore_aft' => 'För/akter eller annan översiktsbild'),
            'disclaimer' => 'Detta protokoll är en intern medlemsprövning enligt SSF:s stadgar. Det utgör inte myndighetsbesiktning, säkerhetsbesiktning eller sjövärdighetsbedömning.',
        );
    }

    private static function question(string $id, string $text, array $options, array $context = array(), string $help = ''): array
    {
        $comment_required = array();
        foreach ($options as $option) {
            if ('Ja, annat' === $option || preg_match('/^(Delvis|Nej|Möjligen|Svagt|Endast|Avvakta|Skicka|Godkänn efter)/u', $option) || false !== mb_stripos($option, 'saknas') || false !== mb_stripos($option, 'oklar')) {
                $comment_required[] = $option;
            }
        }
        return array('id' => $id, 'text' => $text, 'options' => $options, 'context' => $context, 'help' => $help, 'required' => true, 'comment_required_for' => $comment_required, 'photo_allowed' => true);
    }

    public static function questions(array $template = array()): array
    {
        $template = $template ?: self::official_template();
        $questions = array();
        foreach ((array) ($template['sections'] ?? array()) as $section) {
            foreach ((array) ($section['questions'] ?? array()) as $question) {
                $questions[$question['id']] = $question + array('section' => $section['title'], 'step' => $section['step']);
            }
        }
        return $questions;
    }

    /** Template registry. Published entries are immutable; inspections copy the full selected version. */
    public static function seed_default_template(): bool
    {
        $stored = (array) get_option(self::TEMPLATE_OPTION, array());
        $key = self::TEMPLATE_SLUG . '@' . self::TEMPLATE_VERSION;

        foreach ($stored as $stored_key => $template) {
            if (! is_array($template)) {
                continue;
            }
            $is_default = self::TEMPLATE_SLUG === ($template['slug'] ?? '')
                || (self::TEMPLATE_VERSION === (string) ($template['version'] ?? '') && 'Medlemsprövning av fartyg' === ($template['name'] ?? ''));
            if (! $is_default) {
                continue;
            }
            // Upgrade an early 1.0 representation in place without creating a second template.
            $normalized = array_merge(self::official_template(), $template, array(
                'id' => $key,
                'slug' => self::TEMPLATE_SLUG,
                'version' => self::TEMPLATE_VERSION,
                'status' => 'published',
            ));
            if ($normalized !== $template || $stored_key !== $key) {
                unset($stored[$stored_key]);
                $stored[$key] = $normalized;
                return update_option(self::TEMPLATE_OPTION, $stored, false);
            }
            return false;
        }

        $stored[$key] = self::official_template();
        return update_option(self::TEMPLATE_OPTION, $stored, false);
    }

    public static function templates(): array
    {
        $templates = array();
        foreach ((array) get_option(self::TEMPLATE_OPTION, array()) as $key => $template) {
            if (! is_array($template) || empty($template['slug']) || empty($template['version'])) {
                continue;
            }
            $templates[(string) $key] = $template;
        }
        uasort($templates, static function (array $left, array $right): int { return version_compare((string) $left['version'], (string) $right['version']); });
        return $templates;
    }

    public static function published_template(): array
    {
        $published = array_filter(self::templates(), static function (array $template): bool { return self::TEMPLATE_SLUG === ($template['slug'] ?? '') && 'published' === ($template['status'] ?? ''); });
        return $published ? end($published) : self::official_template();
    }

    public static function duplicate_template(string $source_version, string $new_version): bool
    {
        if (! current_user_can('ssf_manage_application_settings') && ! current_user_can('manage_options')) { return false; }
        $templates = self::templates();
        $source_key = self::TEMPLATE_SLUG . '@' . $source_version;
        $new_key = self::TEMPLATE_SLUG . '@' . $new_version;
        if (! isset($templates[$source_key]) || isset($templates[$new_key]) || ! preg_match('/^\d+\.\d+$/', $new_version)) { return false; }
        $copy = $templates[$source_key]; $copy['id'] = $new_key; $copy['version'] = $new_version; $copy['status'] = 'draft';
        $stored = (array) get_option(self::TEMPLATE_OPTION, array()); $stored[$new_key] = $copy;
        return update_option(self::TEMPLATE_OPTION, $stored, false);
    }

    public static function save_draft_template(string $version, array $template): bool
    {
        if (! current_user_can('ssf_manage_application_settings') && ! current_user_can('manage_options')) { return false; }
        $key = self::TEMPLATE_SLUG . '@' . $version;
        $stored = (array) get_option(self::TEMPLATE_OPTION, array());
        if ('draft' !== ($stored[$key]['status'] ?? '') || (string) ($template['version'] ?? '') !== $version || empty($template['sections'])) { return false; }
        $clean = $stored[$key];
        $clean['name'] = sanitize_text_field((string) ($template['name'] ?? $clean['name']));
        $clean['type'] = sanitize_text_field((string) ($template['type'] ?? $clean['type']));
        foreach ((array) $template['sections'] as $section_index => $section) {
            if (! isset($clean['sections'][$section_index])) { continue; }
            $clean['sections'][$section_index]['title'] = sanitize_text_field((string) ($section['title'] ?? $clean['sections'][$section_index]['title']));
            foreach ((array) ($section['questions'] ?? array()) as $question_index => $question) {
                if (! isset($clean['sections'][$section_index]['questions'][$question_index])) { continue; }
                $target =& $clean['sections'][$section_index]['questions'][$question_index];
                if (($question['id'] ?? '') !== $target['id']) { return false; }
                $target['text'] = sanitize_text_field((string) ($question['text'] ?? $target['text']));
                $options = array_values(array_filter(array_map('sanitize_text_field', (array) ($question['options'] ?? array()))));
                if ($options) { $target['options'] = $options; }
                $target['help'] = sanitize_textarea_field((string) ($question['help'] ?? $target['help']));
                $target['comment_required_for'] = array_values(array_intersect((array) ($question['comment_required_for'] ?? array()), $target['options']));
                unset($target);
            }
        }
        $stored[$key] = $clean;
        $current = (array) get_option(self::TEMPLATE_OPTION, array());
        return $current === $stored || update_option(self::TEMPLATE_OPTION, $stored, false);
    }

    public static function publish_template(string $version): bool
    {
        if (! current_user_can('ssf_manage_application_settings') && ! current_user_can('manage_options')) { return false; }
        $key = self::TEMPLATE_SLUG . '@' . $version;
        $stored = (array) get_option(self::TEMPLATE_OPTION, array());
        if ('draft' !== ($stored[$key]['status'] ?? '')) { return false; }
        $stored[$key]['status'] = 'published';
        return update_option(self::TEMPLATE_OPTION, $stored, false);
    }

    public static function ensure_real(int $application_id, int $lead_id, int $co_id = 0): int
    {
        $existing = absint(get_post_meta($application_id, '_ssf_active_inspection_id', true));
        if ($existing && self::record($existing) && ! self::is_test($existing)) {
            self::update_inspectors($existing, $lead_id, $co_id);
            return $existing;
        }
        if (! in_array(SSF_Medlemsprocess_Application::membership_status($application_id), array('aspirant', 'follow_up'), true) || ! $lead_id) {
            return 0;
        }
        $id = self::create($application_id, $lead_id, $co_id, false);
        if ($id) {
            update_post_meta($application_id, '_ssf_active_inspection_id', $id);
        }
        return $id;
    }

    public static function create_test(int $application_id, int $lead_id, int $co_id = 0): int
    {
        return self::create($application_id, $lead_id, $co_id, true);
    }

    private static function create(int $application_id, int $lead_id, int $co_id, bool $is_test): int
    {
        if (SSF_Medlemsprocess_Application::POST_TYPE !== get_post_type($application_id) || ! get_userdata($lead_id)) {
            return 0;
        }
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $id = wp_insert_post(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => ($is_test ? 'TESTINSPEKTION – ' : 'Inspektion – ') . ($data['ship_name'] ?? get_the_title($application_id)),
        ), true);
        if (is_wp_error($id)) {
            return 0;
        }
        $now = current_time('mysql');
        $record = array(
            'application_id' => $application_id,
            'source_application_id' => $application_id,
            'is_test' => $is_test,
            'template' => self::published_template(),
            'template_slug' => self::published_template()['slug'],
            'template_version' => self::published_template()['version'],
            'application_snapshot' => self::application_snapshot($application_id),
            'status' => 'draft',
            'lead_inspector_user_id' => $lead_id,
            'co_inspector_user_id' => $co_id && $co_id !== $lead_id ? $co_id : 0,
            'details' => array('date' => '', 'place' => '', 'attendees' => ''),
            'answers' => array(),
            'photos' => array(),
            'confirmations' => array(),
            'summary' => '',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'completed_at' => '',
            'final_snapshot' => array(),
            'audit' => array(array('type' => 'inspection_created', 'user_id' => get_current_user_id(), 'created_at' => $now)),
        );
        update_post_meta((int) $id, self::META, $record);
        if (! $is_test) {
            SSF_Medlemsprocess_Application::add_history($application_id, 'inspection_created', 'Gemensamt inspektionsprotokoll skapades.', false, array('inspection_id' => (int) $id));
        }
        return (int) $id;
    }

    public static function record(int $inspection_id): array
    {
        if (self::POST_TYPE !== get_post_type($inspection_id)) {
            return array();
        }
        return (array) get_post_meta($inspection_id, self::META, true);
    }

    public static function save(int $inspection_id, array $record): bool
    {
        $record['updated_at'] = current_time('mysql');
        return false !== update_post_meta($inspection_id, self::META, $record);
    }

    public static function is_test(int $inspection_id): bool
    {
        return ! empty(self::record($inspection_id)['is_test']);
    }

    public static function can_read(int $inspection_id, int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        $record = self::record($inspection_id);
        if (! $record || ! self::active_user($user_id)) {
            return false;
        }
        if (user_can($user_id, 'manage_options') || user_can($user_id, 'ssf_view_applications')) {
            return true;
        }
        return user_can($user_id, 'ssf_view_assigned_applications') && in_array($user_id, array((int) $record['lead_inspector_user_id'], (int) $record['co_inspector_user_id']), true);
    }

    public static function can_edit(int $inspection_id, int $user_id = 0): bool
    {
        $record = self::record($inspection_id);
        if (! self::can_read($inspection_id, $user_id) || 'completed' === ($record['status'] ?? '')) { return false; }
        return ! empty($record['is_test']) || in_array(SSF_Medlemsprocess_Application::inspection_status((int) $record['application_id']), array('booked', 'in_progress'), true);
    }

    private static function active_user(int $user_id): bool
    {
        return $user_id > 0 && (! class_exists('SSF_Access_Control') || SSF_Access_Control::is_active($user_id));
    }

    public static function update_inspectors(int $inspection_id, int $lead_id, int $co_id): void
    {
        $record = self::record($inspection_id);
        if (! $record || 'completed' === ($record['status'] ?? '')) {
            return;
        }
        $before = array((int) $record['lead_inspector_user_id'], (int) $record['co_inspector_user_id']);
        $record['lead_inspector_user_id'] = $lead_id;
        $record['co_inspector_user_id'] = $co_id && $co_id !== $lead_id ? $co_id : 0;
        if ($before !== array($lead_id, (int) $record['co_inspector_user_id'])) {
            self::touch($record, 'inspector_assigned');
            self::save($inspection_id, $record);
        }
    }

    public static function save_answer(int $inspection_id, string $question_id, string $selected, string $comment, int $user_id): array
    {
        $record = self::record($inspection_id);
        $questions = self::questions((array) ($record['template'] ?? array()));
        if (! self::can_edit($inspection_id, $user_id) || ! isset($questions[$question_id]) || ! in_array($selected, $questions[$question_id]['options'], true)) {
            return array('ok' => false, 'message' => 'Svaret kunde inte sparas.');
        }
        $comment = sanitize_textarea_field($comment);
        $current = (array) ($record['answers'][$question_id] ?? array());
        if (($current['selected_option'] ?? '') === $selected && ($current['comment'] ?? '') === $comment) {
            return array('ok' => true, 'revision' => $record['revision'], 'progress' => self::progress($record));
        }
        $record['answers'][$question_id] = array('selected_option' => $selected, 'comment' => $comment, 'updated_by' => $user_id, 'updated_at' => current_time('mysql'));
        self::mark_started($record);
        self::touch($record, 'answer_updated', array('question_id' => $question_id));
        self::save($inspection_id, $record);
        return array('ok' => true, 'revision' => $record['revision'], 'progress' => self::progress($record));
    }

    public static function save_details(int $inspection_id, array $details, string $summary, int $user_id): array
    {
        $record = self::record($inspection_id);
        if (! self::can_edit($inspection_id, $user_id)) {
            return array('ok' => false, 'message' => 'Uppgifterna kunde inte sparas.');
        }
        $clean = array();
        foreach (array('date', 'place', 'attendees') as $key) { $clean[$key] = sanitize_text_field((string) ($details[$key] ?? '')); }
        $summary = sanitize_textarea_field($summary);
        if ((array) ($record['details'] ?? array()) === $clean && ($record['summary'] ?? '') === $summary) {
            return array('ok' => true, 'revision' => $record['revision']);
        }
        $record['details'] = $clean;
        $record['summary'] = $summary;
        self::mark_started($record);
        self::touch($record, 'details_updated');
        self::save($inspection_id, $record);
        return array('ok' => true, 'revision' => $record['revision']);
    }

    private static function touch(array &$record, string $event, array $details = array()): void
    {
        $record['revision'] = (int) ($record['revision'] ?? 0) + 1;
        if (! empty($record['confirmations']) || in_array($record['status'] ?? '', array('ready_confirmation', 'ready_finalization'), true)) {
            $record['confirmations'] = array();
            $record['status'] = 'draft';
            $record['audit'][] = array('type' => 'confirmation_invalidated', 'user_id' => get_current_user_id(), 'created_at' => current_time('mysql'));
        }
        $record['audit'][] = array('type' => $event, 'user_id' => get_current_user_id(), 'details' => $details, 'created_at' => current_time('mysql'));
    }

    private static function mark_started(array &$record): void
    {
        foreach ((array) ($record['audit'] ?? array()) as $event) {
            if ('inspection_started' === ($event['type'] ?? '')) { return; }
        }
        $record['audit'][] = array('type' => 'inspection_started', 'user_id' => get_current_user_id(), 'created_at' => current_time('mysql'));
        if (empty($record['is_test']) && 'booked' === SSF_Medlemsprocess_Application::inspection_status((int) $record['application_id'])) {
            if (SSF_Medlemsprocess_Application::set_inspection_status((int) $record['application_id'], 'in_progress', 'inspector')) {
                SSF_Medlemsprocess_Plugin::instance()->sharepoint->push_status((int) $record['application_id']);
            }
        }
    }

    public static function progress(array $record): array
    {
        $questions = self::questions((array) ($record['template'] ?? array()));
        $complete = 0;
        foreach ($questions as $id => $question) {
            $complete += ! empty($record['answers'][$id]['selected_option']) ? 1 : 0;
        }
        return array('complete' => $complete, 'total' => count($questions), 'percent' => $questions ? (int) round(100 * $complete / count($questions)) : 0);
    }

    public static function preflight(array $record): array
    {
        $problems = array();
        foreach (self::questions((array) ($record['template'] ?? array())) as $id => $question) {
            $answer = (array) ($record['answers'][$id] ?? array());
            if (empty($answer['selected_option'])) {
                $problems[] = 'Svar saknas för fråga ' . strtoupper($id) . '.';
            } elseif (in_array($answer['selected_option'], $question['comment_required_for'], true) && '' === trim((string) ($answer['comment'] ?? ''))) {
                $problems[] = 'Kommentar krävs för fråga ' . strtoupper($id) . '.';
            }
        }
        foreach (array('date' => 'Datum', 'place' => 'Plats', 'attendees' => 'Närvarande') as $key => $label) {
            if ('' === trim((string) ($record['details'][$key] ?? ''))) {
                $problems[] = $label . ' saknas.';
            }
        }
        if ('' === trim((string) ($record['summary'] ?? ''))) {
            $problems[] = 'Kort sammanfattning/motivering saknas.';
        }
        return $problems;
    }

    public static function ready(int $inspection_id, int $user_id): array
    {
        $record = self::record($inspection_id);
        if (! self::can_edit($inspection_id, $user_id) || $user_id !== (int) ($record['lead_inspector_user_id'] ?? 0)) {
            return array('ok' => false, 'message' => 'Endast huvudinspektören kan lämna rapporten för bekräftelse.');
        }
        $problems = self::preflight($record);
        if ($problems) {
            return array('ok' => false, 'message' => implode(' ', $problems), 'problems' => $problems);
        }
        $record['status'] = ! empty($record['co_inspector_user_id']) ? 'ready_confirmation' : 'ready_finalization';
        $record['audit'][] = array('type' => 'report_ready_for_confirmation', 'user_id' => $user_id, 'created_at' => current_time('mysql'));
        self::save($inspection_id, $record);
        return array('ok' => true, 'status' => $record['status']);
    }

    public static function confirm(int $inspection_id, int $user_id): array
    {
        $record = self::record($inspection_id);
        if ('ready_confirmation' !== ($record['status'] ?? '') || $user_id !== (int) ($record['co_inspector_user_id'] ?? 0) || ! self::can_edit($inspection_id, $user_id)) {
            return array('ok' => false, 'message' => 'Bekräftelsen kunde inte registreras.');
        }
        $record['confirmations'][$user_id] = array('user_id' => $user_id, 'confirmed_at' => current_time('mysql'), 'report_revision' => (int) $record['revision'], 'report_hash' => self::report_hash($record));
        $record['status'] = 'ready_finalization';
        $record['audit'][] = array('type' => 'co_inspector_confirmed', 'user_id' => $user_id, 'created_at' => current_time('mysql'));
        self::save($inspection_id, $record);
        return array('ok' => true);
    }

    public static function finalize(int $inspection_id, int $user_id): array
    {
        $record = self::record($inspection_id);
        if ('ready_finalization' !== ($record['status'] ?? '') || $user_id !== (int) ($record['lead_inspector_user_id'] ?? 0) || self::preflight($record)) {
            return array('ok' => false, 'message' => 'Protokollet kan inte färdigställas ännu.');
        }
        if (! empty($record['co_inspector_user_id'])) {
            $confirmation = (array) ($record['confirmations'][$record['co_inspector_user_id']] ?? array());
            if (empty($confirmation) || ! hash_equals((string) ($confirmation['report_hash'] ?? ''), self::report_hash($record))) {
                return array('ok' => false, 'message' => 'Medinspektören måste bekräfta den aktuella rapportversionen.');
            }
        }
        $record['status'] = 'completed';
        $record['completed_at'] = current_time('mysql');
        $record['final_snapshot'] = self::snapshot($record);
        $record['audit'][] = array('type' => 'inspection_finalized', 'user_id' => $user_id, 'created_at' => $record['completed_at']);
        self::save($inspection_id, $record);
        if (empty($record['is_test'])) {
            $application_id = (int) $record['application_id'];
            SSF_Medlemsprocess_Application::add_history($application_id, 'inspection_report', 'Inspektionsprotokollet färdigställdes.', true, array('inspection_id' => $inspection_id));
            if (SSF_Medlemsprocess_Application::set_inspection_status($application_id, 'completed', 'inspector')) {
                SSF_Medlemsprocess_Plugin::instance()->sharepoint->push_status($application_id);
                SSF_Medlemsprocess_Plugin::instance()->emails->send_inspection_complete($application_id);
            }
        }
        return array('ok' => true);
    }

    private static function report_hash(array $record): string
    {
        return hash('sha256', wp_json_encode(array($record['template'], $record['details'], $record['answers'], $record['photos'], $record['summary'], $record['revision'])));
    }

    private static function snapshot(array $record): array
    {
        $inspectors = array();
        foreach (array((int) $record['lead_inspector_user_id'], (int) $record['co_inspector_user_id']) as $user_id) {
            $user = $user_id ? get_userdata($user_id) : false;
            if ($user) {
                $inspectors[] = array('user_id' => $user_id, 'name' => $user->display_name);
            }
        }
        return array(
            'template' => $record['template'], 'application' => $record['application_snapshot'], 'details' => $record['details'],
            'answers' => $record['answers'], 'photos' => $record['photos'], 'inspectors' => $inspectors,
            'summary' => $record['summary'], 'confirmations' => $record['confirmations'], 'completed_at' => $record['completed_at'],
        );
    }

    public static function application_snapshot(int $application_id): array
    {
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $raw = (array) get_post_meta($application_id, '_ssf_application_vessel_snapshot', true);
        $aliases = array(
            'ship_name' => array('ship_name', 'post_title'), 'applicant_name' => array('applicant_name'), 'applicant_organization' => array('applicant_organization'),
            'registry_number' => array('ship_registry_number', '_ssf_registry_number'), 'registration' => array('ship_register', '_ssf_registration_type', '_ssf_registered_confirmation'),
            'current_rig' => array('ship_rig', '_ssf_rig'), 'original_rig' => array('_ssf_original_rig'), 'rig_description' => array('_ssf_rig_description'),
            'aux_engine' => array('ship_engine', '_ssf_has_aux_engine', '_ssf_engine'), 'previous_use' => array('_ssf_previous_use', 'ship_current_use'),
            'history' => array('ship_history', '_ssf_history'), 'main_deck_length' => array('ship_length', '_ssf_main_deck_length'), 'beam' => array('ship_beam', '_ssf_beam'),
            'hull_type' => array('_ssf_hull_type', 'ship_type'), 'material' => array('_ssf_material'), 'build_year' => array('ship_build_year', '_ssf_build_year'),
            'build_place' => array('_ssf_build_place', 'ship_shipyard', '_ssf_shipyard'), 'masts' => array('_ssf_masts'), 'sail_area' => array('_ssf_sail_area'),
            'restoration_condition' => array('_ssf_restoration_condition'), 'restoration_goal' => array('_ssf_restoration_goal'),
            'preservation_plan' => array('_ssf_preservation_plan'), 'restoration_timeline' => array('_ssf_restoration_timeline'),
        );
        $source = array_merge($raw, $data);
        $snapshot = array('application_id' => $application_id, 'number' => (string) get_post_meta($application_id, '_ssf_application_number', true));
        foreach ($aliases as $target => $keys) {
            $snapshot[$target] = '';
            foreach ($keys as $key) {
                if (isset($source[$key]) && '' !== trim((string) $source[$key])) {
                    $snapshot[$target] = is_array($source[$key]) ? implode(', ', $source[$key]) : (string) $source[$key];
                    break;
                }
            }
        }
        $snapshot['application_documents'] = array_map('intval', (array) get_post_meta($application_id, '_ssf_application_files', true));
        return $snapshot;
    }

    public static function context(array $record, array $question): array
    {
        $labels = array('current_rig' => 'Nuvarande rigg', 'aux_engine' => 'Hjälpmotor', 'rig_description' => 'Beskrivning av rigg', 'previous_use' => 'Tidigare användning', 'history' => 'Historik', 'main_deck_length' => 'Längd i huvuddäck', 'beam' => 'Bredd', 'registration' => 'Svenskt register', 'registry_number' => 'Signal / reg.nr', 'hull_type' => 'Skrovtyp', 'material' => 'Material', 'build_year' => 'Byggår', 'build_place' => 'Byggplats/varv', 'original_rig' => 'Ursprunglig rigg', 'masts' => 'Antal master', 'sail_area' => 'Segelyta', 'restoration_condition' => 'Nuvarande skick', 'restoration_goal' => 'Restaureringsmål', 'preservation_plan' => 'Bevarandeplan', 'restoration_timeline' => 'Tidsplan', 'application_documents' => 'Dokument/källor');
        $result = array();
        foreach ((array) ($question['context'] ?? array()) as $key) {
            $value = $record['application_snapshot'][$key] ?? '';
            if (is_array($value)) {
                $value = $value ? count($value) . ' bifogade filer' : '';
            }
            if ('' !== trim((string) $value)) {
                $result[] = array('label' => $labels[$key] ?? $key, 'value' => (string) $value);
            }
        }
        return $result;
    }

    public static function deviations(array $record): array
    {
        $result = array();
        foreach (self::questions((array) ($record['template'] ?? array())) as $id => $question) {
            $answer = (array) ($record['answers'][$id] ?? array());
            if (! empty($answer['comment']) || in_array($answer['selected_option'] ?? '', $question['comment_required_for'], true)) {
                $result[] = array('id' => $id, 'question' => $question['text'], 'answer' => $answer['selected_option'] ?? '', 'comment' => $answer['comment'] ?? '');
            }
        }
        return $result;
    }

    public static function add_photo(int $inspection_id, string $question_id, string $caption, string $operation_id, array $file, int $user_id): array
    {
        $record = self::record($inspection_id);
        $questions = self::questions((array) ($record['template'] ?? array()));
        $overview = array_keys((array) ($record['template']['overview_photos'] ?? array()));
        if (! self::can_edit($inspection_id, $user_id) || (! isset($questions[$question_id]) && ! in_array($question_id, $overview, true))) {
            return array('ok' => false, 'message' => 'Bilden hör inte till en giltig kontrollpunkt.');
        }
        if (! wp_is_uuid($operation_id)) {
            return array('ok' => false, 'message' => 'Ogiltigt uppladdnings-ID.');
        }
        foreach ((array) ($record['photos'] ?? array()) as $photo) {
            if (($photo['operation_id'] ?? '') === $operation_id) {
                return array('ok' => true, 'photo' => $photo);
            }
        }
        if (empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name']) || UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) || (int) ($file['size'] ?? 0) > 12 * MB_IN_BYTES) {
            return array('ok' => false, 'message' => 'Bilden saknas eller är för stor.');
        }
        $image = @getimagesize($file['tmp_name']);
        if (! $image || ! in_array($image['mime'], array('image/jpeg', 'image/png', 'image/webp'), true)) {
            return array('ok' => false, 'message' => 'Endast JPG, PNG och WebP är tillåtna.');
        }
        $editor = wp_get_image_editor($file['tmp_name']);
        if (is_wp_error($editor)) {
            return array('ok' => false, 'message' => 'Bilden kunde inte bearbetas.');
        }
        $editor->resize(2000, 2000, false);
        $directory = self::private_photo_dir()['base'] . '/' . $inspection_id;
        if (! wp_mkdir_p($directory)) {
            return array('ok' => false, 'message' => 'Bildlagringen kunde inte skapas.');
        }
        if (! file_exists(self::private_photo_dir()['base'] . '/index.php')) {
            @file_put_contents(self::private_photo_dir()['base'] . '/index.php', "<?php\nhttp_response_code(403);\nexit;\n");
            @file_put_contents(self::private_photo_dir()['base'] . '/.htaccess', "Deny from all\n");
        }
        $photo_id = wp_generate_uuid4();
        $filename = $photo_id . '.jpg';
        $saved = $editor->save($directory . '/' . $filename, 'image/jpeg');
        if (is_wp_error($saved)) {
            return array('ok' => false, 'message' => 'Bilden kunde inte sparas.');
        }
        $photo = array(
            'id' => $photo_id, 'inspection_id' => $inspection_id, 'question_id' => $question_id,
            'path' => $inspection_id . '/' . $filename, 'caption' => sanitize_text_field($caption),
            'created_by' => $user_id, 'created_at' => current_time('mysql'), 'visibility' => 'protocol',
            'operation_id' => $operation_id, 'mime_type' => 'image/jpeg',
        );
        $record['photos'][] = $photo;
        self::mark_started($record);
        self::touch($record, 'photo_uploaded', array('photo_id' => $photo_id, 'question_id' => $question_id));
        self::save($inspection_id, $record);
        return array('ok' => true, 'photo' => $photo, 'revision' => $record['revision']);
    }

    public static function update_photo(int $inspection_id, string $photo_id, string $caption, bool $delete, int $user_id): array
    {
        $record = self::record($inspection_id);
        if (! self::can_edit($inspection_id, $user_id)) {
            return array('ok' => false, 'message' => 'Bilden kan inte ändras.');
        }
        foreach ((array) ($record['photos'] ?? array()) as $index => $photo) {
            if (! hash_equals((string) ($photo['id'] ?? ''), $photo_id)) {
                continue;
            }
            if ($delete) {
                self::delete_photo_file((string) ($photo['path'] ?? ''));
                array_splice($record['photos'], $index, 1);
                self::touch($record, 'photo_deleted', array('photo_id' => $photo_id));
            } else {
                $record['photos'][$index]['caption'] = sanitize_text_field($caption);
                self::touch($record, 'photo_caption_updated', array('photo_id' => $photo_id));
            }
            self::save($inspection_id, $record);
            return array('ok' => true, 'revision' => $record['revision']);
        }
        return array('ok' => false, 'message' => 'Bilden hittades inte.');
    }

    public static function photo(int $inspection_id, string $photo_id, bool $final_only = false): array
    {
        $record = self::record($inspection_id);
        $photos = $final_only ? (array) ($record['final_snapshot']['photos'] ?? array()) : (array) ($record['photos'] ?? array());
        foreach ($photos as $photo) {
            if (hash_equals((string) ($photo['id'] ?? ''), $photo_id)) {
                return $photo;
            }
        }
        return array();
    }

    public static function inspection_for_application(int $application_id): int
    {
        $id = absint(get_post_meta($application_id, '_ssf_active_inspection_id', true));
        return $id && self::record($id) ? $id : 0;
    }

    public function register_test_page(): void
    {
        $parent = class_exists('SSF_Admin_Navigation') ? SSF_Admin_Navigation::MEMBERSHIP : 'edit.php?post_type=' . SSF_Medlemsprocess_Application::POST_TYPE;
        add_submenu_page($parent, 'Inspektionsmall', 'Inspektionsmall', 'ssf_manage_application_settings', 'ssf-inspection-template', array($this, 'render_template_page'), 69);
        if ('production' === wp_get_environment_type()) { return; }
        add_submenu_page($parent, 'Testa inspektion', 'Testa inspektion', 'manage_options', 'ssf-test-inspection', array($this, 'render_test_page'), 70);
    }

    public function render_template_page(): void
    {
        if (! current_user_can('ssf_manage_application_settings') && ! current_user_can('manage_options')) { wp_die('Du saknar behörighet.'); }
        echo '<div class="wrap"><h1>Inspektionsmall</h1><p>Publicerade versioner är låsta. Duplicera en version för att göra en ändring.</p>';
        foreach (self::templates() as $template_key => $template) {
            $version = (string) $template['version'];
            $draft = 'draft' === ($template['status'] ?? '');
            echo '<section id="template-' . esc_attr($template['slug'] . '-' . $version) . '" class="card" style="max-width:1000px"><h2>' . esc_html($template['name'] . ' · version ' . $version) . '</h2><p><code>' . esc_html($template['slug']) . '</code> · <strong>' . esc_html($draft ? 'Utkast' : 'Publicerad') . '</strong> · ' . esc_html($template['type']) . '</p><p><a class="button button-secondary" href="#template-' . esc_attr($template['slug'] . '-' . $version) . '">Visa mall</a></p>';
            if ($draft) { echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_save_inspection_template"><input type="hidden" name="version" value="' . esc_attr($version) . '">'; wp_nonce_field('ssf_save_inspection_template_' . $version); }
            foreach ($template['sections'] as $section_index => $section) {
                echo '<h3>' . esc_html($section['title']) . '</h3>';
                foreach ($section['questions'] as $question_index => $question) {
                    echo '<div style="padding:12px 0;border-top:1px solid #ccd0d4"><strong>' . esc_html(strtoupper($question['id'])) . '.</strong> ';
                    if ($draft) {
                        $base = 'template[sections][' . $section_index . '][questions][' . $question_index . ']';
                        echo '<input type="hidden" name="' . esc_attr($base . '[id]') . '" value="' . esc_attr($question['id']) . '"><input class="large-text" name="' . esc_attr($base . '[text]') . '" value="' . esc_attr($question['text']) . '"><label>Svarsalternativ, ett per rad<textarea class="large-text" rows="' . esc_attr((string) count($question['options'])) . '" name="' . esc_attr($base . '[options_text]') . '">' . esc_textarea(implode("\n", $question['options'])) . '</textarea></label><label>Hjälptext<textarea class="large-text" rows="2" name="' . esc_attr($base . '[help]') . '">' . esc_textarea($question['help']) . '</textarea></label>';
                    } else {
                        echo esc_html($question['text']) . '<ul><li>' . implode('</li><li>', array_map('esc_html', $question['options'])) . '</li></ul>';
                    }
                    echo '</div>';
                }
            }
            if ($draft) {
                echo '<p><button class="button button-primary">Spara utkast</button></p></form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_publish_inspection_template"><input type="hidden" name="version" value="' . esc_attr($version) . '">'; wp_nonce_field('ssf_publish_inspection_template_' . $version); echo '<button class="button">Publicera version ' . esc_html($version) . '</button></form>';
            } else {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_duplicate_inspection_template"><input type="hidden" name="source_version" value="' . esc_attr($version) . '">'; wp_nonce_field('ssf_duplicate_inspection_template_' . $version); echo '<label>Ny version <input name="new_version" placeholder="1.1" required pattern="[0-9]+\.[0-9]+"></label> <button class="button">Duplicera till utkast</button></form>';
            }
            echo '</section>';
        }
        echo '</div>';
    }

    public function duplicate_template_action(): void
    {
        $source = sanitize_text_field(wp_unslash($_POST['source_version'] ?? '')); $new = sanitize_text_field(wp_unslash($_POST['new_version'] ?? ''));
        if (! check_admin_referer('ssf_duplicate_inspection_template_' . $source) || ! self::duplicate_template($source, $new)) { wp_die('Mallversionen kunde inte dupliceras.'); }
        wp_safe_redirect(admin_url('admin.php?page=ssf-inspection-template')); exit;
    }

    public function save_template_action(): void
    {
        $version = sanitize_text_field(wp_unslash($_POST['version'] ?? '')); $submitted = (array) wp_unslash($_POST['template'] ?? array());
        if (! check_admin_referer('ssf_save_inspection_template_' . $version)) { wp_die('Ogiltig säkerhetskontroll.'); }
        $templates = self::templates(); $template = $templates[self::TEMPLATE_SLUG . '@' . $version] ?? array();
        foreach ((array) ($submitted['sections'] ?? array()) as $section_index => $section) {
            foreach ((array) ($section['questions'] ?? array()) as $question_index => $question) {
                if (! isset($template['sections'][$section_index]['questions'][$question_index])) { continue; }
                $template['sections'][$section_index]['questions'][$question_index]['id'] = sanitize_key((string) ($question['id'] ?? ''));
                $template['sections'][$section_index]['questions'][$question_index]['text'] = sanitize_text_field((string) ($question['text'] ?? ''));
                $template['sections'][$section_index]['questions'][$question_index]['options'] = preg_split('/\r\n|\r|\n/', (string) ($question['options_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                $template['sections'][$section_index]['questions'][$question_index]['help'] = sanitize_textarea_field((string) ($question['help'] ?? ''));
            }
        }
        if (! self::save_draft_template($version, $template)) { wp_die('Mallutkastet kunde inte sparas.'); }
        wp_safe_redirect(admin_url('admin.php?page=ssf-inspection-template&updated=1')); exit;
    }

    public function publish_template_action(): void
    {
        $version = sanitize_text_field(wp_unslash($_POST['version'] ?? ''));
        if (! check_admin_referer('ssf_publish_inspection_template_' . $version) || ! self::publish_template($version)) { wp_die('Mallversionen kunde inte publiceras.'); }
        wp_safe_redirect(admin_url('admin.php?page=ssf-inspection-template&published=1')); exit;
    }

    public function render_test_page(): void
    {
        if (! current_user_can('manage_options') || 'production' === wp_get_environment_type()) {
            wp_die('Du saknar behörighet.');
        }
        $applications = get_posts(array('post_type' => SSF_Medlemsprocess_Application::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 200, 'orderby' => 'modified', 'order' => 'DESC'));
        $users = get_users(array('orderby' => 'display_name'));
        echo '<div class="wrap"><h1>Testa inspektion</h1><p>Skapar isolerad testdata. Ansökan och det verkliga medlemsärendet ändras inte.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ssf_start_test_inspection">';
        wp_nonce_field('ssf_start_test_inspection');
        echo '<table class="form-table"><tr><th><label for="ssf-test-app">Ansökan</label></th><td><select id="ssf-test-app" name="application_id" required><option value="">Välj ansökan</option>';
        foreach ($applications as $application) {
            $data = SSF_Medlemsprocess_Application::data($application->ID);
            echo '<option value="' . esc_attr((string) $application->ID) . '">' . esc_html((string) get_post_meta($application->ID, '_ssf_application_number', true) . ' – ' . ($data['ship_name'] ?? $application->post_title)) . '</option>';
        }
        echo '</select></td></tr><tr><th>Testinspektör</th><td><select name="lead_id" required>';
        foreach ($users as $user) {
            if (user_can($user->ID, 'ssf_view_assigned_applications') || user_can($user->ID, 'manage_options')) {
                echo '<option value="' . esc_attr((string) $user->ID) . '" ' . selected(get_current_user_id(), $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
            }
        }
        echo '</select></td></tr><tr><th>Medinspektör</th><td><select name="co_id"><option value="0">Ingen</option>';
        foreach ($users as $user) {
            if (user_can($user->ID, 'ssf_view_assigned_applications') || user_can($user->ID, 'manage_options')) {
                echo '<option value="' . esc_attr((string) $user->ID) . '">' . esc_html($user->display_name) . '</option>';
            }
        }
        $published = self::published_template();
        echo '</select></td></tr><tr><th>Mall</th><td>' . esc_html($published['name'] . ' · version ' . $published['version']) . ' (publicerad)</td></tr></table><p><button class="button button-primary">Starta testinspektion</button></p></form></div>';
    }

    public function start_test(): void
    {
        if (! current_user_can('manage_options') || 'production' === wp_get_environment_type() || ! check_admin_referer('ssf_start_test_inspection')) {
            wp_die('Testinspektion är inte tillåten.', '', array('response' => 403));
        }
        $id = self::create_test(absint($_POST['application_id'] ?? 0), absint($_POST['lead_id'] ?? 0), absint($_POST['co_id'] ?? 0));
        if (! $id) {
            wp_die('Testinspektionen kunde inte skapas.');
        }
        wp_safe_redirect(SSF_Medlemsprocess_Plugin::page_url('mina_inspektioner', array('inspection' => $id)));
        exit;
    }

    public function delete_test(): void
    {
        $id = absint($_POST['inspection_id'] ?? 0);
        if (! current_user_can('manage_options') || 'production' === wp_get_environment_type() || ! check_admin_referer('ssf_delete_test_inspection_' . $id)) {
            wp_die('Testinspektionen kan inte raderas.', '', array('response' => 403));
        }
        $record = self::record($id);
        if (empty($record['is_test'])) {
            wp_die('Endast testdata får raderas.', '', array('response' => 403));
        }
        foreach ((array) ($record['photos'] ?? array()) as $photo) {
            self::delete_photo_file((string) ($photo['path'] ?? ''));
        }
        wp_delete_post($id, true);
        wp_safe_redirect(admin_url('admin.php?page=ssf-test-inspection&deleted=1'));
        exit;
    }

    public static function private_photo_dir(): array
    {
        $uploads = wp_upload_dir();
        return array('base' => trailingslashit($uploads['basedir']) . 'ssf-private-inspections');
    }

    public static function delete_photo_file(string $path): void
    {
        $base = wp_normalize_path(self::private_photo_dir()['base']);
        $file = wp_normalize_path($base . '/' . ltrim($path, '/'));
        if ($path && 0 === strpos($file, $base . '/') && is_file($file)) {
            wp_delete_file($file);
        }
    }
}
