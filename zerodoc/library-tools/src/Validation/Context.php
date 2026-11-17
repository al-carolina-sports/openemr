<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation;

use ZeroDoc\LibraryTools\Model\Library;

final class Context
{
    public readonly Vocabularies $vocabularies;
    private ?Dictionary $dictionary = null;

    public function __construct(
        public readonly Library $library,
        public readonly string $dataDir,
    ) {
        $this->vocabularies = new Vocabularies($library);
    }

    /** Loaded lazily - the word lists cost ~80k lines to read. */
    public function dictionary(): Dictionary
    {
        return $this->dictionary ??= new Dictionary($this->dataDir);
    }

    // ------------------------------------------------------ shared text tools

    public const SIDE_WORDS = [
        'right' => 'right', 'left' => 'left', 'bilateral' => 'bilateral',
        'rt' => 'right', 'lt' => 'left', 'bilat' => 'bilateral',
        'r' => 'right', 'l' => 'left', 'b' => 'bilateral',
    ];

    /**
     * Strips any laterality word from a label so two sides of a pair can be
     * compared on content alone. "tenderness, right L4" -> "tenderness l4".
     */
    public static function stripSide(string $label): string
    {
        $s = ' ' . strtolower($label) . ' ';
        $s = str_replace(['(', ')', '[', ']', '/', '—', '–', ',', ':'], ' ', $s);
        $patterns = [
            '/\bright\b/', '/\bleft\b/', '/\bbilateral\b/', '/\bbilaterally\b/',
            '/\brt\b/', '/\blt\b/', '/\bbilat\b/',
            '/(?<=\s)r(?=\s)/', '/(?<=\s)l(?=\s)/', '/(?<=\s)b(?=\s)/',
            '/\brle\b/', '/\blle\b/', '/\brue\b/', '/\blue\b/',
        ];
        $s = preg_replace($patterns, ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9\s\-+%]/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * The side a label declares, or null. Conservative: a bare "R"/"L"/"B" only
     * counts as a side when it stands alone as a word or trails the name, which
     * is exactly the observed drift pattern (`SI Joint B`, `Hip Labral
     * Tear R`).
     */
    public static function detectSide(string $label): ?string
    {
        $s = strtolower(trim($label));
        $s = str_replace(['—', '–'], '-', $s);
        if (preg_match('/\b(right|left|bilateral|bilaterally)\b/', $s, $m) === 1) {
            return $m[1] === 'bilaterally' ? 'bilateral' : $m[1];
        }
        if (preg_match('/\b(rt|lt|bilat)\b/', $s, $m) === 1) {
            return self::SIDE_WORDS[$m[1]];
        }
        // trailing or parenthesised single letter
        if (preg_match('/(?:^|[\s\-\(\/])([rlb])(?:[\s\-\)\/\.]|$)/', $s, $m) === 1) {
            return self::SIDE_WORDS[$m[1]];
        }
        return null;
    }

    /** Name reduced to comparable tokens: case, punctuation and stop words gone. */
    public static function normaliseName(string $name): string
    {
        $s = strtolower($name);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        $tokens = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['the', 'a', 'an', 'of', 'and', 'for', 'css', 'pos', 'w', 'with'];
        $tokens = array_values(array_filter($tokens, static fn ($t) => !in_array($t, $stop, true)));
        // Singular and plural of the same word are the same word for naming
        // purposes: "Assessments" and "Assessment / Plan" are the near-duplicate
        // pair this catches.
        $tokens = array_map(static function (string $t): string {
            if (strlen($t) > 3 && str_ends_with($t, 's') && !str_ends_with($t, 'ss')) {
                return substr($t, 0, -1);
            }
            return $t;
        }, $tokens);
        sort($tokens);
        return implode(' ', $tokens);
    }

    public static function typeLabel(array $entry): string
    {
        return match ($entry['_type'] ?? '') {
            'template'       => 'template',
            'saved_finding'  => 'saved finding',
            'diagnosis_set'  => 'diagnosis set',
            'plan_group'     => 'plan group',
            'order_set'      => 'order set',
            default          => 'entry',
        };
    }

    public static function describe(array $entry): string
    {
        $code = $entry['code'] ?? null;
        $name = $entry['name'] ?? '(unnamed)';
        return self::typeLabel($entry) . ' ' . ($code ? "{$code} " : '') . "\"{$name}\"";
    }
}
