<?php

declare(strict_types=1);

namespace Aem\Renderer;

use Aem\Renderer\Contracts\ElementDef;
use Aem\Renderer\Contracts\Finding;
use Aem\Renderer\Contracts\PatientContext;
use Aem\Renderer\Support\Pronouns;
use Aem\Renderer\Support\SerialList;
use Aem\Renderer\Support\TextWrapper;

/**
 * Paragraph mode (spec 06 §5.3): prose with pronoun agreement, stem
 * collapsing and polarity grouping.
 *
 * One sentence per element. Within a sentence:
 *
 *   <Subject> <verb> <positives, joined "and">
 *       but <verb> <negatives and neutrals, joined "and">.
 *
 * reproducing §5.6 exactly:
 *
 *   Patient reports limited motion and low back pain but reports no soft
 *   tissue swelling, no joint swelling, no myalgia, and no trauma. He reports
 *   numbness and tingling of the left hand. He reports no significant weight
 *   change, good appetite, no fever, and no fatigue.
 *
 * The subject of the first sentence is the subject noun ("Patient"); every
 * later sentence uses the subject pronoun, agreeing with stated pronouns or,
 * failing that, sex. Nothing is inferred from the patient's name.
 */
final class ParagraphModeRenderer
{
    private LabelResolver $labels;
    private Ordering $ordering;
    private StemCollapser $collapser;
    private Pronouns $pronouns;

    public function __construct(
        private readonly TemplateMap $map,
        private readonly PatientContext $patient = new PatientContext(),
        private readonly RenderOptions $options = new RenderOptions(),
    ) {
        $this->labels = new LabelResolver($map);
        $this->ordering = new Ordering($map, $options);
        $this->collapser = new StemCollapser(
            $options->minStemWords,
            $options->minItemsForShortStem,
        );
        $this->pronouns = new Pronouns($patient);
    }

    /** @param list<Finding> $findings */
    public function render(array $findings): string
    {
        if ($findings === []) {
            return '';
        }

        $ordered = $this->ordering->sort($findings);
        $sentences = [];
        $first = true;

        foreach ($this->byElement($ordered) as $bucket) {
            $sentence = $this->sentence($bucket['findings'], $bucket['element'], $first);
            if ($sentence === '') {
                continue;
            }
            $sentences[] = $sentence;
            $first = false;
        }

        return TextWrapper::wrap(
            implode(' ', $sentences),
            $this->options->wrapWidth,
            $this->options->hangingIndent
        );
    }

    /** @param list<Finding> $findings */
    private function sentence(array $findings, ?ElementDef $element, bool $isFirst): string
    {
        if ($this->options->polarityGrouping) {
            [$positives, $rest] = Ordering::partitionByPolarity($findings);
        } else {
            $positives = [];
            $rest = $findings;
        }

        $positiveText = $this->clauseList($positives, 'and');
        $restText = $this->clauseList($rest, 'and');

        if ($positiveText === '' && $restText === '') {
            return '';
        }

        $useNoun = $isFirst && $this->options->nounThenPronoun;
        $subject = $useNoun
            ? $this->patient->subject_noun
            : $this->pronouns->subjectCapitalised();

        // A noun subject takes the third-person singular verb whatever the
        // patient's pronouns are: "Patient reports ...", then "They report ...".
        $verb = $useNoun
            ? $this->options->reportingVerb
            : $this->pronouns->verb($this->options->reportingVerb);

        $parts = [$subject, $verb];
        if ($positiveText !== '') {
            $parts[] = $positiveText;
            if ($restText !== '') {
                $parts[] = 'but';
                $parts[] = $verb;
                $parts[] = $restText;
            }
        } else {
            $parts[] = $restText;
        }

        $sentence = implode(' ', $parts);

        // An element whose render_prefix is set keeps it as a lead-in; §5.6's
        // ROS uses none, so prefixes are normally null in paragraph sections.
        if ($element !== null && $element->render_prefix !== null) {
            $sentence = $element->prefix() . ': ' . $sentence;
        }

        return rtrim($sentence, '. ') . '.';
    }

    /**
     * @param list<Finding> $findings
     */
    private function clauseList(array $findings, string $conjunction): string
    {
        if ($findings === []) {
            return '';
        }

        $items = [];
        foreach (LabelResolver::groupLevels($findings, $this->options->aggregateLevels) as $group) {
            $items[] = [
                'text' => $this->labels->text($group['findings'][0], $group['levels']),
                'polarity' => $group['findings'][0]->polarity,
            ];
        }

        $segments = $this->collapser->collapse($items);

        return SerialList::join(
            array_map(static fn (array $s) => $s['text'], $segments),
            $conjunction
        );
    }

    /**
     * @param list<Finding> $ordered
     * @return list<array{element:?ElementDef,findings:list<Finding>}>
     */
    private function byElement(array $ordered): array
    {
        $buckets = [];
        $currentId = false;
        foreach ($ordered as $finding) {
            if ($finding->element_id !== $currentId) {
                $buckets[] = ['element' => $this->map->element($finding->element_id), 'findings' => []];
                $currentId = $finding->element_id;
            }
            $buckets[count($buckets) - 1]['findings'][] = $finding;
        }

        return $buckets;
    }
}
