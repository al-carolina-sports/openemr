<?php

declare(strict_types=1);

namespace Aem\Renderer;

use Aem\Renderer\Contracts\ElementDef;
use Aem\Renderer\Contracts\Finding;
use Aem\Renderer\Support\SerialList;
use Aem\Renderer\Support\TextWrapper;

/**
 * List mode (spec 06 §5.2) in two styles.
 *
 * LIST_SIMPLE — the §5.2 contract, one element per line:
 *     Location: radiating to the RLE; LBP; right buttock pain
 *     Severity: current pain level 6/10; worst pain 8/10; interference with sleep
 *
 * LIST_EXAM — the layout the §5.6 physical exam actually uses: elements
 * grouped under a region heading, values comma-separated, each element
 * terminated as a sentence, stem collapsing applied:
 *     Lumbar Spine: Inspection: no induration, ecchymosis, or swelling; normal
 *       alignment. Bony Palpation: no tenderness of the spinous process, the
 *       facet joints, the sacroiliac joint(s), or the coccyx.
 *
 * Neither style regroups by polarity: list mode renders strictly in the
 * documented sort order (§5.5). Polarity grouping is a paragraph-mode rule
 * (§5.3).
 */
final class ListModeRenderer
{
    private LabelResolver $labels;
    private Ordering $ordering;
    private StemCollapser $collapser;

    public function __construct(
        private readonly TemplateMap $map,
        private readonly RenderOptions $options = new RenderOptions(),
    ) {
        $this->labels = new LabelResolver($map);
        $this->ordering = new Ordering($map, $options);
        $this->collapser = new StemCollapser(
            $options->minStemWords,
            $options->minItemsForShortStem,
        );
    }

    /** @param list<Finding> $findings */
    public function render(array $findings): string
    {
        if ($findings === []) {
            return '';
        }

        $ordered = $this->ordering->sort($findings);

        return $this->options->listStyle === RenderOptions::LIST_EXAM
            ? $this->renderExam($ordered)
            : $this->renderSimple($ordered);
    }

    /** @param list<Finding> $ordered */
    private function renderSimple(array $ordered): string
    {
        $lines = [];
        foreach ($this->byElement($ordered) as $bucket) {
            /** @var ElementDef|null $element */
            $element = $bucket['element'];
            $segments = $this->segments($bucket['findings']);
            $values = array_map(static fn (array $s) => $s['text'], $segments);

            $prefix = $element === null ? '' : $element->prefix() . ': ';
            $lines[] = TextWrapper::wrap(
                $prefix . SerialList::semicolons($values),
                $this->options->wrapWidth,
                $this->options->hangingIndent
            );
        }

        return implode("\n", $lines);
    }

    /** @param list<Finding> $ordered */
    private function renderExam(array $ordered): string
    {
        $lines = [];
        $currentGroup = null;
        $sentences = [];

        $flush = function () use (&$lines, &$currentGroup, &$sentences): void {
            if ($sentences === []) {
                return;
            }
            $prefix = ($currentGroup !== null && $currentGroup !== '')
                ? rtrim($currentGroup, ': ') . ': '
                : '';
            $lines[] = TextWrapper::wrap(
                $prefix . implode(' ', $sentences),
                $this->options->wrapWidth,
                $this->options->hangingIndent
            );
            $sentences = [];
        };

        foreach ($this->byElement($ordered) as $bucket) {
            /** @var ElementDef|null $element */
            $element = $bucket['element'];
            $group = $element?->group_label;

            if ($group !== $currentGroup) {
                $flush();
                $currentGroup = $group;
            }

            $segments = $this->segments($bucket['findings']);
            $hasCollapsedCluster = false;
            foreach ($segments as $segment) {
                if ($segment['collapsed']) {
                    $hasCollapsedCluster = true;
                }
            }
            $separator = $hasCollapsedCluster ? '; ' : ', ';
            $value = implode($separator, array_map(static fn (array $s) => $s['text'], $segments));

            // render_prefix is nullable: a null prefix emits the clause bare,
            // which is how §5.6 renders the Neurological elements.
            $elementPrefix = ($element !== null && $element->render_prefix !== null)
                ? $element->prefix() . ': '
                : '';

            $sentences[] = rtrim($elementPrefix . $value, '. ') . '.';
        }
        $flush();

        return implode("\n", $lines);
    }

    /**
     * @param list<Finding> $findings
     * @return list<array{text:string,polarity:string,collapsed:bool}>
     */
    private function segments(array $findings): array
    {
        $items = [];
        foreach (LabelResolver::groupLevels($findings, $this->options->aggregateLevels) as $group) {
            $items[] = [
                'text' => $this->labels->text($group['findings'][0], $group['levels']),
                'polarity' => $group['findings'][0]->polarity,
            ];
        }

        return $this->collapser->collapse($items);
    }

    /**
     * Buckets the ordered findings into consecutive runs sharing element_id.
     * Consecutive, not keyed: the sort already placed every element's findings
     * together, and bucketing by run guarantees we never reorder.
     *
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
