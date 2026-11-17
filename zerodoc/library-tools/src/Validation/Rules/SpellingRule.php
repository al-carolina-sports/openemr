<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * 06 §3.8 - "spell-check on save".
 *
 * Defect class, seen in transcribed content: `discecotomy` (option text, HPI element 17),
 * `Pain Manangement T-spine` (template name), `Knee OA Left val` (an unfinished
 * saved-finding name).
 *
 * Severity: a misspelling in a NAME is an error - names are what the picker
 * shows and what everything else references, and a typo there outlives the
 * transcription. A misspelling in option text or narrative is a warning, because
 * the checker cannot know every local coinage; the fix for a false positive is a
 * line in data/clinical-words.txt.
 */
final class SpellingRule implements Rule
{
    public function code(): string
    {
        return 'SPELLING';
    }

    public function describes(): string
    {
        return 'Spell check on names, element labels and option text (06 §3.8)';
    }

    public function check(Library $library, Context $context): array
    {
        $dict   = $context->dictionary();
        $issues = [];

        foreach ($library->allEntries() as $entry) {
            $name  = (string) ($entry['name'] ?? '');
            $label = Context::describe($entry);
            foreach ($dict->check($name) as $bad) {
                $issues[] = $this->issue(
                    true, $label, 'name', $bad, $entry['_file'],
                    "The name of this " . Context::typeLabel($entry) . ' contains "'
                    . $bad['word'] . '", which is not a word in the dictionary.'
                );
            }
        }

        foreach ($library->templates as $template) {
            $label = Context::describe($template);
            foreach ($template['elements'] as $element) {
                foreach ($dict->check($element['label']) as $bad) {
                    $issues[] = $this->issue(
                        true, $label, "element \"{$element['label']}\"", $bad, $template['_file'],
                        'Element label contains "' . $bad['word'] . '".'
                    );
                }
                foreach ($element['options'] as $option) {
                    foreach ($dict->check($option['label']) as $bad) {
                        $issues[] = $this->issue(
                            false, $label,
                            "element \"{$element['label']}\" / option \"{$option['label']}\"",
                            $bad, $template['_file'],
                            'Option text contains "' . $bad['word'] . '".'
                        );
                    }
                }
            }
        }

        foreach ($library->diagnosisSets as $set) {
            $label = Context::describe($set);
            foreach ($set['items'] as $item) {
                foreach ($dict->check((string) $item['display_name']) as $bad) {
                    $issues[] = $this->issue(
                        false, $label, "item \"{$item['display_name']}\"", $bad, $set['_file'],
                        'Diagnosis display name contains "' . $bad['word'] . '".'
                    );
                }
                foreach ($dict->check((string) ($item['default_plan_text'] ?? '')) as $bad) {
                    $issues[] = $this->issue(
                        false, $label, "item \"{$item['display_name']}\" / default_plan_text",
                        $bad, $set['_file'],
                        'Plan text contains "' . $bad['word'] . '".'
                    );
                }
            }
        }

        foreach ($library->planGroups as $group) {
            $label = Context::describe($group);
            foreach ($group['options'] as $option) {
                foreach ($dict->check($option['label']) as $bad) {
                    $issues[] = $this->issue(
                        true, $label, "plan option \"{$option['label']}\"", $bad, $group['_file'],
                        'Plan option label contains "' . $bad['word'] . '".'
                    );
                }
                foreach ($dict->check((string) ($option['narrative_template'] ?? '')) as $bad) {
                    $issues[] = $this->issue(
                        false, $label, "plan option \"{$option['label']}\" / narrative_template",
                        $bad, $group['_file'],
                        'Narrative text contains "' . $bad['word'] . '".'
                    );
                }
            }
        }

        foreach ($library->orderSets as $set) {
            $label = Context::describe($set);
            foreach ($set['items'] as $item) {
                foreach ($dict->check((string) ($item['display_name'] ?? '')) as $bad) {
                    $issues[] = $this->issue(
                        false, $label, "item \"{$item['reference']}\"", $bad, $set['_file'],
                        'Item display name contains "' . $bad['word'] . '".'
                    );
                }
            }
        }

        return $issues;
    }

    /** @param array{word:string,suggestion:string|null} $bad */
    private function issue(bool $isName, string $entry, string $locus, array $bad, string $file, string $message): Issue
    {
        $fix = $bad['suggestion'] !== null
            ? "Did you mean \"{$bad['suggestion']}\"? If \"{$bad['word']}\" is correct, add it to data/clinical-words.txt."
            : "If \"{$bad['word']}\" is correct, add it to data/clinical-words.txt; otherwise correct the spelling.";
        return $isName
            ? Issue::error($this->code(), $entry, $locus, $message, $fix, $file)
            : Issue::warning($this->code(), $entry, $locus, $message, $fix, $file);
    }
}
