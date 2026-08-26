<?php

namespace SSF\MemberPortal\Core;

if (! defined('ABSPATH')) {
    exit;
}

final class Logger
{
    private const OPTION = 'ssf_member_portal_log';

    public static function add(string $event, array $context = array()): void
    {
        $log = (array) get_option(self::OPTION, array());
        $log[] = array(
            'at' => time(),
            'event' => sanitize_key($event),
            'context' => self::sanitize_context($context),
        );
        update_option(self::OPTION, array_slice($log, -100), false);
    }

    public static function recent(int $limit = 20): array
    {
        return array_reverse(array_slice((array) get_option(self::OPTION, array()), -1 * max(1, $limit)));
    }

    private static function sanitize_context(array $context): array
    {
        $sanitized = array();
        foreach ($context as $key => $value) {
            if (is_scalar($value) || null === $value) {
                $sanitized[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
            }
        }
        return $sanitized;
    }
}
