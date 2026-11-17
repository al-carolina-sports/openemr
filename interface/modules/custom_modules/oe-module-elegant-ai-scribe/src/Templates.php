<?php

/**
 * Note templates: section keys (DB columns) and the headings the model must use.
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe;

final class Templates
{
    public const ALL = [
        'hp' => [
            'label' => 'History & Physical',
            'form_name' => 'AI Scribe Note - History & Physical',
            'formdir' => 'elegant_ai_scribe',
            'prompt_key' => 'prompt_new_patient',
            'sections' => [
                'chief_complaint' => 'CHIEF COMPLAINT',
                'hpi' => 'HPI',
                'review_of_systems' => 'REVIEW OF SYSTEMS',
                'physical_exam' => 'PHYSICAL EXAM',
                'imaging' => 'IMAGING',
                'assessment' => 'ASSESSMENT',
                'plan' => 'PLAN',
            ],
        ],
        'soap' => [
            'label' => 'SOAP',
            'form_name' => 'AI Scribe Note - SOAP',
            'formdir' => 'elegant_ai_scribe_soap',
            'prompt_key' => 'prompt_soap',
            'sections' => [
                'subjective' => 'SUBJECTIVE',
                'objective' => 'OBJECTIVE',
                'assessment' => 'ASSESSMENT',
                'plan' => 'PLAN',
            ],
        ],
    ];

    /** Every section column, in display order. */
    public const COLUMNS = ['chief_complaint', 'hpi', 'review_of_systems', 'physical_exam', 'imaging', 'subjective', 'objective', 'assessment', 'plan'];

    /** Older template ids (v0.3) mapped to current ones. */
    private const ALIASES = ['new_patient' => 'hp'];

    public static function id(?string $id, string $fallback = 'hp'): string
    {
        $id = self::ALIASES[(string)$id] ?? (string)$id;
        $fallback = self::ALIASES[$fallback] ?? $fallback;
        return isset(self::ALL[$id]) ? $id : (isset(self::ALL[$fallback]) ? $fallback : 'hp');
    }

    /** Template served by an encounter form directory, or null if not ours. */
    public static function forFormdir(string $formdir): ?string
    {
        foreach (self::ALL as $id => $t) {
            if ($t['formdir'] === $formdir) {
                return $id;
            }
        }
        return null;
    }

    /** @return list<string> */
    public static function formdirs(): array
    {
        return array_values(array_map(static fn($t) => $t['formdir'], self::ALL));
    }

    /** Each form directory has its own table, as OpenEMR expects (form_<formdir>). */
    public static function table(string $formdir): string
    {
        if (self::forFormdir($formdir) === null) {
            throw new \InvalidArgumentException('Unknown AI Scribe form');
        }
        return 'form_' . $formdir;
    }

    /** @return array<string, string> key => HEADING */
    public static function sections(string $id): array
    {
        return self::ALL[self::id($id)]['sections'];
    }

    /** Template of a saved row (rows from v0.2 and earlier had no template and were SOAP; v0.3 'new_patient' is H&P). */
    public static function ofRow(array $row): string
    {
        return self::id((string)($row['template'] ?? ''), 'soap');
    }
}
