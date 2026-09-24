<?php
/** Standalone PDF-layout smoke test. No WordPress writes. */
define('ABSPATH', __DIR__);
$root = dirname(__DIR__, 2);
require $root . '/wp-content/plugins/ssf-medlemsprocess/includes/class-ssf-medlemsprocess-pdf.php';

class SSF_Medlemsprocess_Application
{
    public static function data(int $id): array
    {
        return array(
            'ship_name' => 'Wietze', 'applicant_name' => 'David Pajus',
            'applicant_organization' => 'Privat', 'applicant_address' => 'Mildsgata 3',
            'applicant_phone' => '0701234567', 'applicant_email' => 'david@example.org',
            'applicant_invoice_email' => 'faktura@example.org', 'applicant_website' => 'https://example.org',
        );
    }
}
class SSF_Medlemsfartyg_Profile
{
    public static function route_label(string $route): string { return 'Fartyg under restaurering'; }
    public static function schema(): array
    {
        return array(
            'post_title' => array('section' => 'basic', 'label' => 'Fartygsnamn', 'type' => 'text'),
            '_ssf_build_year' => array('section' => 'basic', 'label' => 'Byggår', 'type' => 'number'),
            '_ssf_main_deck_length' => array('section' => 'dimensions', 'label' => 'Längd i huvuddäck (meter)', 'type' => 'number'),
            '_ssf_sail_area' => array('section' => 'rig', 'label' => 'Segelyta (m²)', 'type' => 'number'),
            '_ssf_engine' => array('section' => 'rig', 'label' => 'Huvudmaskin', 'type' => 'text'),
            '_ssf_history' => array('section' => 'history', 'label' => 'Fartygets historia', 'type' => 'textarea'),
            '_ssf_today' => array('section' => 'presentation', 'label' => 'Vad gör fartyget idag?', 'type' => 'textarea'),
            '_ssf_restoration_condition' => array('section' => 'restoration', 'label' => 'Nuvarande skick', 'type' => 'textarea'),
            '_ssf_restoration_goal' => array('section' => 'restoration', 'label' => 'Mål med restaureringen', 'type' => 'textarea'),
        );
    }
}
function get_post_meta(int $id, string $key, bool $single = true)
{
    $values = array(
        '_ssf_application_number' => 'SSF-2026-0001',
        '_ssf_submitted_at' => '2026-09-16 02:04:00',
        '_ssf_application_route' => 'restoration',
        '_ssf_application_vessel_snapshot' => array(
            'post_title' => 'Wietze', '_ssf_build_year' => '1902',
            '_ssf_main_deck_length' => '26', '_ssf_sail_area' => '180', '_ssf_engine' => 'Volvo Penta',
            '_ssf_history' => str_repeat('Wietze seglade mellan svenska hamnar. ', 65),
            '_ssf_today' => 'Seglas och vårdas av föreningen.',
            '_ssf_restoration_condition' => 'Skrovet är i gott skick.',
            '_ssf_restoration_goal' => str_repeat('Bevara riggen och återställa segelförmågan. ', 42),
        ),
        '_ssf_application_gallery_ids' => array(10),
        '_ssf_application_main_image_id' => 10,
        '_ssf_application_document_ids' => array(11),
    );
    return $values[$key] ?? '';
}
function mysql2date(string $format, string $date): string { return '16 september 2026, 02:04'; }
function wp_strip_all_tags(string $text): string { return strip_tags($text); }
function absint($value): int { return abs((int) $value); }
function get_attached_file(int $id): string { return 10 === $id ? '/uploads/wietze.jpg' : '/uploads/ritning.pdf'; }
function get_theme_file_path(string $path): string
{
    return dirname(__DIR__, 2) . '/wp-content/themes/ssf' . $path;
}

$pdf = (new SSF_Medlemsprocess_PDF())->render(1);
// The committed JPEG must be regenerated if the theme's source SVG changes.
if ('1b9b709ca8238a26d5b3b46b405247cb3dbe51e055af3e64ccf8bef8af853cdf' !== hash_file('sha256', get_theme_file_path('/assets/images/ssf-logo.svg'))) {
    throw new RuntimeException('Theme SVG changed; regenerate the PDF logo raster.');
}
$text = str_replace(array('\\(', '\\)'), array('(', ')'), iconv('Windows-1252', 'UTF-8//IGNORE', $pdf));
foreach (array('SVERIGES SEGELFARTYGSFÖRBUND', 'Fartygsombud', 'MÅTT OCH DIMENSIONER', 'Segelyta (m²)', 'Volvo Penta', 'Seglas och vårdas', 'Restaurering', 'Nuvarande skick', 'Skrovet är i gott skick.', 'wietze.jpg', 'ritning.pdf', 'Sida 1 av ') as $needle) {
    if (false === stripos($text, $needle)) {
        throw new RuntimeException('Missing PDF content: ' . $needle);
    }
}
if (false !== strpos($pdf, '(SSF) Tj') || false === strpos($pdf, '/Subtype /Image') || false === strpos($pdf, '/Logo Do')) {
    throw new RuntimeException('PDF must embed the real theme-logo raster.');
}
$page_count = preg_match_all('/\/Type \/Page \/Parent/', $pdf);
if ($page_count < 2 || substr_count($pdf, 'Sida ') !== $page_count) {
    throw new RuntimeException('Page breaks or page numbering failed.');
}
$output = sys_get_temp_dir() . '/ssf-wietze-pdf-layout-test.pdf';
file_put_contents($output, $pdf);
echo "PASS: {$page_count} pages; {$output}\n";
