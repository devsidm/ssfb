<?php
/**
 * Plugin Name: SSF Organization Info
 * Description: Central source for SSF organization, payment and membership fee information.
 * Version: 1.0.0
 * Author: SIDM
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Organization_Info
{
    private const OPTION = 'ssf_organization_info';

    public static function defaults(): array
    {
        return array(
            'organization_name' => 'Sveriges Segelfartygsförbund',
            'website_url' => 'https://ssfb.se',
            'website_label' => 'ssfb.se',
            'address_line_1' => 'C/O HSX 031W',
            'address_line_2' => 'BILLO',
            'postal_code' => '10646',
            'city' => 'STOCKHOLM',
            'organization_number' => '8328008605',
            'bankgiro' => '332-1908',
            'swish' => '1236400279',
        );
    }

    public static function get(): array
    {
        $saved = (array) get_option(self::OPTION, array());
        $values = array_replace(self::defaults(), array_intersect_key($saved, self::defaults()));
        $values = self::sanitize($values);
        return (array) apply_filters('ssf_organization_info', $values);
    }

    public static function save(array $input): void
    {
        update_option(self::OPTION, self::sanitize(array_replace(self::defaults(), $input)), false);
    }

    public static function membership_fees(): array
    {
        return array(
            'support' => array('label' => 'Stödmedlem', 'amount' => '200 kr/år'),
            'leisure' => array('label' => 'Fritidsfartyg', 'amount' => '500 kr/år per fartyg'),
            'commercial' => array('label' => 'Handelsfartyg', 'amount' => '1 500 kr/år per fartyg'),
        );
    }

    public static function address_lines(bool $display_format = true): array
    {
        $info = self::get();
        $postal_code = $display_format ? self::display_postal_code($info['postal_code']) : $info['postal_code'];
        $city = $display_format ? self::title_case($info['city']) : $info['city'];
        return array_filter(array(
            $info['address_line_1'],
            $info['address_line_2'],
            trim($postal_code . ' ' . $city),
        ));
    }

    private static function sanitize(array $values): array
    {
        $defaults = self::defaults();
        $clean = array();
        foreach ($defaults as $key => $default) {
            $value = isset($values[$key]) && is_scalar($values[$key]) ? (string) $values[$key] : $default;
            $clean[$key] = sanitize_text_field($value);
            if ('' === $clean[$key]) {
                $clean[$key] = $default;
            }
        }
        $website = isset($values['website_url']) && is_scalar($values['website_url']) ? (string) $values['website_url'] : $defaults['website_url'];
        $url = esc_url_raw($website);
        $clean['website_url'] = 0 === stripos($url, 'https://') ? $url : $defaults['website_url'];
        return $clean;
    }

    private static function display_postal_code(string $postal_code): string
    {
        $compact = preg_replace('/\s+/', '', $postal_code);
        return preg_match('/^\d{5}$/', $compact) ? substr($compact, 0, 3) . ' ' . substr($compact, 3) : $postal_code;
    }

    private static function title_case(string $value): string
    {
        return function_exists('mb_convert_case') ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : ucwords(strtolower($value));
    }
}
