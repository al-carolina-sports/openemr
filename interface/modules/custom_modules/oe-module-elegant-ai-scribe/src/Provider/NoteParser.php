<?php

/**
 * Splits model output into the template's sections by their headings (JSON also accepted).
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Provider;

final class NoteParser
{
    private const ALIASES = [
        'HISTORY OF PRESENT ILLNESS' => 'HPI',
        'ROS' => 'REVIEW OF SYSTEMS',
        'PHYSICAL EXAMINATION' => 'PHYSICAL EXAM',
        'EXAM' => 'PHYSICAL EXAM',
        'ASSESSMENT AND PLAN' => 'ASSESSMENT',
        'IMPRESSION' => 'ASSESSMENT',
        'CC' => 'CHIEF COMPLAINT',
    ];

    /**
     * @param array<string, string> $sections key => HEADING
     * @return array<string, string>
     */
    public static function parse(string $text, array $sections): array
    {
        $out = array_fill_keys(array_keys($sections), '');
        $byHeading = array_flip($sections);
        $trim = trim($text);
        if ($trim !== '' && $trim[0] === '{' && is_array($data = json_decode($trim, true))) {
            foreach ($sections as $k => $h) {
                $v = $data[$k] ?? $data[$h] ?? $data[ucfirst($k)] ?? '';
                $out[$k] = is_array($v) ? implode("\n", array_map('strval', $v)) : trim((string)$v);
            }
            return $out;
        }
        $names = array_unique(array_merge(array_values($sections), array_keys(self::ALIASES)));
        usort($names, static fn($a, $b) => strlen($b) <=> strlen($a));
        $alt = implode('|', array_map(static fn($n) => preg_quote($n, '/'), $names));
        // A heading line: optional markdown (#, **), the heading, optional colon; text may follow on the same line.
        $re = '/^[ \t]*(?:#+[ \t]*)?(?:\*\*)?[ \t]*(' . $alt . ')[ \t]*(?:\*\*)?[ \t]*:?[ \t]*(?:\*\*)?[ \t]*/im';
        $parts = preg_split($re, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) < 3) {
            $first = array_key_first($sections);
            $out[$first] = $trim;
            return $out;
        }
        for ($i = 1; $i < count($parts); $i += 2) {
            $h = strtoupper(trim($parts[$i]));
            $h = self::ALIASES[$h] ?? $h;
            $key = $byHeading[$h] ?? null;
            if ($key === null) {
                continue;
            }
            $body = trim($parts[$i + 1] ?? '');
            $out[$key] = $out[$key] === '' ? $body : $out[$key] . "\n" . $body;
        }
        return $out;
    }
}
