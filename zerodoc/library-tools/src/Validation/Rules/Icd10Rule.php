<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * ICD-10 format and specificity sanity check.
 *
 * Spec 06 Appendix A item 4 is the one this rule exists for: a problem list carried
 * `M54.16` (lumbar) while the order set attached `M54.17` (lumbosacral). 06 §4.3
 * makes it a MUST: "Diagnosis codes on a set MUST match the problem list
 * specificity." 08 §12.3.1 explains why it happened - options attached per code
 * instead of per plan group - and the plan-group check below enforces that fix.
 *
 * Checks:
 *   1. format              - letter + 2 digits, optional "." + up to 4 chars    error
 *   2. category-only code  - 3 characters where the family subdivides           warning
 *   3. unspecified code    - an "unspecified" code where a side is declared     error
 *   4. side disagreement   - code encodes one side, laterality says another     error
 *   5. side not encodable  - laterality on a code whose family has no side       warning
 *   6. family mismatch     - a set and the order set it links to carry different
 *                            subdivisions of the same category (M54.16/M54.17)  error
 *   7. no plan group       - a diagnosis-set code no plan group covers          warning
 *   8. duplicate within a set                                                   warning
 *   9. unknown code        - format fine, not in the reference file             warning
 */
final class Icd10Rule implements Rule
{
    private const FORMAT = '/^[A-TV-Z][0-9][0-9AB](?:\.[0-9A-Z]{1,4})?$/';

    /** @var array<string,array{description:string,flags:list<string>}> */
    private array $reference = [];

    public function __construct(private readonly string $dataDir = '')
    {
    }

    public function code(): string
    {
        return 'ICD10';
    }

    public function describes(): string
    {
        return 'ICD-10 format, specificity and cross-set code agreement (06 §4.3)';
    }

    public function check(Library $library, Context $context): array
    {
        $this->loadReference($context->dataDir);
        $issues = [];

        // --------------------------------------------------- per-code checks
        foreach ($library->diagnosisSets as $set) {
            $seen = [];
            foreach ($set['items'] as $item) {
                $where = "item \"{$item['display_name']}\" ({$item['icd10_code']})";
                $issues = array_merge($issues, $this->checkCode(
                    $item['icd10_code'], $item['laterality'],
                    Context::describe($set), $where, $set['_file'],
                    $set['laterality_param']
                ));
                $key = $item['icd10_code'] . '|' . $item['laterality'];
                if (isset($seen[$key])) {
                    $issues[] = Issue::warning(
                        $this->code(), Context::describe($set), $where,
                        "Code {$item['icd10_code']} appears twice in this set with the same laterality.",
                        'Remove the duplicate. In a pick list a repeated candidate is '
                        . 'just two identical checkboxes.',
                        $set['_file']
                    );
                }
                $seen[$key] = true;
            }
        }

        foreach ($library->orderSets as $set) {
            foreach ($set['items'] as $item) {
                if ($item['item_type'] !== 'diagnosis') {
                    continue;
                }
                $issues = array_merge($issues, $this->checkCode(
                    strtoupper($item['reference']), $item['laterality'],
                    Context::describe($set), "diagnosis item \"{$item['reference']}\"",
                    $set['_file'], false
                ));
            }
        }

        // A plan group is a code-to-plans mapping, so it SHOULD list the family's
        // unspecified code alongside the specific ones - that is how one group
        // serves every code meaning the same problem. Only format and side
        // disagreements are checked here.
        foreach ($library->planGroups as $group) {
            foreach ($group['codes'] as $code) {
                $issues = array_merge($issues, $this->checkCode(
                    $code, 'n/a', Context::describe($group), "code {$code}",
                    $group['_file'], false, true
                ));
            }
        }

        // ------------------------------------- cross-set agreement (item 4)
        $issues = array_merge($issues, $this->checkLinkedSets($library));
        $issues = array_merge($issues, $this->checkPlanGroupCoverage($library));

        return $issues;
    }

    /** @return list<Issue> */
    private function checkCode(
        string $code, string $laterality, string $entry, string $where,
        string $file, bool $lateralityParam, bool $isMapping = false
    ): array {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return [];
        }
        $issues = [];

        if (preg_match(self::FORMAT, $code) !== 1) {
            return [Issue::error(
                $this->code(), $entry, $where,
                "\"{$code}\" is not a valid ICD-10-CM code.",
                'The shape is a letter, two digits, then optionally a dot and up to '
                . 'four more characters: M54.16, G56.01, I10. Check for a missing dot, '
                . 'a letter O typed for a zero, or a trailing space.',
                $file
            )];
        }

        // A letter O typed where a zero belongs is legal-looking - ICD-10-CM does
        // use letters after the dot - so it survives the format check and lands
        // as a code that does not exist.
        if (!isset($this->reference[$code]) && str_contains($code, 'O')) {
            $asZero = str_replace('O', '0', $code);
            if (isset($this->reference[$asZero])) {
                return [Issue::error(
                    $this->code(), $entry, $where,
                    "\"{$code}\" is not a real code, but \"{$asZero}\" is ({$this->reference[$asZero]['description']}). "
                    . 'The letter O has been typed where a zero belongs.',
                    "Change it to {$asZero}.",
                    $file
                )];
            }
        }

        if (!str_contains($code, '.') && strlen($code) === 3 && $this->familySubdivides($code)) {
            $issues[] = Issue::warning(
                $this->code(), $entry, $where,
                "\"{$code}\" is a 3-character category. Codes in this family subdivide further, "
                . 'so a category-level code is usually not billable.',
                'Transcribe the specific code the source used, including the digits '
                . 'after the dot.',
                $file
            );
        }

        $ref = $this->reference[$code] ?? null;
        if ($ref === null && $issues !== []) {
            // Already reported as a category-level code; one message is enough.
            return $issues;
        }
        if ($ref === null) {
            $issues[] = Issue::warning(
                $this->code(), $entry, $where,
                "\"{$code}\" is well-formed but is not in data/icd10-reference.txt, "
                . 'so its specificity and side could not be verified.',
                'Add the code to data/icd10-reference.txt with its description and '
                . 'side flag once you have confirmed it against the code book. The '
                . 'reference file is the practice\'s own list, not a full ICD-10 release.',
                $file
            );
            return $issues;
        }

        $declaredSide = $laterality === 'n/a' ? null : $laterality;
        $codeSide = $this->sideOf($ref['flags']);
        $isUnspecified = in_array('unspecified', $ref['flags'], true);

        if ($isUnspecified && ($declaredSide !== null || $lateralityParam)) {
            $suggest = $this->siblingForSide($code, $declaredSide ?? 'right');
            $issues[] = Issue::error(
                $this->code(), $entry, $where,
                sprintf(
                    '%s is the "unspecified" code (%s) but %s.',
                    $code, $ref['description'],
                    $declaredSide !== null
                        ? "this entry declares laterality \"{$declaredSide}\""
                        : 'the set applies a side at selection time'
                ),
                $suggest !== []
                    ? 'In this family the side is part of the code, so a side selector '
                      . 'cannot add it. Transcribe the side-specific codes instead: '
                      . implode(', ', $suggest) . '.'
                    : 'Use the side-specific code from this family rather than the '
                      . 'unspecified one; an unspecified code is a denial risk and '
                      . 'loses the information the exam captured.',
                $file
            );
        } elseif ($isUnspecified && !$isMapping) {
            $issues[] = Issue::warning(
                $this->code(), $entry, $where,
                "{$code} is an \"unspecified\" code ({$ref['description']}).",
                'Confirm the source really used the unspecified code. If the side or '
                . 'the level is known, the specific code is the right one.',
                $file
            );
        }

        if ($codeSide !== null && $codeSide !== 'none' && $declaredSide !== null && $codeSide !== $declaredSide) {
            $issues[] = Issue::error(
                $this->code(), $entry, $where,
                sprintf(
                    '%s is a %s-sided code (%s) but this entry declares laterality "%s".',
                    $code, $codeSide, $ref['description'], $declaredSide
                ),
                'One of the two is wrong. The code wins on the claim, so check the '
                . 'source and correct whichever was mistyped.',
                $file
            );
        }

        if ($codeSide === 'none' && $declaredSide !== null) {
            $issues[] = Issue::warning(
                $this->code(), $entry, $where,
                sprintf(
                    '%s does not encode a side (%s) but this entry declares laterality "%s".',
                    $code, $ref['description'], $declaredSide
                ),
                'The laterality will render and document correctly but will not reach '
                . 'the claim through the code. Keep it if that is intended; drop it to '
                . '"n/a" if it was copied in by habit.',
                $file
            );
        }

        if ($codeSide !== null && $codeSide !== 'none' && $lateralityParam) {
            $issues[] = Issue::error(
                $this->code(), $entry, $where,
                sprintf(
                    'The set carries "laterality_param: true", but %s already encodes '
                    . 'the %s side in the code itself.',
                    $code, $codeSide
                ),
                'A side selector cannot change the code. Either list both side-specific '
                . 'codes as separate items with "laterality_param: false", or hold the '
                . 'family\'s unspecified stem and resolve the code at selection time - '
                . 'which needs a schema field that does not exist today (see NOTES.md).',
                $file
            );
        }

        return $issues;
    }

    /** Appendix A item 4: a set and its linked order set disagreeing on the code. */
    private function checkLinkedSets(Library $library): array
    {
        $issues = [];
        $orderSets = [];
        foreach ($library->orderSets as $o) {
            $orderSets[$this->canonical($o['code'])] = $o;
        }

        foreach ($library->diagnosisSets as $set) {
            foreach ($set['items'] as $item) {
                if ($item['linked_order_set'] === null) {
                    continue;
                }
                $target = $orderSets[$this->canonical($item['linked_order_set'])] ?? null;
                if ($target === null) {
                    $issues[] = Issue::error(
                        $this->code(), Context::describe($set),
                        "item \"{$item['display_name']}\"",
                        "Links order set \"{$item['linked_order_set']}\", which is not in this library.",
                        'Transcribe the order set, or correct the code. A link to a '
                        . 'missing set fails at import, not at use.',
                        $set['_file']
                    );
                    continue;
                }
                foreach ($target['items'] as $oItem) {
                    if ($oItem['item_type'] !== 'diagnosis') {
                        continue;
                    }
                    $theirs = strtoupper($oItem['reference']);
                    $ours   = $item['icd10_code'];
                    if ($theirs === $ours) {
                        continue;
                    }
                    if ($this->sameCategory($ours, $theirs)) {
                        $issues[] = Issue::error(
                            $this->code(), Context::describe($set),
                            "item \"{$item['display_name']}\"",
                            sprintf(
                                'This item carries %s (%s) but the order set it links to, '
                                . '%s "%s", attaches %s (%s). Same category, different specificity.',
                                $ours, $this->describeCode($ours),
                                $target['code'], $target['name'],
                                $theirs, $this->describeCode($theirs)
                            ),
                            'Pick one code and use it in both places. This is the '
                            . 'M54.16-versus-M54.17 defect: the problem list and the '
                            . 'attached set named different regions for the same problem.',
                            $set['_file']
                        );
                    }
                }
            }
        }
        return $issues;
    }

    /**
     * 08 §12.3.1: a diagnosis reached from a set should land on a plan group, or
     * the provider gets a code with no plan options under it.
     */
    private function checkPlanGroupCoverage(Library $library): array
    {
        if ($library->planGroups === []) {
            return [];
        }
        $covered = [];
        foreach ($library->planGroups as $g) {
            foreach ($g['codes'] as $c) {
                $covered[$c] = $g['name'];
            }
        }
        $issues = [];
        foreach ($library->diagnosisSets as $set) {
            foreach ($set['items'] as $item) {
                if (isset($covered[$item['icd10_code']])) {
                    continue;
                }
                $issues[] = Issue::warning(
                    $this->code(), Context::describe($set),
                    "item \"{$item['display_name']}\" ({$item['icd10_code']})",
                    'No plan group lists this code, so selecting it in the assessment '
                    . 'tree shows no plan options.',
                    'Add the code to the plan group that already serves this problem - '
                    . 'one group per problem, every code that means it (08 §12.3.1). '
                    . 'Do not create a new group per code.',
                    $set['_file']
                );
            }
        }
        return $issues;
    }

    // ------------------------------------------------------------- reference

    private function loadReference(string $dataDir): void
    {
        if ($this->reference !== []) {
            return;
        }
        $dir  = $this->dataDir !== '' ? $this->dataDir : $dataDir;
        $path = rtrim($dir, '/') . '/icd10-reference.txt';
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $code  = strtoupper($parts[0] ?? '');
            if ($code === '') {
                continue;
            }
            $flags = isset($parts[2]) && $parts[2] !== ''
                ? array_map('trim', explode(',', strtolower($parts[2])))
                : [];
            $this->reference[$code] = [
                'description' => $parts[1] ?? '',
                'flags'       => $flags,
            ];
        }
    }

    /** @param list<string> $flags */
    private function sideOf(array $flags): ?string
    {
        foreach ($flags as $f) {
            if (str_starts_with($f, 'side=')) {
                return substr($f, 5);
            }
        }
        return null;
    }

    /** @return list<string> */
    private function siblingForSide(string $unspecifiedCode, string $side): array
    {
        $stem = substr($unspecifiedCode, 0, -1);
        $out  = [];
        foreach ($this->reference as $code => $ref) {
            if ($code === $unspecifiedCode || !str_starts_with($code, $stem)) {
                continue;
            }
            if (strlen($code) !== strlen($unspecifiedCode)) {
                continue;
            }
            $s = $this->sideOf($ref['flags']);
            if ($s === 'right' || $s === 'left' || $s === 'bilateral') {
                $out[] = "{$code} ({$s})";
            }
        }
        sort($out);
        return $out;
    }

    private function describeCode(string $code): string
    {
        return $this->reference[$code]['description'] ?? 'description not in the reference file';
    }

    private function sameCategory(string $a, string $b): bool
    {
        return substr($a, 0, 3) === substr($b, 0, 3) && $a !== $b;
    }

    /** Families whose 3-character category is never the whole code. */
    private function familySubdivides(string $category): bool
    {
        foreach (array_keys($this->reference) as $code) {
            if (str_starts_with($code, $category) && strlen($code) > 3) {
                return true;
            }
        }
        // M, G, S chapters subdivide almost without exception.
        return in_array($category[0], ['M', 'G', 'S'], true);
    }

    private function canonical(string $code): string
    {
        $c = strtoupper(trim($code));
        return preg_match('/^\d+$/', $c) === 1 ? (string) (int) $c : $c;
    }
}
