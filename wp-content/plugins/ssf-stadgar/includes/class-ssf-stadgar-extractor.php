<?php
/**
 * Conservative PDF text and outline extraction with an editable fallback.
 *
 * @package SSF_Stadgar
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Stadgar_Extractor
{
    private SSF_Stadgar_Document $documents;

    public function __construct(SSF_Stadgar_Document $documents)
    {
        $this->documents = $documents;
    }

    public function extract(int $attachment_id): array
    {
        $file = get_attached_file($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);

        if (! $file || ! file_exists($file) || 'application/pdf' !== $mime_type) {
            return array('text' => '', 'outline' => array(), 'message' => 'Välj en PDF från WordPress mediabibliotek.');
        }

        $size = filesize($file);
        if (false === $size || $size > 20 * MB_IN_BYTES) {
            return array('text' => '', 'outline' => array(), 'message' => 'PDF-filen är för stor för automatisk analys. Lägg in webbtext och snabböversikt manuellt.');
        }

        $text = (string) apply_filters('ssf_stadgar_pdf_text', '', $file, $attachment_id);
        if (! $text) {
            $text = $this->extract_basic_pdf_text($file);
        }

        $text = $this->normalise_text($text);
        $outline = $this->detect_outline($text);

        if (! $text) {
            return array('text' => '', 'outline' => array(), 'message' => 'PDF-filen kunde inte tolkas automatiskt. Det går fortfarande bra att publicera PDF-filen och skriva webbtexten och snabböversikten manuellt.');
        }

        return array(
            'text'    => $text,
            'outline' => $outline,
            'message' => $outline ? 'En preliminär snabböversikt har skapats. Kontrollera den före publicering.' : 'Text hittades, men inga paragraf-rubriker kunde identifieras automatiskt.',
        );
    }

    private function extract_basic_pdf_text(string $file): string
    {
        $pdf = file_get_contents($file);
        if (! is_string($pdf)) {
            return '';
        }

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
        $text = '';
        foreach ($matches[1] ?? array() as $stream) {
            $decoded = @zlib_decode($stream);
            $content = is_string($decoded) ? $decoded : $stream;
            $text .= "\n" . $this->extract_text_showing_operators($content);
        }

        return $text;
    }

    private function extract_text_showing_operators(string $content): string
    {
        $strings = array();
        preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $content, $matches);
        foreach ($matches[0] ?? array() as $match) {
            if (preg_match('/^\((.*)\)\s*Tj/s', $match, $parts)) {
                $strings[] = $this->decode_pdf_string($parts[1]);
            }
        }

        preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $array_matches);
        foreach ($array_matches[1] ?? array() as $array_content) {
            preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $array_content, $items);
            foreach ($items[0] ?? array() as $item) {
                $strings[] = $this->decode_pdf_string(substr($item, 1, -1));
            }
        }

        return implode(' ', array_filter($strings));
    }

    private function decode_pdf_string(string $value): string
    {
        $value = preg_replace_callback(
            '/\\\\([0-7]{1,3})/',
            static function (array $matches): string {
                return chr(octdec($matches[1]));
            },
            $value
        );

        return strtr(
            (string) $value,
            array('\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\b' => "\b", '\\f' => "\f", '\\\\' => '\\', '\\(' => '(', '\\)' => ')')
        );
    }

    private function normalise_text(string $text): string
    {
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", (string) $text);
        return trim((string) $text);
    }

    private function detect_outline(string $text): array
    {
        if (! $text) {
            return array();
        }

        preg_match_all('/§\s*\d+\s+[A-ZÅÄÖ][^§\n]{2,100}/u', $text, $matches);
        $raw_items = array_unique(array_map('trim', $matches[0] ?? array()));
        $outline = array();
        $seen = array();

        foreach ($raw_items as $title) {
            $title = preg_replace('/\s{2,}.*/u', '', $title);
            $title = sanitize_text_field((string) $title);
            $anchor = sanitize_title($title);
            if ($title && $anchor && ! isset($seen[$anchor])) {
                $outline[] = array('title' => $title, 'anchor' => $anchor);
                $seen[$anchor] = true;
            }
        }

        return $outline;
    }
}
