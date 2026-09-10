<?php
/**
 * Small archive-PDF writer for membership applications.
 *
 * @package SSF_Medlemsprocess
 */

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
        $upload = wp_upload_bits($filename, null, $this->render($application_id));
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
        $lines = array(
            array('title', 'Ansökan om medlemskap som fartygsombud'),
            array('field', 'Ansökningsnummer', $number),
            array('field', 'Inkommet datum', $submitted ? mysql2date('j F Y, H:i', $submitted) : ''),
            array('field', 'Medlemsväg', class_exists('SSF_Medlemsfartyg_Profile') ? SSF_Medlemsfartyg_Profile::route_label($route) : $route),
            array('section', 'Fartygsombud'),
            array('field', 'Namn', $data['applicant_name'] ?? ''),
            array('field', 'Organisation', $data['applicant_organization'] ?? ''),
            array('field', 'Adress', $data['applicant_address'] ?? ''),
            array('field', 'Telefon', $data['applicant_phone'] ?? ''),
            array('field', 'E-post', $data['applicant_email'] ?? ''),
            array('field', 'Hemsida', $data['applicant_website'] ?? ''),
        );

        if (class_exists('SSF_Medlemsfartyg_Profile')) {
            $schema = SSF_Medlemsfartyg_Profile::schema();
            $groups = array(
                'Fartyget' => array('basic', 'dimensions', 'rig'),
                'Fartygets historia och nuvarande användning' => array('history', 'presentation'),
                'Särskilda uppgifter' => array('registration', 'restoration', 'traditional'),
            );
            foreach ($groups as $heading => $sections) {
                $rows = array();
                foreach ($schema as $key => $field) {
                    if (! in_array($field['section'], $sections, true) || ! array_key_exists($key, $profile)) {
                        continue;
                    }
                    $value = $this->display_value($profile[$key], $field);
                    if ('' !== $value) {
                        $rows[] = array('field', (string) $field['label'], $value);
                    }
                }
                if ($rows) {
                    $lines[] = array('section', $heading);
                    $lines = array_merge($lines, $rows);
                }
            }
        }

        $lines[] = array('section', 'Bilder');
        $lines[] = array('body', $this->attachment_list((array) get_post_meta($application_id, '_ssf_application_gallery_ids', true), (int) get_post_meta($application_id, '_ssf_application_main_image_id', true), 'Inga bilder bifogades.'));
        $lines[] = array('section', 'Bilagor');
        $lines[] = array('body', $this->attachment_list((array) get_post_meta($application_id, '_ssf_application_document_ids', true), 0, 'Inga bilagor bifogades.'));
        $lines[] = array('body', 'PDF-filen är en arkiverad sammanställning. Strukturerade fartygsuppgifter och ärendestatus hanteras i WordPress.');

        return $this->build_pdf($lines, $number, (string) ($data['ship_name'] ?? ''));
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
        $y = 748;
        foreach ($items as $item) {
            $type = $item[0];
            $label = (string) ($item[1] ?? '');
            $value = (string) ($item[2] ?? '');
            $font_size = 'title' === $type ? 20 : ('section' === $type ? 13 : 10);
            $line_height = 'title' === $type ? 26 : ('section' === $type ? 22 : 14);
            $text = 'field' === $type ? $label . ': ' . $value : $label;
            $wrapped = $this->wrap($text, 'title' === $type ? 48 : ('section' === $type ? 72 : 92));
            $needed = max($line_height, count($wrapped) * $line_height) + ('section' === $type ? 5 : 2);
            if ($y - $needed < 65) {
                ++$page;
                $pages[$page] = array();
                $y = 760;
            }
            if ('section' === $type) {
                $pages[$page][] = '0.93 0.96 1 rg 48 ' . ($y - 5) . ' 499 20 re f';
            }
            foreach ($wrapped as $line_index => $line) {
                $font = in_array($type, array('title', 'section'), true) || ('field' === $type && 0 === $line_index) ? 'F2' : 'F1';
                $color = in_array($type, array('title', 'section'), true) ? '0.04 0.13 0.29' : '0.12 0.15 0.19';
                $pages[$page][] = sprintf('BT /%s %d Tf %s rg 54 %d Td (%s) Tj ET', $font, $font_size, $color, $y, $this->escape($line));
                $y -= $line_height;
            }
            $y -= 'section' === $type ? 7 : 3;
        }

        foreach ($pages as $page_index => &$commands) {
            array_unshift($commands,
                '0.04 0.13 0.29 rg 48 786 m 48 816 l 66 824 l 84 816 l 84 786 l 66 776 l h f',
                '0.92 0.68 0.05 rg 50 819 8 6 re f 62 824 8 6 re f 74 819 8 6 re f',
                '1 1 1 RG 1.6 w 66 813 m 66 789 l S 59 806 m 73 806 l S 61 813 m 61 816 71 816 71 813 c 71 810 61 810 61 813 c S 55 794 m 58 787 63 784 66 784 c 69 784 74 787 77 794 c S 55 794 m 60 795 l S 77 794 m 72 795 l S',
                'BT /F2 24 Tf 0.04 0.13 0.29 rg 98 795 Td (SSF) Tj ET',
                'BT /F1 9 Tf 0.25 0.29 0.35 rg 98 782 Td (' . $this->escape('Sveriges Segelfartygsförbund') . ') Tj ET',
                '0.19 0.39 0.72 RG 48 768 m 547 768 l S'
            );
            $commands[] = 'BT /F1 8 Tf 0.4 0.43 0.48 rg 54 35 Td (' . $this->escape($number . ' - ' . $ship_name . ' - Sida ' . ($page_index + 1) . ' av ' . count($pages)) . ') Tj ET';
        }
        unset($commands);

        $objects = array(
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        );
        $kids = array();
        foreach ($pages as $page_index => $commands) {
            $page_id = 5 + ($page_index * 2);
            $content_id = $page_id + 1;
            $stream = implode("\n", $commands);
            $kids[] = $page_id . ' 0 R';
            $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
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
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_keys($objects) as $id) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private function wrap(string $text, int $limit): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ('' === $text) {
            return array('–');
        }
        $lines = array();
        $line = '';
        foreach (preg_split('/\s+/u', $text) as $word) {
            $candidate = '' === $line ? $word : $line . ' ' . $word;
            $length = function_exists('mb_strlen') ? mb_strlen($candidate) : strlen($candidate);
            if ($length > $limit && '' !== $line) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ('' !== $line) {
            $lines[] = $line;
        }
        return $lines;
    }

    private function escape(string $text): string
    {
        $encoded = function_exists('iconv') ? iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) : $text;
        return str_replace(array('\\', '(', ')', "\r", "\n"), array('\\\\', '\\(', '\\)', '', ' '), (string) $encoded);
    }
}
