<?php
/** Archive PDF for membership applications. */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsprocess_PDF
{
    public function create_attachment(int $application_id): int
    {
        $application = get_post($application_id);
        if (! $application || SSF_Medlemsprocess_Application::POST_TYPE !== $application->post_type) {
            return 0;
        }

        $number = (string) get_post_meta($application_id, '_ssf_application_number', true);
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $filename = sanitize_file_name('Ansokan-' . $number . '-' . ($data['ship_name'] ?? 'fartyg') . '.pdf');
        try {
            $content = $this->render($application_id);
        } catch (RuntimeException $error) {
            SSF_Medlemsprocess_Application::add_history($application_id, 'pdf_error', 'Ansöknings-PDF kunde inte skapas.', false);
            return 0;
        }
        $upload = wp_upload_bits($filename, null, $content);
        if (! empty($upload['error'])) {
            SSF_Medlemsprocess_Application::add_history($application_id, 'pdf_error', 'Ansöknings-PDF kunde inte skapas.', false);
            return 0;
        }

        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'application/pdf',
            'post_title' => 'Ansökan ' . $number,
            'post_status' => 'inherit',
            'post_parent' => $application_id,
        ), $upload['file'], $application_id);
        if (is_wp_error($attachment_id)) {
            wp_delete_file($upload['file']);
            return 0;
        }

        update_post_meta((int) $attachment_id, '_ssf_generated_application_pdf', '1');
        SSF_Medlemsprocess_Application::add_history($application_id, 'pdf_created', 'Strukturerad ansöknings-PDF skapades.', false, array('attachment_id' => (int) $attachment_id));
        return (int) $attachment_id;
    }

    public function render(int $application_id): string
    {
        $number = (string) get_post_meta($application_id, '_ssf_application_number', true);
        $submitted = (string) get_post_meta($application_id, '_ssf_submitted_at', true);
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $profile = (array) get_post_meta($application_id, '_ssf_application_vessel_snapshot', true);
        $route = (string) get_post_meta($application_id, '_ssf_application_route', true);
        $items = array(
            array('summary', 'Ansökningsnummer', $number),
            array('summary', 'Inkommet', $submitted ? mysql2date('j F Y, H:i', $submitted) : ''),
            array('summary', 'Medlemsväg', class_exists('SSF_Medlemsfartyg_Profile') ? SSF_Medlemsfartyg_Profile::route_label($route) : $route),
            array('section', 'Fartygsombud'),
            array('field', 'Namn', $data['applicant_name'] ?? ''),
            array('field', 'Organisation', $data['applicant_organization'] ?? ''),
            array('field', 'Adress', $data['applicant_address'] ?? ''),
            array('field', 'Telefon', $data['applicant_phone'] ?? ''),
            array('field', 'E-post', $data['applicant_email'] ?? ''),
            array('field', 'Faktura-e-post', $data['applicant_invoice_email'] ?? ''),
            array('field', 'Hemsida', $data['applicant_website'] ?? ''),
        );

        if (class_exists('SSF_Medlemsfartyg_Profile')) {
            $schema = SSF_Medlemsfartyg_Profile::schema();
            $groups = array(
                'Fartyget' => 'basic',
                'Mått och dimensioner' => 'dimensions',
                'Rigg och maskin' => 'rig',
                'Historia och tidigare användning' => 'history',
                'Nuvarande verksamhet och presentation' => 'presentation',
                'Registrering' => 'registration',
                'Restaurering' => 'restoration',
                'Traditionell utformning' => 'traditional',
            );
            foreach ($groups as $heading => $section) {
                $rows = array();
                foreach ($schema as $key => $field) {
                    if (($field['section'] ?? '') !== $section || ! array_key_exists($key, $profile)) {
                        continue;
                    }
                    $value = $this->display_value($profile[$key], $field);
                    if ('' !== $value) {
                        $kind = 'textarea' === ($field['type'] ?? '') || strlen($value) > 170 ? 'paragraph' : 'field';
                        $rows[] = array($kind, (string) $field['label'], $value);
                    }
                }
                if ($rows) {
                    $items[] = array('section', $heading);
                    $items = array_merge($items, $rows);
                }
            }
        }

        $items[] = array('section', 'Bilder');
        $items[] = array('paragraph', 'Bifogade bilder', $this->attachment_list((array) get_post_meta($application_id, '_ssf_application_gallery_ids', true), (int) get_post_meta($application_id, '_ssf_application_main_image_id', true), 'Inga bilder bifogades.'));
        $items[] = array('section', 'Bilagor');
        $items[] = array('paragraph', 'Bifogade dokument', $this->attachment_list((array) get_post_meta($application_id, '_ssf_application_document_ids', true), 0, 'Inga bilagor bifogades.'));
        $items[] = array('note', 'PDF-filen är en arkiverad sammanställning. Strukturerade fartygsuppgifter och ärendestatus hanteras i WordPress.');

        return $this->build_pdf($items, $number, (string) ($data['ship_name'] ?? ''));
    }

    private function display_value($value, array $field): string
    {
        $value = trim(wp_strip_all_tags((string) $value));
        if ('checkbox' === ($field['type'] ?? '')) {
            return '1' === $value ? 'Ja' : '';
        }
        if ('select' === ($field['type'] ?? '') && isset($field['options'][$value])) {
            return (string) $field['options'][$value];
        }
        return $value;
    }

    private function attachment_list(array $ids, int $first_id, string $empty): string
    {
        $ids = array_values(array_unique(array_filter(array_merge($first_id ? array($first_id) : array(), array_map('absint', $ids)))));
        if (! $ids) {
            return $empty;
        }
        $names = array();
        foreach ($ids as $id) {
            $file = get_attached_file($id);
            if ($file) {
                $names[] = basename($file);
            }
        }
        return $names ? implode(', ', $names) : $empty;
    }

    private function build_pdf(array $items, string $number, string $ship_name): string
    {
        $pages = array(array());
        $page = 0;
        $y = 738;
        $pending = null;
        $summaries = array();

        foreach ($items as $item) {
            $type = $item[0];
            if ('summary' === $type) {
                $summaries[] = $item;
                if (3 === count($summaries)) {
                    foreach ($summaries as $index => $summary) {
                        $x = 48 + $index * 170;
                        $pages[$page][] = '0.94 0.96 0.99 rg ' . $x . ' 679 160 59 re f';
                        $pages[$page][] = $this->text($x + 10, 718, $this->uppercase($summary[1]), 8, true, '0.30 0.39 0.53');
                        foreach ($this->wrap((string) $summary[2], 24) as $line_index => $line) {
                            $pages[$page][] = $this->text($x + 10, 699 - $line_index * 12, $line, 10, true);
                        }
                    }
                    $y = 656;
                }
                continue;
            }

            if ('field' === $type) {
                if ('' === trim((string) ($item[2] ?? ''))) {
                    continue;
                }
                if (null === $pending) {
                    $pending = $item;
                    continue;
                }
                $this->field_row($pages, $page, $y, $pending, $item);
                $pending = null;
                continue;
            }
            if (null !== $pending) {
                $this->field_row($pages, $page, $y, $pending, null);
                $pending = null;
            }

            if ('section' === $type) {
                $this->new_page_if_needed($pages, $page, $y, 62);
                $y -= 17;
                $pages[$page][] = $this->text(48, $y, $this->uppercase((string) $item[1]), 11, true);
                $pages[$page][] = sprintf('0.19 0.39 0.72 RG 0.8 w 48 %d m 547 %d l S', $y - 9, $y - 9);
                $y -= 27;
                continue;
            }

            if ('note' === $type) {
                $note_lines = $this->wrap((string) $item[1], 97);
                $height = 18 + count($note_lines) * 13;
                $this->new_page_if_needed($pages, $page, $y, $height + 10);
                $pages[$page][] = sprintf('0.94 0.96 0.99 rg 48 %d 499 %d re f', $y - $height, $height);
                foreach ($note_lines as $index => $line) {
                    $pages[$page][] = $this->text(60, $y - 17 - $index * 13, $line, 9, false, '0.30 0.39 0.53');
                }
                $y -= $height + 10;
                continue;
            }

            $label = (string) $item[1];
            $value = (string) ($item[2] ?? '');
            $lines = $this->wrap($value, 91, true);
            $first = true;
            while ($lines) {
                $this->new_page_if_needed($pages, $page, $y, 58);
                if ('' !== $label) {
                    $pages[$page][] = $this->text(54, $y, $first ? $label : $label . ' (forts.)', 9, true, '0.30 0.39 0.53');
                    $y -= 17;
                }
                $room = max(1, (int) floor(($y - 68) / 14));
                $chunk = array_splice($lines, 0, $room);
                foreach ($chunk as $line) {
                    $pages[$page][] = $this->text(54, $y, $line, 10, false, '0.13 0.19 0.29');
                    $y -= 14;
                }
                $y -= 12;
                $first = false;
                if ($lines) {
                    $this->new_page_if_needed($pages, $page, $y, 1000);
                }
            }
        }
        if (null !== $pending) {
            $this->field_row($pages, $page, $y, $pending, null);
        }

        // The JPEG is a raster export of the exact theme SVG, not a redrawn logo.
        $theme_logo = function_exists('get_theme_file_path') ? get_theme_file_path('/assets/images/ssf-logo.svg') : '';
        $logo = dirname(__DIR__) . '/assets/ssf-logo-pdf.jpg';
        if (! is_file($theme_logo) || ! is_file($logo)) {
            throw new RuntimeException('SSF theme logo or PDF raster is missing.');
        }
        $image = file_get_contents($logo);
        foreach ($pages as $page_index => &$commands) {
            array_unshift($commands,
                'q 68 0 0 70 48 763 cm /Logo Do Q',
                $this->text(132, 809, 'SVERIGES SEGELFARTYGSFÖRBUND', 9, true, '0.19 0.39 0.72'),
                $this->text(132, 788, 'Ansökan om medlemskap', 17, true),
                $this->text(132, 768, 'som fartygsombud', 17, true),
                '0.19 0.39 0.72 RG 0.8 w 48 752 m 547 752 l S'
            );
            $commands[] = '0.84 0.89 0.95 RG 0.6 w 48 55 m 547 55 l S';
            $commands[] = $this->text(48, 40, trim($number . '  ·  ' . $ship_name), 8, false, '0.35 0.43 0.54');
            $commands[] = $this->text(498, 40, 'Sida ' . ($page_index + 1) . ' av ' . count($pages), 8, false, '0.35 0.43 0.54');
        }
        unset($commands);

        $objects = array(
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /XObject /Subtype /Image /Width 944 /Height 974 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($image) . " >>\nstream\n" . $image . "\nendstream",
        );
        $kids = array();
        foreach ($pages as $page_index => $commands) {
            $page_id = 6 + $page_index * 2;
            $content_id = $page_id + 1;
            $stream = implode("\n", $commands);
            $kids[] = $page_id . ' 0 R';
            $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Logo 5 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
            $objects[$content_id] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array(0 => 0);
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_keys($objects) as $id) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }

    private function field_row(array &$pages, int &$page, int &$y, array $left, ?array $right): void
    {
        $left_labels = $this->wrap((string) $left[1], 39);
        $left_lines = $this->wrap((string) $left[2], 39);
        $right_labels = null === $right ? array() : $this->wrap((string) $right[1], 39);
        $right_lines = null === $right ? array() : $this->wrap((string) $right[2], 39);
        $height = 10 + max(count($left_labels) + count($left_lines), count($right_labels) + count($right_lines)) * 13 + 9;
        $this->new_page_if_needed($pages, $page, $y, $height);
        $this->field_cell($pages[$page], 54, $y, $left_labels, $left_lines);
        if (null !== $right) {
            $this->field_cell($pages[$page], 305, $y, $right_labels, $right_lines);
        }
        $y -= $height;
    }

    private function field_cell(array &$commands, int $x, int $y, array $labels, array $lines): void
    {
        foreach ($labels as $index => $label) {
            $commands[] = $this->text($x, $y - $index * 12, $label, 9, true, '0.30 0.39 0.53');
        }
        foreach ($lines as $index => $line) {
            $commands[] = $this->text($x, $y - count($labels) * 13 - 4 - $index * 13, $line, 10, false, '0.13 0.19 0.29');
        }
    }

    private function new_page_if_needed(array &$pages, int &$page, int &$y, int $needed): void
    {
        if ($y - $needed < 68) {
            ++$page;
            $pages[$page] = array();
            $y = 738;
        }
    }

    private function wrap(string $text, int $limit, bool $preserve_breaks = false): array
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $paragraphs = $preserve_breaks ? preg_split('/\R/u', $text) : array($text);
        $max_width = array(24 => 142, 39 => 232, 91 => 476, 97 => 470)[$limit] ?? 470;
        $lines = array();
        foreach ($paragraphs as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $candidate = '' === $line ? $word : $line . ' ' . $word;
                if ($this->approx_width($candidate) <= $max_width) {
                    $line = $candidate;
                    continue;
                }
                if ('' !== $line) {
                    $lines[] = $line;
                    $line = '';
                }
                foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                    if ('' !== $line && $this->approx_width($line . $char) > $max_width) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $char;
                }
            }
            $lines[] = $line;
        }
        return $lines ?: array('');
    }

    private function approx_width(string $text): float
    {
        $width = 0.0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if (false !== strpos('WMÅÄÖ@mw', $char)) {
                $width += 10;
            } elseif (false !== strpos('ilI.,:;!|\'` ', $char)) {
                $width += 3.4;
            } elseif (preg_match('/[A-Z0-9]/u', $char)) {
                $width += 7.6;
            } else {
                $width += 6.2;
            }
        }
        return $width;
    }

    private function text(int $x, int $y, string $value, int $size, bool $bold = false, string $color = '0.04 0.13 0.29'): string
    {
        return sprintf('BT /%s %d Tf %s rg %d %d Td (%s) Tj ET', $bold ? 'F2' : 'F1', $size, $color, $x, $y, $this->escape($value));
    }

    private function uppercase(string $text): string
    {
        return strtr(strtoupper($text), array('å' => 'Å', 'ä' => 'Ä', 'ö' => 'Ö'));
    }

    private function escape(string $text): string
    {
        $encoded = function_exists('iconv') ? iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) : $text;
        return str_replace(array('\\', '(', ')', "\r", "\n"), array('\\\\', '\\(', '\\)', '', ' '), (string) $encoded);
    }
}
