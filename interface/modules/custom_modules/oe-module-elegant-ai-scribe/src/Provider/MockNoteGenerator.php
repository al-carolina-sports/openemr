<?php

/**
 * Mock note generator: sorts transcript lines into the template's sections by keywords (no AI, no network).
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Provider;

class MockNoteGenerator implements NoteGeneratorInterface
{
    public function id(): string
    {
        return 'mock';
    }

    public function lastUsage(): ?array
    {
        return null;
    }

    public function generate(string $transcript, string $prompt, array $sections): array
    {
        $b = array_fill_keys(array_keys($sections), []);
        $put = static function (array $keys, string $line) use (&$b): bool {
            foreach ($keys as $k) {
                if (array_key_exists($k, $b)) {
                    $b[$k][] = $line;
                    return true;
                }
            }
            return false;
        };
        foreach (preg_split('/\R+/', $transcript) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $l = strtolower($line);
            if (preg_match('/\b(x-?ray|mri|ultrasound|ct scan|imaging)\b/', $l) && !preg_match('/\b(we\'ll get|order)\b/', $l)) {
                $put(['imaging', 'objective'], $line);
            } elseif (preg_match('/\b(exam|tender|effusion|range of motion|ligaments|blood pressure|pulse|labs? (from|show))\b/', $l)) {
                $put(['physical_exam', 'objective'], $line);
            } elseif (preg_match('/\b(looks like|consistent with|fits with|diagnos|likely)\b/', $l)) {
                $put(['assessment'], $line);
            } elseif (preg_match('/\b(follow.?up|let\'s|we\'ll|start|order|therapy|as needed)\b/', $l)) {
                $put(['plan'], $line);
            } else {
                if (array_key_exists('chief_complaint', $b) && $b['chief_complaint'] === [] && preg_match('/\b(pain|for about|i\'ve had|since)\b/', $l)) {
                    $b['chief_complaint'][] = $line;
                }
                $put(['hpi', 'subjective'], $line);
            }
        }
        $out = [];
        foreach ($b as $k => $lines) {
            $out[$k] = $lines === [] ? 'Not discussed.' : '- ' . implode("\n- ", $lines);
        }
        $first = array_key_first($out);
        $out[$first] = "[MOCK DRAFT - review before saving]\n" . $out[$first];
        return $out;
    }
}
