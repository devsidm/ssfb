<?php
/**
 * Shared vessel profile schema and persistence.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Profile
{
    public const MODE_APPLICATION = 'application';
    public const MODE_UPDATE = 'update';
    public const MODE_PORTAL = 'portal';
    public const MODE_ADMIN = 'admin';

    public static function routes(): array
    {
        return array(
            'normal' => array(
                'number' => 1,
                'title' => 'Seglande yrkesfartyg',
                'summary' => 'Fartyget uppfyller stadgans måttkrav.',
                'description' => 'Fartyget är ett segelfartyg eller segelfartyg med hjälpmotor, används eller har tidigare använts som seglande yrkesfartyg, har en längd i huvuddäck över 12 meter och uppfyller stadgans breddkrav om 4 meter.',
            ),
            'small_registered' => array(
                'number' => 2,
                'title' => 'Mindre registrerat fartyg',
                'summary' => 'Under ett måttkrav men registrerat i Sverige.',
                'description' => 'Fartyget understiger ett eller båda måttkraven men är registrerat i svenskt skeppsregister eller fartygsregister och går därför vidare till särskild prövning.',
            ),
            'restoration' => array(
                'number' => 3,
                'title' => 'Fartyg under restaurering',
                'summary' => 'En trovärdig plan finns för fartygets framtid.',
                'description' => 'Fartyget är under restaurering och är av sådan typ som stadgan avser. Det ska finnas en trovärdig plan för att bevara eller återföra fartyget som segelfartyg.',
            ),
            'new_traditional' => array(
                'number' => 4,
                'title' => 'Nybyggt traditionsfartyg',
                'summary' => 'Byggt enligt traditioner för äldre yrkessegelfartyg.',
                'description' => 'Fartyget är nybyggt men byggt och utformat i överensstämmelse med vedertagna traditioner för äldre segelfartyg i yrkessjöfart.',
            ),
        );
    }

    public static function route_label(string $route): string
    {
        return self::routes()[$route]['title'] ?? $route;
    }

    public static function sections(): array
    {
        return array(
            'basic' => array('label' => 'Grunduppgifter', 'intro' => 'Fartygets identitet, byggnation och hemmahamn.'),
            'dimensions' => array('label' => 'Mått och konstruktion', 'intro' => 'Längd i huvuddäck och bredd används vid bedömningen enligt SSF:s stadgar.'),
            'history' => array('label' => 'Fartygets historia', 'intro' => 'Beskriv fartygets bakgrund och tidigare användning.'),
            'rig' => array('label' => 'Rigg och användning', 'intro' => 'Uppgifter om rigg, yrkesverksamhet och eventuell hjälpmotor.'),
            'registration' => array('label' => 'Registrering', 'intro' => 'Det mindre fartyget går vidare till särskild prövning.'),
            'restoration' => array('label' => 'Restaurering', 'intro' => 'Beskriv nuläge, mål och hur fartyget ska bevaras eller återföras som segelfartyg.'),
            'traditional' => array('label' => 'Traditionell konstruktion', 'intro' => 'Beskriv förebild, konstruktion och de traditioner som har följts.'),
            'presentation' => array('label' => 'Publik presentation', 'intro' => 'Texten kan efter granskning användas på fartygets publika sida.'),
            'contact' => array('label' => 'Fartygsombud och länkar', 'intro' => 'Kontaktuppgifterna används internt och publiceras bara efter ett uttryckligt val.'),
        );
    }

    public static function schema(): array
    {
        $all = array(self::MODE_APPLICATION, self::MODE_UPDATE, self::MODE_PORTAL, self::MODE_ADMIN);
        $edit = array(self::MODE_UPDATE, self::MODE_PORTAL, self::MODE_ADMIN);

        return array(
            'post_title' => self::field('Fartygsnamn', 'text', 'basic', true, true, $all),
            '_ssf_previous_names' => self::field('Tidigare namn', 'text', 'basic', false, true, $all),
            '_ssf_build_year' => self::field('Byggår', 'number', 'basic', false, true, $all, array(), 'Ange årtal med fyra siffror.', array('min' => 1000, 'max' => (int) wp_date('Y') + 2)),
            '_ssf_build_place' => self::field('Byggnadsort', 'text', 'basic', false, true, $all),
            '_ssf_shipyard' => self::field('Varv eller byggare', 'text', 'basic', false, true, $all),
            '_ssf_nationality' => self::field('Nationalitet', 'text', 'basic', false, true, $all),
            '_ssf_home_port' => self::field('Hemmahamn', 'text', 'basic', true, true, $all),
            'tax_fartygstyp' => self::field('Fartygstyp', 'taxonomy', 'basic', true, true, $all, array(), '', array('taxonomy' => 'fartygstyp')),
            '_ssf_rig' => self::field('Rigtyp', 'text', 'basic', false, true, $all),
            '_ssf_hull_type' => self::field('Skrovtyp', 'text', 'basic', false, true, $all),
            '_ssf_material' => self::field('Material', 'text', 'basic', false, true, $all),

            '_ssf_main_deck_length' => self::field('Längd i huvuddäck (meter)', 'number', 'dimensions', true, true, $all, array(), 'Måttet används vid bedömning enligt SSF:s stadgar.', array('min' => 0.1, 'step' => '0.01')),
            '_ssf_length' => self::field('Total längd (meter)', 'number', 'dimensions', false, true, $all, array(), '', array('min' => 0.1, 'step' => '0.01')),
            '_ssf_beam' => self::field('Bredd (meter)', 'number', 'dimensions', true, true, $all, array(), 'Måttet används vid bedömning enligt SSF:s stadgar.', array('min' => 0.1, 'step' => '0.01')),
            '_ssf_draft' => self::field('Djupgående (meter)', 'number', 'dimensions', false, true, $all, array(), '', array('min' => 0, 'step' => '0.01')),
            '_ssf_displacement' => self::field('Deplacement', 'text', 'dimensions', false, true, $all),

            '_ssf_previous_use' => self::field('Tidigare användning', 'textarea', 'history', false, true, $all),
            '_ssf_history' => self::field('Historik', 'textarea', 'history', true, true, $all),
            '_ssf_previous_home_ports' => self::field('Tidigare hemmahamnar', 'textarea', 'history', false, true, $all),
            '_ssf_previous_owners' => self::field('Tidigare ägare', 'textarea', 'history', false, true, $all),
            '_ssf_professional_use' => self::field('Har fartyget använts som seglande yrkesfartyg?', 'select', 'history', false, false, $all, array(), '', array('options' => array('' => 'Välj', 'yes' => 'Ja', 'no' => 'Nej', 'unknown' => 'Okänt'))),
            '_ssf_professional_use_description' => self::field('Hur har fartyget använts?', 'textarea', 'history', false, true, $all),

            '_ssf_masts' => self::field('Antal master', 'number', 'rig', false, true, $all, array(), '', array('min' => 0, 'max' => 10)),
            '_ssf_sail_area' => self::field('Segelyta', 'text', 'rig', false, true, $all),
            '_ssf_rig_description' => self::field('Beskrivning av riggen', 'textarea', 'rig', false, true, $all),
            '_ssf_rig_period' => self::field('Riggens utförande', 'select', 'rig', false, true, $all, array(), '', array('options' => array('' => 'Välj', 'historical' => 'Historisk rigg', 'current' => 'Nuvarande rigg', 'combined' => 'Historisk och nuvarande'))),
            '_ssf_has_aux_engine' => self::field('Har fartyget hjälpmotor?', 'select', 'rig', false, false, $all, array(), '', array('options' => array('' => 'Välj', 'yes' => 'Ja', 'no' => 'Nej'))),
            '_ssf_engine' => self::field('Motor', 'text', 'rig', false, true, $all),
            '_ssf_engine_power' => self::field('Motoreffekt', 'text', 'rig', false, true, $all),
            '_ssf_engine_year' => self::field('Motorns årsmodell', 'number', 'rig', false, true, $all, array(), '', array('min' => 1900, 'max' => (int) wp_date('Y') + 2)),

            '_ssf_registration_type' => self::field('Svenskt register', 'select', 'registration', true, false, $all, array('small_registered'), '', array('options' => array('' => 'Välj register', 'ship' => 'Skeppsregister', 'vessel' => 'Fartygsregister', 'other' => 'Annat relevant register'))),
            '_ssf_registry_number' => self::field('Registreringsnummer', 'text', 'registration', true, false, $all, array('small_registered')),
            '_ssf_call_sign' => self::field('Signalbokstäver', 'text', 'registration', false, true, $all, array('small_registered')),
            '_ssf_mmsi' => self::field('MMSI', 'text', 'registration', false, false, $all, array('small_registered')),

            '_ssf_restoration_condition' => self::field('Nuvarande skick', 'textarea', 'restoration', true, false, $all, array('restoration')),
            '_ssf_restoration_remaining' => self::field('Vad återstår?', 'textarea', 'restoration', false, false, $all, array('restoration')),
            '_ssf_restoration_goal' => self::field('Mål med restaureringen', 'textarea', 'restoration', true, false, $all, array('restoration')),
            '_ssf_preservation_plan' => self::field('Hur ska fartyget bevaras eller återföras som segelfartyg?', 'textarea', 'restoration', true, false, $all, array('restoration')),
            '_ssf_restoration_timeline' => self::field('Tidsplan', 'textarea', 'restoration', false, false, $all, array('restoration')),
            '_ssf_restoration_documented' => self::field('Finns en dokumenterad restaureringsplan?', 'select', 'restoration', false, false, $all, array('restoration'), '', array('options' => array('' => 'Välj', 'yes' => 'Ja', 'no' => 'Nej'))),

            '_ssf_traditional_archetype' => self::field('Vilken äldre fartygstyp bygger fartyget på?', 'text', 'traditional', true, true, $all, array('new_traditional')),
            '_ssf_traditional_traditions' => self::field('Vilka vedertagna traditioner har följts?', 'textarea', 'traditional', true, true, $all, array('new_traditional')),
            '_ssf_traditional_construction' => self::field('Beskriv konstruktionen', 'textarea', 'traditional', true, true, $all, array('new_traditional')),
            '_ssf_traditional_rig' => self::field('Beskriv riggen', 'textarea', 'traditional', false, true, $all, array('new_traditional')),
            '_ssf_traditional_reference' => self::field('Historisk förebild', 'textarea', 'traditional', false, true, $all, array('new_traditional')),
            '_ssf_designer' => self::field('Konstruktör', 'text', 'traditional', false, true, $all, array('new_traditional')),

            'post_excerpt' => self::field('Kort presentation', 'textarea', 'presentation', true, true, $all),
            'post_content' => self::field('Publik beskrivning', 'textarea', 'presentation', false, true, $all),
            '_ssf_today' => self::field('Vad gör fartyget idag?', 'textarea', 'presentation', false, true, $all),
            '_ssf_activity' => self::field('Verksamhet', 'textarea', 'presentation', false, true, $all),
            '_ssf_future' => self::field('Kommande planer', 'textarea', 'presentation', false, true, $all),

            '_ssf_contact_name' => self::field('Namn på fartygsombud', 'text', 'contact', false, false, $edit),
            '_ssf_organization' => self::field('Organisation, förening eller rederi', 'text', 'contact', false, false, $edit),
            '_ssf_email' => self::field('E-post', 'email', 'contact', false, false, $edit),
            '_ssf_phone' => self::field('Telefon', 'text', 'contact', false, false, $edit),
            '_ssf_website' => self::field('Webbplats', 'url', 'contact', false, true, $edit),
            '_ssf_facebook' => self::field('Facebook', 'url', 'contact', false, true, $edit),
            '_ssf_instagram' => self::field('Instagram', 'url', 'contact', false, true, $edit),
            '_ssf_other_link' => self::field('Annan länk', 'url', 'contact', false, true, $edit),
            '_ssf_public_contact' => self::field('Visa namn och organisation publikt', 'checkbox', 'contact', false, false, $edit),
            '_ssf_public_website' => self::field('Visa webbplats publikt', 'checkbox', 'contact', false, false, $edit),
            '_ssf_public_phone' => self::field('Visa telefon publikt', 'checkbox', 'contact', false, false, $edit),
            '_ssf_public_email' => self::field('Visa e-post publikt', 'checkbox', 'contact', false, false, $edit),
        );
    }

    private static function field(string $label, string $type, string $section, bool $required, bool $public, array $modes, array $routes = array(), string $help = '', array $extra = array()): array
    {
        return array_merge(array(
            'label' => $label,
            'type' => $type,
            'section' => $section,
            'required' => $required,
            'public' => $public,
            'internal' => ! $public,
            'modes' => $modes,
            'routes' => $routes,
            'help' => $help,
        ), $extra);
    }

    public static function fields_for(string $route, string $mode): array
    {
        return array_filter(self::schema(), static function (array $field) use ($route, $mode): bool {
            if (! in_array($mode, $field['modes'], true)) {
                return false;
            }
            if (self::MODE_ADMIN === $mode || empty($field['routes'])) {
                return true;
            }
            if (! $route) {
                return self::MODE_APPLICATION === $mode;
            }
            return in_array($route, $field['routes'], true);
        });
    }

    public static function collect(array $source, string $route, string $mode): array
    {
        $data = array();
        foreach (self::fields_for($route, $mode) as $key => $field) {
            if (! array_key_exists($key, $source)) {
                if ('checkbox' === $field['type']) {
                    $data[$key] = '0';
                }
                continue;
            }
            $value = wp_unslash($source[$key]);
            if ('checkbox' === $field['type']) {
                $data[$key] = ! empty($value) ? '1' : '0';
            } elseif ('email' === $field['type']) {
                $data[$key] = sanitize_email((string) $value);
            } elseif ('url' === $field['type']) {
                $data[$key] = esc_url_raw((string) $value);
            } elseif ('textarea' === $field['type']) {
                $data[$key] = wp_kses_post((string) $value);
            } elseif ('taxonomy' === $field['type']) {
                $data[$key] = sanitize_text_field((string) $value);
            } else {
                $data[$key] = sanitize_text_field((string) $value);
            }
        }
        return $data;
    }

    public static function validate(array $data, string $route, string $mode): WP_Error
    {
        $errors = new WP_Error();
        if (! isset(self::routes()[$route]) && self::MODE_APPLICATION === $mode) {
            $errors->add('route', 'Välj den väg som bäst beskriver fartyget.');
        }
        foreach (self::fields_for($route, $mode) as $key => $field) {
            $value = trim(wp_strip_all_tags((string) ($data[$key] ?? '')));
            if (! empty($field['required']) && '' === $value) {
                $errors->add($key, sprintf('Fyll i fältet %s.', $field['label']));
            }
            if ('email' === $field['type'] && $value && ! is_email($value)) {
                $errors->add($key, sprintf('%s måste vara en giltig e-postadress.', $field['label']));
            }
            if ('number' === $field['type'] && $value && ! is_numeric(str_replace(',', '.', $value))) {
                $errors->add($key, sprintf('%s måste vara ett tal.', $field['label']));
            } elseif ('number' === $field['type'] && $value) {
                $number = (float) str_replace(',', '.', $value);
                if (isset($field['min']) && $number < (float) $field['min']) {
                    $errors->add($key, sprintf('%s måste vara minst %s.', $field['label'], $field['min']));
                }
                if (isset($field['max']) && $number > (float) $field['max']) {
                    $errors->add($key, sprintf('%s får vara högst %s.', $field['label'], $field['max']));
                }
            }
        }
        return $errors;
    }

    public static function values(int $ship_id, string $route, string $mode): array
    {
        $values = array();
        foreach (self::fields_for($route, $mode) as $key => $field) {
            $values[$key] = self::value($ship_id, $key);
        }
        return $values;
    }

    public static function value(int $ship_id, string $key): string
    {
        if (in_array($key, array('post_title', 'post_excerpt', 'post_content'), true)) {
            $value = (string) get_post_field($key, $ship_id);
        } elseif (0 === strpos($key, 'tax_')) {
            $terms = wp_get_object_terms($ship_id, substr($key, 4), array('fields' => 'names'));
            $value = is_wp_error($terms) || ! $terms ? '' : (string) reset($terms);
        } else {
            $value = (string) get_post_meta($ship_id, $key, true);
        }

        if ('' !== $value) {
            return $value;
        }
        $aliases = array(
            '_ssf_main_deck_length' => '_ssf_length',
            '_ssf_build_place' => '_ssf_shipyard',
            'post_excerpt' => '_ssf_short_presentation',
        );
        return isset($aliases[$key]) ? (string) get_post_meta($ship_id, $aliases[$key], true) : '';
    }

    public static function save(int $ship_id, array $data, string $source = 'admin'): void
    {
        $post_data = array('ID' => $ship_id);
        foreach (array('post_title', 'post_excerpt', 'post_content') as $key) {
            if (array_key_exists($key, $data)) {
                $post_data[$key] = 'post_content' === $key ? wp_kses_post($data[$key]) : sanitize_text_field($data[$key]);
                if ('post_excerpt' === $key) {
                    $post_data[$key] = sanitize_textarea_field($data[$key]);
                }
            }
        }
        if (count($post_data) > 1) {
            wp_update_post($post_data);
        }

        foreach ($data as $key => $value) {
            if (in_array($key, array('post_title', 'post_excerpt', 'post_content'), true)) {
                continue;
            }
            if (0 === strpos($key, 'tax_')) {
                $taxonomy = substr($key, 4);
                if ($value && taxonomy_exists($taxonomy)) {
                    wp_set_object_terms($ship_id, sanitize_text_field((string) $value), $taxonomy, false);
                }
                continue;
            }
            if (isset(self::schema()[$key])) {
                update_post_meta($ship_id, $key, $value);
            }
        }
        if (isset($data['post_excerpt'])) {
            update_post_meta($ship_id, '_ssf_short_presentation', sanitize_textarea_field($data['post_excerpt']));
        }
        update_post_meta($ship_id, '_ssf_profile_updated_at', current_time('mysql'));
        update_post_meta($ship_id, '_ssf_profile_updated_source', sanitize_key($source));
    }

    public static function create_for_application(int $application_id, string $route, array $data, array $contact): int
    {
        if (! post_type_exists('medlemsfartyg')) {
            return 0;
        }
        $ship_id = wp_insert_post(array(
            'post_type' => 'medlemsfartyg',
            'post_status' => 'draft',
            'post_title' => sanitize_text_field($data['post_title'] ?? 'Namnlöst fartyg'),
            'post_excerpt' => sanitize_textarea_field($data['post_excerpt'] ?? ''),
            'post_content' => wp_kses_post($data['post_content'] ?? ''),
        ), true);
        if (is_wp_error($ship_id)) {
            return 0;
        }
        self::save((int) $ship_id, $data, 'application');
        foreach (array('_ssf_contact_name' => 'applicant_name', '_ssf_email' => 'applicant_email', '_ssf_phone' => 'applicant_phone', '_ssf_organization' => 'applicant_organization', '_ssf_website' => 'applicant_website') as $meta_key => $contact_key) {
            update_post_meta((int) $ship_id, $meta_key, (string) ($contact[$contact_key] ?? ''));
        }
        update_post_meta((int) $ship_id, '_ssf_application_route', $route);
        update_post_meta((int) $ship_id, '_ssf_source_application_id', $application_id);
        update_post_meta((int) $ship_id, '_ssf_public_visibility', 'draft');
        update_post_meta((int) $ship_id, '_ssf_review_status', 'Ansökan inskickad');
        update_post_meta($application_id, '_ssf_linked_ship_id', (int) $ship_id);
        return (int) $ship_id;
    }

    public static function attach_application_files(int $ship_id, array $attachment_ids, int $main_image_id = 0): void
    {
        $images = array();
        $documents = array();
        foreach (array_filter(array_map('intval', $attachment_ids)) as $attachment_id) {
            if (wp_attachment_is_image($attachment_id)) {
                $images[] = $attachment_id;
            } else {
                $documents[] = $attachment_id;
            }
        }
        if ($main_image_id && in_array($main_image_id, $images, true)) {
            set_post_thumbnail($ship_id, $main_image_id);
        } elseif ($images) {
            set_post_thumbnail($ship_id, reset($images));
        }
        $featured = (int) get_post_thumbnail_id($ship_id);
        update_post_meta($ship_id, '_ssf_gallery_ids', implode(',', array_diff($images, array($featured))));
        update_post_meta($ship_id, '_ssf_profile_document_ids', array_values($documents));
    }

    public static function is_public(int $ship_id): bool
    {
        if ('publish' !== get_post_status($ship_id)) {
            return false;
        }
        $visibility = (string) get_post_meta($ship_id, '_ssf_public_visibility', true);
        return '' === $visibility || 'public' === $visibility;
    }

    public static function legacy_application_data(int $ship_id): array
    {
        return array(
            'ship_name' => self::value($ship_id, 'post_title'),
            'ship_length' => self::value($ship_id, '_ssf_main_deck_length'),
            'ship_beam' => self::value($ship_id, '_ssf_beam'),
            'ship_draft' => self::value($ship_id, '_ssf_draft'),
            'ship_registry_number' => self::value($ship_id, '_ssf_registry_number'),
            'ship_type' => self::value($ship_id, 'tax_fartygstyp'),
            'ship_rig' => self::value($ship_id, '_ssf_rig'),
            'ship_build_year' => self::value($ship_id, '_ssf_build_year'),
            'ship_shipyard' => self::value($ship_id, '_ssf_shipyard'),
            'ship_home_port' => self::value($ship_id, '_ssf_home_port'),
            'ship_short_description' => self::value($ship_id, 'post_excerpt'),
            'ship_history' => self::value($ship_id, '_ssf_history'),
            'ship_current_use' => self::value($ship_id, '_ssf_today'),
            'ship_description' => self::value($ship_id, 'post_content'),
        );
    }

    public static function render(string $mode, string $route = '', array $values = array(), bool $as_steps = false): void
    {
        $fields = self::fields_for($route, $mode);
        foreach (self::sections() as $section_key => $section) {
            $section_fields = array_filter($fields, static function (array $field) use ($section_key): bool {
                return $section_key === $field['section'];
            });
            if (! $section_fields) {
                continue;
            }
            $section_routes = array();
            foreach ($section_fields as $section_field) {
                $section_routes = array_merge($section_routes, $section_field['routes']);
            }
            $section_routes = array_values(array_unique($section_routes));
            $classes = 'ssf-vessel-profile-section' . ($as_steps ? ' ssf-collection-step' : '');
            echo '<section class="' . esc_attr($classes) . '" data-vessel-section="' . esc_attr($section_key) . '"' . ($section_routes ? ' data-routes="' . esc_attr(implode(',', $section_routes)) . '"' : '') . '>';
            echo '<div class="ssf-vessel-section-heading"><h3>' . esc_html($section['label']) . '</h3><p>' . esc_html($section['intro']) . '</p></div><div class="ssf-vessel-fields">';
            foreach ($section_fields as $key => $field) {
                self::render_field($key, $field, (string) ($values[$key] ?? ''));
            }
            echo '</div></section>';
        }
    }

    private static function render_field(string $key, array $field, string $value): void
    {
        $route_attr = $field['routes'] ? ' data-routes="' . esc_attr(implode(',', $field['routes'])) . '"' : '';
        $required_attr = $field['required'] ? ' data-route-required="1"' : '';
        $required = $field['required'] ? ' required' : '';
        echo '<label class="ssf-vessel-field"' . $route_attr . $required_attr . '><span>' . esc_html($field['label']) . ($field['required'] ? ' <em>Obligatorisk</em>' : '') . '</span>';
        $attributes = '';
        foreach (array('min', 'max', 'step') as $attribute) {
            if (isset($field[$attribute])) {
                $attributes .= ' ' . $attribute . '="' . esc_attr((string) $field[$attribute]) . '"';
            }
        }
        if ('textarea' === $field['type']) {
            echo '<textarea name="' . esc_attr($key) . '" rows="5"' . $required . '>' . esc_textarea($value) . '</textarea>';
        } elseif ('select' === $field['type']) {
            echo '<select name="' . esc_attr($key) . '"' . $required . '>';
            foreach ($field['options'] as $option_value => $option_label) {
                echo '<option value="' . esc_attr((string) $option_value) . '" ' . selected($value, (string) $option_value, false) . '>' . esc_html($option_label) . '</option>';
            }
            echo '</select>';
        } elseif ('taxonomy' === $field['type']) {
            echo '<select name="' . esc_attr($key) . '"' . $required . '><option value="">Välj</option>';
            $terms = get_terms(array('taxonomy' => $field['taxonomy'], 'hide_empty' => false));
            if (! is_wp_error($terms)) {
                foreach ($terms as $term) {
                    echo '<option value="' . esc_attr($term->name) . '" ' . selected($value, $term->name, false) . '>' . esc_html($term->name) . '</option>';
                }
            }
            echo '</select>';
        } elseif ('checkbox' === $field['type']) {
            echo '<input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked('1', $value, false) . '>';
        } else {
            echo '<input type="' . esc_attr($field['type']) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '"' . $attributes . $required . '>';
        }
        if ($field['help']) {
            echo '<small>' . esc_html($field['help']) . '</small>';
        }
        echo '</label>';
    }
}
