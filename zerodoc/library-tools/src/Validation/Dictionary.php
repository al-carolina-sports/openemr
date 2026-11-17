<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation;

/**
 * Spell checker for labels and option text.
 *
 * Two word lists, both plain text, both editable without touching code:
 *   data/english-words.txt  - common English base forms
 *   data/clinical-words.txt - the clinical supplement (and multi-word phrases)
 *
 * A third list can be supplied per-library as data/local-words.txt for practice
 * names, provider names and local coinages.
 *
 * The checker is deliberately conservative. It only reports a word when it is
 * absent from every list AND a close, real alternative exists or the word is
 * unusual enough to be worth a look. That keeps it usable: a checker that cries
 * wolf on every clinical term gets switched off, and then "discecotomy" ships.
 */
final class Dictionary
{
    /** @var array<string,true> */
    private array $words = [];
    /** @var list<string> */
    private array $phrases = [];
    /** @var array<string,string> */
    private array $cache = [];

    /** @var array<string,string> known-wrong spellings worth an explicit message */
    public const COMMON_TYPOS = [
        'discecotomy'   => 'discectomy',
        'discectomoy'   => 'discectomy',
        'manangement'   => 'management',
        'managment'     => 'management',
        'laminecotmy'   => 'laminectomy',
        'tendonitis'    => 'tendinitis',
        'radiculapathy' => 'radiculopathy',
        'paresthsia'    => 'paresthesia',
        'bilaterial'    => 'bilateral',
        'assesment'     => 'assessment',
        'assesments'    => 'assessments',
        'cervcial'      => 'cervical',
        'lumabr'        => 'lumbar',
        'injectoin'     => 'injection',
        'sacroilliac'   => 'sacroiliac',
    ];

    /**
     * The base word list holds stems, not every inflection: it has "place" but
     * not "displacement", "view" but not "reviewed". These are the affixes the
     * checker strips to find the stem. One prefix and one suffix per word, which
     * covers ordinary clinical prose without making a typo look like a word.
     */
    private const PREFIXES = [
        're', 'un', 'in', 'im', 'il', 'ir', 'dis', 'mis', 'non', 'pre', 'post',
        'anti', 'sub', 'super', 'inter', 'intra', 'trans', 'over', 'under',
        'out', 'co', 'de', 'semi', 'multi', 'mid', 'bi', 'uni', 'poly', 'hypo',
        'hyper', 'peri', 'para', 'retro', 'pro', 'counter',
    ];

    private const SUFFIXES = [
        "'s", 's', 'es', 'ed', 'd', 'ing', 'ly', 'er', 'est', 'ment', 'ments',
        'ness', 'ity', 'ities', 'tion', 'tions', 'sion', 'sions', 'ation',
        'ations', 'al', 'ally', 'ic', 'ical', 'ically', 'able', 'ably', 'ible',
        'ive', 'ively', 'ous', 'ously', 'ful', 'less', 'ish', 'ism', 'ist',
        'ists', 'ize', 'izes', 'ized', 'izing', 'y', 'ance', 'ence', 'ent',
        'ant',
    ];

    public function __construct(private readonly string $dataDir)
    {
        foreach (['english-words.txt', 'common-words.txt', 'clinical-words.txt', 'local-words.txt'] as $f) {
            $path = rtrim($this->dataDir, '/') . '/' . $f;
            if (!is_file($path)) {
                continue;
            }
            $h = fopen($path, 'r');
            if ($h === false) {
                continue;
            }
            while (($line = fgets($h)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $line = strtolower($line);
                if (str_contains($line, ' ')) {
                    $this->phrases[] = $line;
                    foreach (explode(' ', $line) as $part) {
                        $this->words[$part] = true;
                    }
                    continue;
                }
                $this->words[$line] = true;
            }
            fclose($h);
        }
        usort($this->phrases, static fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    public function loaded(): int
    {
        return count($this->words);
    }

    /**
     * @return list<array{word:string,suggestion:string|null}> misspellings in $text
     */
    public function check(string $text): array
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return [];
        }
        $lower = strtolower($text);
        // Remove known multi-word phrases first so their parts are not judged alone.
        foreach ($this->phrases as $p) {
            if (str_contains($lower, $p)) {
                $lower = str_replace($p, ' ', $lower);
            }
        }
        // Strip placeholders, macro variables, units, numbers, punctuation.
        $lower = preg_replace('/@[A-Z0-9\/_]+@/i', ' ', $lower) ?? $lower;
        $lower = preg_replace('/__+/', ' ', $lower) ?? $lower;
        $lower = preg_replace('/\{[^}]*\}/', ' ', $lower) ?? $lower;
        $lower = preg_replace('/\b[a-z]?\d+[a-z0-9\/\.\+-]*\b/', ' ', $lower) ?? $lower;
        $lower = preg_replace("/[^a-z'\-\s]/", ' ', $lower) ?? $lower;

        $out  = [];
        $seen = [];
        foreach (preg_split('/\s+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $word = trim($raw, "'-");
            if ($word === '' || isset($seen[$word])) {
                continue;
            }
            $seen[$word] = true;
            if ($this->known($word)) {
                continue;
            }
            $out[] = ['word' => $word, 'suggestion' => $this->suggest($word)];
        }
        return $out;
    }

    public function known(string $word): bool
    {
        $word = strtolower($word);
        if (isset(self::COMMON_TYPOS[$word])) {
            return false;
        }
        if (strlen($word) <= 2) {
            return true;              // r, l, b, mm, cm, hx, px ...
        }
        if (isset($this->words[$word])) {
            return true;
        }
        // Hyphenated compound: every part known is good enough.
        if (str_contains($word, '-')) {
            $parts = array_filter(explode('-', $word));
            if ($parts !== []) {
                foreach ($parts as $p) {
                    // "pre-visit", "post-lumbar": a bare prefix is a legitimate
                    // part of a hyphenated compound even though it is not a word.
                    if (in_array($p, self::PREFIXES, true)) {
                        continue;
                    }
                    if (!$this->known($p)) {
                        return false;
                    }
                }
                return true;
            }
        }
        // Derived forms: strip at most one suffix, then at most one prefix.
        if ($this->knownWithSuffix($word)) {
            return true;
        }
        foreach (self::PREFIXES as $prefix) {
            if (!str_starts_with($word, $prefix)) {
                continue;
            }
            $stem = substr($word, strlen($prefix));
            if (strlen($stem) < 3) {
                continue;
            }
            if (isset($this->words[$stem]) || $this->knownWithSuffix($stem)) {
                return true;
            }
        }
        return false;
    }

    private function knownWithSuffix(string $word): bool
    {
        foreach (self::SUFFIXES as $suffix) {
            if (!str_ends_with($word, $suffix) || strlen($word) - strlen($suffix) < 3) {
                continue;
            }
            $base = substr($word, 0, -strlen($suffix));
            foreach ($this->baseVariants($base) as $variant) {
                if (isset($this->words[$variant])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<string> spelling changes an affix can cause in the stem */
    private function baseVariants(string $base): array
    {
        $out = [$base, $base . 'e'];
        // doubled consonant: "tapped" -> "tap"
        if (strlen($base) > 2 && $base[-1] === $base[-2]) {
            $out[] = substr($base, 0, -1);
        }
        // "i" for "y": "happier" -> "happy"
        if (str_ends_with($base, 'i')) {
            $out[] = substr($base, 0, -1) . 'y';
        }
        // "-tion"/"-sion" stems: "rotation" -> "rotate", "decision" -> "decide"
        $out[] = $base . 'te';
        $out[] = $base . 'de';
        $out[] = $base . 'ce';
        return $out;
    }

    public function suggest(string $word): ?string
    {
        $word = strtolower($word);
        if (isset(self::COMMON_TYPOS[$word])) {
            return self::COMMON_TYPOS[$word];
        }
        if (isset($this->cache[$word])) {
            return $this->cache[$word] === '' ? null : $this->cache[$word];
        }
        $len   = strlen($word);
        $limit = $len <= 5 ? 1 : ($len <= 9 ? 2 : 3);
        $best  = null;
        $bestD = $limit + 1;
        foreach (array_keys($this->words) as $cand) {
            $cl = strlen($cand);
            if (abs($cl - $len) > $limit || $cand[0] !== $word[0]) {
                continue;
            }
            $d = levenshtein($word, $cand);
            if ($d < $bestD) {
                $bestD = $d;
                $best  = $cand;
                if ($d === 1) {
                    break;
                }
            }
        }
        $this->cache[$word] = $best ?? '';
        return $best;
    }
}
