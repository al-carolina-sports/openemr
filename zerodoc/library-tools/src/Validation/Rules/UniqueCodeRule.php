<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * 06 §4.3 and 07 §11.2.4 - set codes MUST be unique.
 *
 * Defect class: one catalog number naming several sets. `850` named three sets (Lumbar, Lumbar Post-Lami,
 * Lumbar Spondylolisthesis) and `856`/`857`/`858` name two each, so the number
 * stopped being a key. `857 TheWorks Shoulder Left` and `857 TheWorks Shoulder
 * Right` are the same code on duplicate content - which §12.3.2 replaces with
 * one set and a side selector.
 *
 * Diagnosis sets and order sets share one catalog number space, so
 * uniqueness is checked across both and not per type. Two sets with the same
 * number are a collision whichever kind they are.
 *
 * Severity: error. This is acceptance test 9.
 */
final class UniqueCodeRule implements Rule
{
    public function code(): string
    {
        return 'DUP_CODE';
    }

    public function describes(): string
    {
        return 'Set codes must be unique across diagnosis sets and order sets (06 §4.3, test 9)';
    }

    public function check(Library $library, Context $context): array
    {
        $issues = [];
        $byCode = [];
        foreach (array_merge($library->diagnosisSets, $library->orderSets) as $entry) {
            $code = (string) ($entry['code'] ?? '');
            if ($code === '') {
                continue;   // absence is a STRUCTURE error, reported by the loader
            }
            $byCode[$this->canonical($code)][] = $entry;
        }

        foreach ($byCode as $canon => $entries) {
            if (count($entries) < 2) {
                continue;
            }
            $names = [];
            foreach ($entries as $e) {
                $names[] = Context::typeLabel($e) . " \"{$e['name']}\" (" . $e['_file'] . ')';
            }
            $first = $entries[0];
            $sameShape = $this->looksLikeLateralityPair($entries);
            $issues[] = Issue::error(
                $this->code(),
                Context::describe($first),
                "code \"{$first['code']}\"",
                sprintf(
                    'Code %s is used by %d sets: %s.',
                    $first['code'], count($entries), implode('; ', $names)
                ),
                $sameShape
                    ? 'These look like one set split by side. Collapse them into a '
                      . 'single set with "laterality_param: true" and let the side '
                      . 'selector carry right/left/bilateral (08 §12.3.2).'
                    : 'Give each set its own code. The code is the key; reusing it '
                      . 'means neither set can be referenced reliably.',
                $first['_file']
            );
            unset($canon);
        }
        return $issues;
    }

    /** @param list<array<string,mixed>> $entries */
    private function looksLikeLateralityPair(array $entries): bool
    {
        $stems = [];
        $sides = [];
        foreach ($entries as $e) {
            $stems[] = Context::stripSide((string) $e['name']);
            $side = Context::detectSide((string) $e['name']);
            if ($side !== null) {
                $sides[] = $side;
            }
        }
        return count(array_unique($stems)) === 1 && count($sides) === count($entries);
    }

    /** "0857" and "857 " are the same catalog number. */
    private function canonical(string $code): string
    {
        $c = strtoupper(trim($code));
        return preg_match('/^\d+$/', $c) === 1 ? (string) (int) $c : $c;
    }
}
