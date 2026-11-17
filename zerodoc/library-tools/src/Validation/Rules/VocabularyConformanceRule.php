<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * 06 Appendix B - "Any element needing one of these MUST reference the
 * vocabulary rather than restating its options."
 *
 * This is the rule that prevents per-element scale drift:
 * element 18 (Previous injections) carries "helped significantly" and element 19
 * (Previous chiropractic) does not, because each retyped the scale by hand.
 * Referencing `response_to_treatment` makes that divergence impossible.
 *
 * What it reports:
 *   - an element whose own option list is substantially a vocabulary       error
 *     (named, with the exact options it is missing or has added)
 *   - an element referencing a vocabulary that does not exist              error
 *   - a plan-option parameter restating a vocabulary                       error
 *   - a diagnosis set with laterality_param restating sides as items       warning
 */
final class VocabularyConformanceRule implements Rule
{
    public function code(): string
    {
        return 'VOCAB';
    }

    public function describes(): string
    {
        return 'Elements must reference Appendix B vocabularies, not restate them (06 Appendix B)';
    }

    public function check(Library $library, Context $context): array
    {
        $vocab  = $context->vocabularies;
        $issues = [];

        foreach ($library->templates as $template) {
            $entry = Context::describe($template);
            foreach ($template['elements'] as $element) {
                $locus = "element \"{$element['label']}\"";

                if ($element['vocabulary'] !== null) {
                    if (!$vocab->has($element['vocabulary'])) {
                        $issues[] = Issue::error(
                            $this->code(), $entry, $locus,
                            "References vocabulary \"{$element['vocabulary']}\", which is not defined.",
                            'Use one of: ' . implode(', ', $vocab->names())
                            . '. A practice-local list can be declared under the '
                            . 'file\'s "vocabularies:" key.',
                            $template['_file']
                        );
                    }
                    continue;
                }

                if ($element['options'] === []) {
                    continue;
                }
                $labels = array_column($element['options'], 'label');
                $drift  = $vocab->matchDrift($labels);
                if ($drift === null) {
                    continue;
                }
                [$name, $coverage, $missing] = $drift;
                $detail = $missing === []
                    ? 'It currently restates the vocabulary exactly, which will drift the moment either copy is edited.'
                    : 'It is missing: "' . implode('", "', $missing) . '".';
                $issues[] = Issue::error(
                    $this->code(), $entry, $locus,
                    sprintf(
                        'This element retypes the shared vocabulary "%s" (%d%% of it). %s',
                        $name, (int) round($coverage * 100), $detail
                    ),
                    "Replace the \"options:\" list with \"vocabulary: {$name}\". "
                    . 'Appendix B lists are defined once centrally; an element that '
                    . 'retypes one is how two elements end up with different scales.',
                    $template['_file']
                );
            }
        }

        foreach ($library->planGroups as $group) {
            $entry = Context::describe($group);
            foreach ($group['options'] as $option) {
                foreach ($option['parameters'] as $param) {
                    $locus = "plan option \"{$option['label']}\" / parameter \"{$param['key']}\"";
                    if ($param['vocabulary'] !== null) {
                        if (!$vocab->has($param['vocabulary'])) {
                            $issues[] = Issue::error(
                                $this->code(), $entry, $locus,
                                "References vocabulary \"{$param['vocabulary']}\", which is not defined.",
                                'Use one of: ' . implode(', ', $vocab->names()) . '.',
                                $group['_file']
                            );
                        }
                        continue;
                    }
                    if ($param['options'] === []) {
                        continue;
                    }
                    $drift = $vocab->matchDrift($param['options']);
                    if ($drift === null) {
                        continue;
                    }
                    $issues[] = Issue::error(
                        $this->code(), $entry, $locus,
                        "This parameter retypes the shared vocabulary \"{$drift[0]}\".",
                        "Replace \"options:\" with \"vocabulary: {$drift[0]}\".",
                        $group['_file']
                    );
                }
            }
        }

        foreach ($library->diagnosisSets as $set) {
            if (!$set['laterality_param']) {
                continue;
            }
            foreach ($set['items'] as $item) {
                if (in_array($item['laterality'], ['right', 'left'], true)) {
                    $issues[] = Issue::warning(
                        $this->code(), Context::describe($set),
                        "item \"{$item['display_name']}\"",
                        'The set carries "laterality_param: true", so the side comes '
                        . "from the selector, but this item hard-codes \"{$item['laterality']}\".",
                        'Set the item laterality to "n/a" and let the set-level '
                        . 'selector apply the side to every code ticked (08 §12.3.2).',
                        $set['_file']
                    );
                }
            }
        }

        return $issues;
    }
}
