<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation;

use ZeroDoc\LibraryTools\Model\Library;

/**
 * The shared option lists from 06 Appendix B, verbatim.
 *
 * Appendix B is a MUST: "Any element needing one of these MUST reference the
 * vocabulary rather than restating its options." These arrays are therefore both
 * the expansion source for the importer and the detector for restatement.
 *
 * WP-00 seeds these as reference data. This file is a transcription-time copy so
 * the validators run with no database; if the seeded data and this file diverge,
 * the seeded data wins and this file is updated to match.
 */
final class Vocabularies
{
    /** @var array<string,list<string>> */
    public const APPENDIX_B = [
        'response_to_treatment' => [
            'did not help', 'helped a little', 'helped temporarily',
            'helped significantly', 'aggravated symptoms',
        ],
        'laterality' => ['right', 'left', 'bilateral', 'n/a'],
        'severity_scale' => [
            '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10',
        ],
        'frequency' => ['constant', 'intermittent', 'nocturnal'],
        'onset'     => ['sudden', 'gradual', 'chronic'],
        'pain_quality' => [
            'throbbing', 'tightness', 'numbness', 'burning', 'aching',
            'cramping', 'sharp', 'dull', 'tingling',
        ],
        'reflex_grade' => ['absent', '1+', '2+', '3+', '4+'],
        'motor_grade'  => ['0/5', '1/5', '2/5', '3/5', '4/5', '5/5'],
        'sensation'    => ['normal', 'decreased', 'absent', 'hyperesthetic'],
        'special_test_result' => ['positive', 'negative', 'not performed'],
        'lumbar_levels'   => ['T12', 'L1', 'L2', 'L3', 'L4', 'L5', 'S1', 'S2'],
        'cervical_levels' => ['C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8'],
    ];

    /**
     * Polarity of individual vocabulary members, where the vocabulary carries
     * one. 06 §1.3: polarity is derived from the option definition, never
     * guessed from wording - so it is declared here once rather than retyped per
     * element.
     *
     * @var array<string,array<string,string>>
     */
    public const POLARITY = [
        'response_to_treatment' => [
            'did not help'        => 'negative',
            'aggravated symptoms' => 'negative',
            'helped a little'      => 'neutral',
            'helped temporarily'   => 'neutral',
            'helped significantly' => 'positive',
        ],
        'special_test_result' => [
            'positive'      => 'positive',
            'negative'      => 'negative',
            'not performed' => 'neutral',
        ],
        'sensation' => [
            'normal'        => 'negative',
            'decreased'     => 'positive',
            'absent'        => 'positive',
            'hyperesthetic' => 'positive',
        ],
        'reflex_grade' => [
            'absent' => 'positive',
            '2+'     => 'negative',
        ],
        'motor_grade' => [
            '5/5' => 'negative',
            '0/5' => 'positive',
            '1/5' => 'positive',
            '2/5' => 'positive',
            '3/5' => 'positive',
            '4/5' => 'positive',
        ],
    ];

    /** @var array<string,list<string>> */
    private array $all;

    public function __construct(Library $library = null)
    {
        $this->all = self::APPENDIX_B;
        foreach ($library?->localVocabularies ?? [] as $name => $opts) {
            $this->all[$name] = $opts;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->all[$name]);
    }

    /** @return list<string> */
    public function options(string $name): array
    {
        return $this->all[$name] ?? [];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all);
    }

    public function polarityOf(string $vocabulary, string $option): string
    {
        $map = self::POLARITY[$vocabulary] ?? [];
        return $map[strtolower(trim($option))] ?? 'neutral';
    }

    /**
     * Returns the name of the Appendix B vocabulary an element's own option list
     * duplicates, or null. "Duplicates" is deliberately generous: an element
     * that reproduces most of a vocabulary is drifting from it, and that drift
     * is precisely how one element ends up without "helped significantly" while
     * its neighbour has it.
     *
     * @param list<string> $optionLabels
     * @return array{0:string,1:float,2:list<string>}|null  [vocabulary, overlap, missing]
     */
    public function matchDrift(array $optionLabels, float $threshold = 0.6): ?array
    {
        $norm = array_map(self::norm(...), $optionLabels);
        $norm = array_values(array_filter($norm, static fn ($s) => $s !== ''));
        if (count($norm) < 2) {
            return null;
        }
        $best = null;
        foreach ($this->all as $name => $vocabOptions) {
            // Numeric/level vocabularies are matched exactly, not fuzzily; a
            // three-item subset of lumbar_levels is a legitimate narrowing.
            $vocab = array_map(self::norm(...), $vocabOptions);
            $hit   = count(array_intersect($norm, $vocab));
            if ($hit === 0) {
                continue;
            }
            $coverageOfVocab   = $hit / max(1, count($vocab));
            $coverageOfElement = $hit / max(1, count($norm));
            // Drift signature: the element's list is substantially this
            // vocabulary (most of its own options are vocabulary members) and it
            // covers a meaningful share of the vocabulary.
            if ($coverageOfElement < 0.75 || $coverageOfVocab < $threshold) {
                continue;
            }
            $missing = [];
            foreach ($vocabOptions as $vo) {
                if (!in_array(self::norm($vo), $norm, true)) {
                    $missing[] = $vo;
                }
            }
            $score = $coverageOfVocab + $coverageOfElement;
            if ($best === null || $score > $best[3]) {
                $best = [$name, $coverageOfVocab, $missing, $score];
            }
        }
        return $best === null ? null : [$best[0], $best[1], $best[2]];
    }

    public static function norm(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s, " \t.;,");
    }
}
