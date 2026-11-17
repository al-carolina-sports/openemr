<?php

declare(strict_types=1);

namespace Aem\Renderer\Tests;

use Aem\Renderer\Contracts\Finding;
use Aem\Renderer\Contracts\PatientContext;
use Aem\Renderer\Contracts\Section;
use Aem\Renderer\Contracts\TextSection;
use Aem\Renderer\ListModeRenderer;
use Aem\Renderer\NoteRenderer;
use Aem\Renderer\ParagraphModeRenderer;
use Aem\Renderer\RenderOptions;
use Aem\Renderer\StemCollapser;
use Aem\Renderer\Support\Pronouns;
use Aem\Renderer\Support\SerialList;
use Aem\Renderer\TemplateMap;

final class UnitTest
{
    public static function run(Harness $t): void
    {
        self::stemCollapsing($t);
        self::ordering($t);
        self::pronouns($t);
        self::polarityGrouping($t);
        self::listModes($t);
        self::guards($t);
    }

    // ------------------------------------------------------------------

    private static function stemCollapsing(Harness $t): void
    {
        $t->group('Stem collapsing (§5.3)');

        $collapser = new StemCollapser(minStemWords: 2, minItemsForShortStem: 3);

        $negatives = array_map(
            static fn (string $l) => ['text' => $l, 'polarity' => 'negative'],
            [
                'no tenderness of the spinous process',
                'no tenderness of the facet joints',
                'no tenderness of the sacroiliac joint(s)',
                'no tenderness of the coccyx',
            ]
        );
        $out = $collapser->collapse($negatives);

        $t->assertSame(
            'four negatives collapse to one clause with "or"',
            'no tenderness of the spinous process, the facet joints, '
                . 'the sacroiliac joint(s), or the coccyx',
            $out[0]['text']
        );
        $t->assertSame('collapse yields a single segment', 1, count($out));
        $t->assertTrue('segment is flagged collapsed', $out[0]['collapsed']);

        $positives = array_map(
            static fn (string $l) => ['text' => $l, 'polarity' => 'positive'],
            [
                'tenderness of the spinous process',
                'tenderness of the facet joints',
                'tenderness of the coccyx',
            ]
        );
        $t->assertSame(
            'positives collapse with "and", not "or"',
            'tenderness of the spinous process, the facet joints, and the coccyx',
            $collapser->collapse($positives)[0]['text']
        );

        $t->assertSame(
            'determiners are handed back to the tails, not kept in the stem',
            'no tenderness of the left wrist or the right wrist',
            $collapser->collapse([
                ['text' => 'no tenderness of the left wrist', 'polarity' => 'negative'],
                ['text' => 'no tenderness of the right wrist', 'polarity' => 'negative'],
            ])[0]['text']
        );

        $t->assertSame(
            'a bare one-word stem needs three items (list mode)',
            2,
            count($collapser->collapse([
                ['text' => 'no limp', 'polarity' => 'negative'],
                ['text' => 'no assistive devices', 'polarity' => 'negative'],
            ]))
        );
        $t->assertSame(
            'three items with a bare stem do collapse (list mode)',
            'no induration, ecchymosis, or swelling',
            $collapser->collapse([
                ['text' => 'no induration', 'polarity' => 'negative'],
                ['text' => 'no ecchymosis', 'polarity' => 'negative'],
                ['text' => 'no swelling', 'polarity' => 'negative'],
            ])[0]['text']
        );

        $prose = new StemCollapser(minStemWords: 2, minItemsForShortStem: PHP_INT_MAX);
        $t->assertSame(
            'a bare one-word stem never collapses in prose',
            4,
            count($prose->collapse(array_map(
                static fn (string $l) => ['text' => $l, 'polarity' => 'negative'],
                ['no soft tissue swelling', 'no joint swelling', 'no myalgia', 'no trauma']
            )))
        );

        $t->assertSame(
            'polarity runs never merge',
            3,
            count($collapser->collapse([
                ['text' => 'no induration', 'polarity' => 'negative'],
                ['text' => 'normal alignment', 'polarity' => 'neutral'],
                ['text' => 'no swelling', 'polarity' => 'negative'],
            ]))
        );

        $t->assertSame(
            'an item identical to the stem blocks collapse',
            2,
            count($collapser->collapse([
                ['text' => 'no tenderness', 'polarity' => 'negative'],
                ['text' => 'no tenderness of the coccyx', 'polarity' => 'negative'],
            ]))
        );

        $t->assertSame('two-item serial list takes no comma', 'a or b', SerialList::join(['a', 'b'], 'or'));
        $t->assertSame('three-item list takes the Oxford comma', 'a, b, or c', SerialList::join(['a', 'b', 'c'], 'or'));
    }

    // ------------------------------------------------------------------

    private static function ordering(Harness $t): void
    {
        $t->group('Deterministic ordering (§5.5, acceptance test 19)');

        // Acceptance test 19: chips entered out of template order.
        // Entered: healthy-appearing, normal body habitus, NAD.
        // Template order: healthy-appearing(1), NAD(2), normal body habitus(3).
        $map = new TemplateMap(
            [['id' => 1, 'label' => 'General Appearance', 'render_prefix' => 'General Appearance', 'sort_order' => 1]],
            [
                ['id' => 11, 'element_id' => 1, 'label' => 'healthy-appearing', 'polarity' => 'neutral', 'sort_order' => 1],
                ['id' => 12, 'element_id' => 1, 'label' => 'NAD', 'polarity' => 'neutral', 'sort_order' => 2],
                ['id' => 13, 'element_id' => 1, 'label' => 'normal body habitus', 'polarity' => 'neutral', 'sort_order' => 3],
            ]
        );

        $entered = [
            Finding::fromArray(['element_id' => 1, 'option_id' => 11, 'polarity' => 'neutral', 'sort_order' => 1]),
            Finding::fromArray(['element_id' => 1, 'option_id' => 13, 'polarity' => 'neutral', 'sort_order' => 2]),
            Finding::fromArray(['element_id' => 1, 'option_id' => 12, 'polarity' => 'neutral', 'sort_order' => 3]),
        ];

        $t->assertSame(
            'default ordering is template_option.sort_order',
            'General Appearance: healthy-appearing; NAD; normal body habitus',
            (new ListModeRenderer($map))->render($entered)
        );

        $t->assertSame(
            'orderBy=entry preserves entry order instead',
            'General Appearance: healthy-appearing; normal body habitus; NAD',
            (new ListModeRenderer($map, new RenderOptions(orderBy: RenderOptions::ORDER_BY_ENTRY)))
                ->render($entered)
        );

        $t->assertSame(
            'rendering is stable: the same input gives the same output',
            (new ListModeRenderer($map))->render($entered),
            (new ListModeRenderer($map))->render(array_reverse($entered))
        );
    }

    // ------------------------------------------------------------------

    private static function pronouns(Harness $t): void
    {
        $t->group('Pronoun agreement (§5.3)');

        $map = new TemplateMap(
            [
                ['id' => 1, 'label' => 'Constitutional', 'sort_order' => 1],
                ['id' => 2, 'label' => 'Neurological', 'sort_order' => 2],
            ],
            [
                ['id' => 11, 'element_id' => 1, 'label' => 'no fever', 'polarity' => 'negative', 'sort_order' => 1],
                ['id' => 21, 'element_id' => 2, 'label' => 'numbness', 'polarity' => 'positive', 'sort_order' => 1],
            ]
        );
        $findings = [
            Finding::fromArray(['element_id' => 1, 'option_id' => 11, 'polarity' => 'negative', 'sort_order' => 1]),
            Finding::fromArray(['element_id' => 2, 'option_id' => 21, 'polarity' => 'positive', 'sort_order' => 2]),
        ];

        $render = static fn (PatientContext $p) => (new ParagraphModeRenderer($map, $p, RenderOptions::paragraph()))
            ->render($findings);

        $t->assertSame(
            'male sex yields "He reports"',
            'Patient reports no fever. He reports numbness.',
            $render(new PatientContext(sex: 'M'))
        );
        $t->assertSame(
            'female sex yields "She reports"',
            'Patient reports no fever. She reports numbness.',
            $render(new PatientContext(sex: 'female'))
        );
        $t->assertSame(
            'stated pronouns override sex, with plural verb agreement',
            'Patient reports no fever. They report numbness.',
            $render(new PatientContext(sex: 'M', pronouns: 'they/them'))
        );
        $t->assertSame(
            'unknown sex falls back to the neutral set, never a guess',
            'Patient reports no fever. They report numbness.',
            $render(new PatientContext(display_name: 'TEST, MARTY'))
        );

        $t->assertSame('verb agreement: is -> are', 'are', (new Pronouns(new PatientContext(pronouns: 'they')))->verb('is'));
        $t->assertSame('verb agreement: has -> have', 'have', (new Pronouns(new PatientContext(pronouns: 'they')))->verb('has'));
        $t->assertSame('verb agreement: denies -> deny', 'deny', (new Pronouns(new PatientContext(pronouns: 'they')))->verb('denies'));
        $t->assertSame('singular keeps the third-person form', 'reports', (new Pronouns(new PatientContext(sex: 'F')))->verb('reports'));
    }

    // ------------------------------------------------------------------

    private static function polarityGrouping(Harness $t): void
    {
        $t->group('Polarity grouping (§5.3)');

        $map = new TemplateMap(
            [['id' => 1, 'label' => 'Musculoskeletal', 'sort_order' => 1]],
            [
                ['id' => 11, 'element_id' => 1, 'label' => 'no joint swelling', 'polarity' => 'negative', 'sort_order' => 1],
                ['id' => 12, 'element_id' => 1, 'label' => 'low back pain', 'polarity' => 'positive', 'sort_order' => 2],
                ['id' => 13, 'element_id' => 1, 'label' => 'no myalgia', 'polarity' => 'negative', 'sort_order' => 3],
                ['id' => 14, 'element_id' => 1, 'label' => 'limited motion', 'polarity' => 'positive', 'sort_order' => 4],
            ]
        );
        $findings = [];
        foreach ([[11, 'negative'], [12, 'positive'], [13, 'negative'], [14, 'positive']] as $i => [$opt, $pol]) {
            $findings[] = Finding::fromArray([
                'element_id' => 1, 'option_id' => $opt, 'polarity' => $pol, 'sort_order' => $i + 1,
            ]);
        }

        $t->assertSame(
            'positives come first, then negatives, each in sort order',
            // wrapped at the 79-column default
            "Patient reports low back pain and limited motion but reports no joint swelling\nand no myalgia.",
            (new ParagraphModeRenderer($map, new PatientContext(sex: 'M'), RenderOptions::paragraph()))
                ->render($findings)
        );

        $t->assertSame(
            'list mode does not regroup by polarity',
            'Musculoskeletal: no joint swelling; low back pain; no myalgia; limited motion',
            (new ListModeRenderer($map))->render($findings)
        );
    }

    // ------------------------------------------------------------------

    private static function listModes(Harness $t): void
    {
        $t->group('List mode (§5.2) and value slots');

        $map = new TemplateMap(
            [['id' => 1, 'label' => 'Severity', 'render_prefix' => 'Severity', 'sort_order' => 1]],
            [
                ['id' => 11, 'element_id' => 1, 'label' => 'current pain level {numeric}/10', 'polarity' => 'neutral', 'sort_order' => 1],
                ['id' => 12, 'element_id' => 1, 'label' => 'relief {numeric}{unit} for {text}', 'polarity' => 'neutral', 'sort_order' => 2],
                ['id' => 13, 'element_id' => 1, 'label' => 'worst pain {numeric}/10', 'polarity' => 'neutral', 'sort_order' => 3],
            ]
        );

        $t->assertSame(
            'value slots fill from value_numeric, value_unit and value_text',
            'Severity: current pain level 6/10; relief 50% for 4 hours; worst pain 8/10',
            (new ListModeRenderer($map))->render([
                Finding::fromArray(['element_id' => 1, 'option_id' => 11, 'value_numeric' => 6, 'sort_order' => 1]),
                Finding::fromArray(['element_id' => 1, 'option_id' => 12, 'value_numeric' => 50, 'value_unit' => '%', 'value_text' => '4 hours', 'sort_order' => 2]),
                Finding::fromArray(['element_id' => 1, 'option_id' => 13, 'value_numeric' => 8, 'sort_order' => 3]),
            ])
        );

        $t->assertSame(
            'a null value slot leaves no placeholder and no stray space',
            'Severity: current pain level /10',
            (new ListModeRenderer($map))->render([
                Finding::fromArray(['element_id' => 1, 'option_id' => 11, 'sort_order' => 1]),
            ])
        );

        $free = new TemplateMap([['id' => 1, 'label' => 'Context', 'render_prefix' => 'Context', 'sort_order' => 1]], []);
        $t->assertSame(
            'a finding with no option renders its value_text (free entry)',
            'Context: moving a freezer up a staircase',
            (new ListModeRenderer($free))->render([
                Finding::fromArray(['element_id' => 1, 'value_text' => 'moving a freezer up a staircase', 'sort_order' => 1]),
            ])
        );

        $t->assertSame('empty input renders empty', '', (new ListModeRenderer($map))->render([]));
    }

    // ------------------------------------------------------------------

    private static function guards(Harness $t): void
    {
        $t->group('Contract guards');

        $t->assertThrows(
            'an invented finding column is rejected',
            \InvalidArgumentException::class,
            static fn () => Finding::fromArray(['element_id' => 1, 'stem' => 'no tenderness of'])
        );

        $t->assertThrows(
            'a missing OptionDef raises rather than rendering a blank clause',
            \OutOfBoundsException::class,
            static fn () => (new ListModeRenderer(new TemplateMap([['id' => 1, 'label' => 'X', 'sort_order' => 1]], [])))
                ->render([Finding::fromArray(['element_id' => 1, 'option_id' => 999, 'sort_order' => 1])])
        );

        $t->assertThrows(
            'near-duplicate section headings are rejected (§5.7)',
            \LogicException::class,
            static fn () => (new NoteRenderer(new TemplateMap()))->render([], [
                new TextSection('assessment_plan', ['1. Lumbar radiculopathy']),
                new TextSection('assessment_plan', ['None recorded.'], title: 'Assessment/Plan'),
            ])
        );

        $t->assertSame(
            'an internal-visibility note is withheld from the shared view (§6.4)',
            '',
            (new \Aem\Renderer\SectionRenderer(new TemplateMap()))->renderBody(
                new Section(
                    section_key: 'hpi',
                    note_text: 'internal scheduling remark',
                    note_visibility: Section::VIS_INTERNAL,
                )
            )
        );

        $t->assertSame(
            'the internal view includes it when asked',
            'Note: internal scheduling remark',
            (new \Aem\Renderer\SectionRenderer(new TemplateMap(), new PatientContext(), new RenderOptions(), true))
                ->renderBody(new Section(
                    section_key: 'hpi',
                    note_text: 'internal scheduling remark',
                    note_visibility: Section::VIS_INTERNAL,
                ))
        );

        $source = implode("\n", array_map(
            static fn (string $f) => (string) file_get_contents($f),
            array_merge(
                glob(dirname(__DIR__) . '/src/*.php') ?: [],
                glob(dirname(__DIR__) . '/src/*/*.php') ?: [],
                glob(dirname(__DIR__) . '/tests/*.php') ?: [],
                glob(dirname(__DIR__) . '/fixtures/*.php') ?: [],
                [dirname(__DIR__) . '/README.md', dirname(__DIR__) . '/autoload.php']
            )
        ));
        $t->assertTrue(
            'the forbidden vendor name appears nowhere in the package',
            stripos($source, 'patient' . 'now') === false
        );
    }
}
