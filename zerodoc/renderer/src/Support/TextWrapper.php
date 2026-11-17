<?php

declare(strict_types=1);

namespace Aem\Renderer\Support;

/**
 * Line wrapping. Width 0 disables wrapping entirely.
 *
 * $hangingIndent is applied to every continuation line of a logical line.
 */
final class TextWrapper
{
    public static function wrap(string $line, int $width, int $hangingIndent = 0): string
    {
        if ($width <= 0 || $line === '') {
            return $line;
        }

        $indent = str_repeat(' ', max(0, $hangingIndent));
        $words = preg_split('/ +/', trim($line)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $prefix = $lines === [] ? '' : $indent;
            $candidate = $current === '' ? $prefix . $word : $current . ' ' . $word;
            if (mb_strlen($candidate) > $width && $current !== '') {
                $lines[] = $current;
                $current = $indent . $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return implode("\n", $lines);
    }

    /** Inverse of wrap(), for comparing against a reference whose line breaks differ. */
    public static function unwrap(string $text): string
    {
        $text = preg_replace('/\n\s*/', ' ', $text) ?? $text;

        return trim(preg_replace('/ +/', ' ', $text) ?? $text);
    }
}
