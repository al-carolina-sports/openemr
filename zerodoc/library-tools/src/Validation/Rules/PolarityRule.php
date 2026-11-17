<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * Polarity tagging convention - descriptor vs asserted absence.
 *
 * The renderer collapses consecutive NEGATIVE findings into one negated clause.
 * That makes polarity a punctuation decision as much as a colour one:
 *
 *   correct   "no induration, ecchymosis, or swelling; normal alignment"
 *             - three negatives collapse, then the neutral descriptor opens a
 *               second clause
 *   incorrect "no induration, ecchymosis, swelling, or normal alignment"
 *             - "normal alignment" tagged negative collapses into the negated
 *               clause and the sentence now asserts the opposite of the exam
 *
 * So:
 *   - an asserted ABSENCE ("no swelling", "without tenderness", "denies fever")
 *     is polarity: negative
 *   - a normal DESCRIPTOR ("normal alignment", "healthy-appearing", "normal
 *     gait", "good appetite") is polarity: neutral
 *
 * This rule flags both directions of mis-tagging. Warning, not error: there are
 * legitimate exceptions, and the transcriber is the one who can tell. But every
 * instance is surfaced, because the consequence is invisible at entry time - the
 * option looks right in the picker and renders a false sentence in the note.
 */
final class PolarityRule implements Rule
{
    /** Openers that mark an asserted absence. */
    public const NEGATION_MARKERS = [
        'no ', 'no-', 'not ', 'without ', 'denies ', 'denied ', 'negative for ',
        'absence of ', 'absent ', 'free of ', 'no evidence of ', 'nil ',
    ];

    /** Single words that are themselves absence assertions. */
    public const NEGATION_WORDS = [
        'nontender', 'non-tender', 'nontender to palpation', 'nonradiating',
        'non-radiating', 'afebrile', 'anicteric', 'atraumatic', 'nonswollen',
    ];

    /** Openers that mark a normal descriptor - the dangerous mis-tag. */
    public const DESCRIPTOR_MARKERS = [
        'normal', 'normally', 'healthy', 'healthy-appearing', 'well-appearing',
        'good ', 'intact', 'symmetric', 'symmetrical', 'unremarkable',
        'within normal limits', 'wnl', 'full ', 'supple', 'nourished',
        'well-nourished', 'alert', 'oriented', 'physiologic', 'physiological',
        'baseline', 'improving', 'improved', 'resolved', 'stable',
    ];

    public function code(): string
    {
        return 'POLARITY';
    }

    public function describes(): string
    {
        return 'Asserted absences are negative; normal descriptors are neutral (renderer clause rule)';
    }

    public function check(Library $library, Context $context): array
    {
        $issues = [];

        foreach ($library->templates as $template) {
            $entry = Context::describe($template);
            foreach ($template['elements'] as $element) {
                foreach ($element['options'] as $option) {
                    $label    = (string) $option['label'];
                    $polarity = (string) $option['polarity'];
                    $locus    = "element \"{$element['label']}\" / option \"{$label}\"";
                    $negates  = $this->isAbsenceAssertion($label);

                    if ($negates && $polarity !== 'negative') {
                        $issues[] = Issue::warning(
                            $this->code(), $entry, $locus,
                            sprintf(
                                'This option asserts an absence but is tagged "%s". '
                                . 'Asserted absences are what the renderer collapses into '
                                . 'a single negated clause; tagged "%s" it will sit outside '
                                . 'that clause and read as its own statement.',
                                $polarity, $polarity
                            ),
                            'Set "polarity: negative" unless this option genuinely is not '
                            . 'an absence statement.',
                            $template['_file']
                        );
                        continue;
                    }

                    if (!$negates && $polarity === 'negative') {
                        $isDescriptor = $this->isNormalDescriptor($label);
                        $issues[] = Issue::warning(
                            $this->code(), $entry, $locus,
                            $isDescriptor
                                ? sprintf(
                                    'This option is a normal descriptor ("%s") tagged '
                                    . '"negative". Tagged negative it collapses into the '
                                    . 'preceding negated clause, so a line that should read '
                                    . '"... or swelling; %s" instead reads "... swelling, or '
                                    . '%s" - which asserts the opposite of the exam.',
                                    $label, $label, $label
                                )
                                : sprintf(
                                    'This option is tagged "negative" but its text does not '
                                    . 'begin with a negation ("no", "without", "denies", '
                                    . '"negative for"), so it will be folded into a negated '
                                    . 'clause it does not belong in.'
                                ),
                            $isDescriptor
                                ? 'Set "polarity: neutral". A descriptor of normality is not '
                                  . 'an asserted absence; only "no <finding>" phrasing is.'
                                : 'Either set "polarity: neutral", or reword the option as an '
                                  . 'explicit absence ("no ...") if that is what it means.',
                            $template['_file']
                        );
                    }
                }
            }
        }

        return $issues;
    }

    public function isAbsenceAssertion(string $label): bool
    {
        $s = strtolower(ltrim($label, " \t*-\u{2014}\u{2013}("));
        foreach (self::NEGATION_WORDS as $w) {
            if ($s === $w || str_starts_with($s, $w . ' ') || str_starts_with($s, $w . ',')) {
                return true;
            }
        }
        foreach (self::NEGATION_MARKERS as $m) {
            if (str_starts_with($s, $m)) {
                return true;
            }
        }
        return false;
    }

    public function isNormalDescriptor(string $label): bool
    {
        $s = strtolower(trim($label));
        foreach (self::DESCRIPTOR_MARKERS as $m) {
            if (str_starts_with($s, $m)) {
                return true;
            }
        }
        return false;
    }
}
