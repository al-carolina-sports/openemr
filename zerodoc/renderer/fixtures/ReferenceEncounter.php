<?php

declare(strict_types=1);

namespace Aem\Renderer\Fixtures;

use Aem\Renderer\Contracts\ElementDef;
use Aem\Renderer\Contracts\Finding;
use Aem\Renderer\Contracts\OptionDef;
use Aem\Renderer\Contracts\PatientContext;
use Aem\Renderer\Contracts\Section;
use Aem\Renderer\Contracts\TextSection;
use Aem\Renderer\TemplateMap;

/**
 * The fixture set for the primary acceptance test: the findings that must
 * render the HPI, ROS and physical-exam sections of spec 06 §5.6.
 *
 * Synthetic patient only. Every option label, polarity and sort order here
 * is a claim about what the content library must contain for §5.6 to come
 * out; where the reference output forced a choice, the choice is commented.
 */
final class ReferenceEncounter
{
    public const EN_DASH = "\u{2013}";
    public const EM_DASH = "\u{2014}";

    public static function patient(): PatientContext
    {
        return new PatientContext(
            display_name: 'TEST, MARTY',
            sex: 'M',
            age: 36,
            record_id: '6108',
            dob: '01/01/1990',
        );
    }

    // ---------------------------------------------------------------- HPI

    /** @return array{0:list<array>,1:list<array>,2:list<Finding>} */
    public static function hpi(): array
    {
        // [element_id, render_prefix, [[option_id, label, polarity], ...]]
        // Element order is the §3.2 element order; §5.6 adds a "Previous PT"
        // element that §3.2's 19-element table does not list (README item 7).
        $spec = [
            [1,  'Location', [
                [101, 'radiating to the RLE', 'positive'],
                [102, 'LBP', 'positive'],
                [103, 'right buttock pain', 'positive'],
            ]],
            [2,  'Quality', [
                [201, 'aching', 'positive'],
                [202, 'sharp', 'positive'],
            ]],
            [3,  'Severity', [
                [301, 'current pain level {numeric}/10', 'neutral'],
                [302, 'worst pain {numeric}/10', 'neutral'],
                [303, 'interference with sleep', 'positive'],
            ]],
            [4,  'Duration', [[401, 'intermittent', 'neutral']]],
            [5,  'Onset/Timing', [[501, 'chronic', 'neutral']]],
            [6,  'Context', [[601, 'lifting', 'neutral']]],
            [7,  'Alleviating Factors', [
                [701, 'heat', 'positive'],
                [702, 'medication', 'positive'],
                [703, 'rest', 'positive'],
            ]],
            [8,  'Aggravating Factors', [
                [801, 'flexion', 'positive'],
                [802, 'lifting', 'positive'],
                [803, 'sit-to-stand', 'positive'],
                [804, 'sitting', 'positive'],
            ]],
            [9,  'Associated Symptoms', [
                [901, 'numbness', 'positive'],
                [902, 'no weakness', 'negative'],
                [903, 'no bladder or bowel compromise', 'negative'],
            ]],
            [10, 'Radiation', [[1001, 'right S1', 'positive']]],
            [11, 'Work Related', [[1101, 'no', 'negative']]],
            [12, 'ADL', [[1201, 'improve with medication', 'neutral']]],
            [14, 'Driving Impairments with Medications', [[1401, 'no', 'negative']]],
            [15, 'Prior Imaging', [[1501, 'MRI', 'neutral']]],
            [16, 'Prior EMG', [[1601, 'none', 'neutral']]],
            [17, 'Previous Surgery', [[1701, 'none', 'neutral']]],
            [18, 'Previous Injections', [[1801, 'none', 'neutral']]],
            [19, 'Previous PT', [[1901, 'helped a little', 'neutral']]],
            [20, 'Previous Chiropractic Care', [[20001, 'none', 'neutral']]],
        ];

        $elements = [];
        $options = [];
        $findings = [];
        $order = 0;

        foreach ($spec as [$elementId, $prefix, $opts]) {
            $elements[] = [
                'id' => $elementId,
                'label' => $prefix,
                'render_prefix' => $prefix,
                'sort_order' => $elementId,
                'template_id' => 1,
            ];
            foreach ($opts as $i => [$optionId, $label, $polarity]) {
                $options[] = [
                    'id' => $optionId,
                    'element_id' => $elementId,
                    'label' => $label,
                    'polarity' => $polarity,
                    'sort_order' => $i + 1,
                ];
                $findings[] = Finding::fromArray([
                    'encounter_section_id' => 10,
                    'template_id' => 1,
                    'element_id' => $elementId,
                    'option_id' => $optionId,
                    'polarity' => $polarity,
                    'sort_order' => ++$order,
                    'value_numeric' => match ($optionId) { 301 => 6, 302 => 8, default => null },
                ]);
            }
        }

        return [$elements, $options, $findings];
    }

    public static function hpiSection(): Section
    {
        [, , $findings] = self::hpi();

        return new Section(
            section_key: 'hpi',
            findings: $findings,
            render_mode: Section::MODE_LIST,
            note_text: 'numbness and tingling in the first three digits of the LEFT hand in a '
                . 'median nerve distribution, worse at night and with sustained gripping; '
                . 'relieved by shaking the hand. No prior EMG/NCS. Consistent with left '
                . 'carpal tunnel syndrome.',
            template_label: 'Pain Management L-spine',
            reported_by: 'patient',
        );
    }

    // ---------------------------------------------------------------- ROS

    /** @return array{0:list<array>,1:list<array>,2:list<Finding>} */
    public static function ros(): array
    {
        // One element per reviewed system; render_prefix is null because §5.6
        // renders ROS as running prose with no element labels.
        $spec = [
            [200, 'Musculoskeletal', [
                [2001, 'limited motion', 'positive'],
                [2002, 'low back pain', 'positive'],
                [2003, 'no soft tissue swelling', 'negative'],
                [2004, 'no joint swelling', 'negative'],
                [2005, 'no myalgia', 'negative'],
                [2006, 'no trauma', 'negative'],
            ]],
            [201, 'Neurological', [
                [2101, 'numbness and tingling of the left hand', 'positive'],
            ]],
            [202, 'Constitutional', [
                [2201, 'no significant weight change', 'negative'],
                // "good appetite" is a neutral descriptor, not a positive
                // finding: §5.6 renders it between two negatives, which
                // polarity grouping would forbid for a positive. README item 4.
                [2202, 'good appetite', 'neutral'],
                [2203, 'no fever', 'negative'],
                [2204, 'no fatigue', 'negative'],
            ]],
        ];

        return self::build($spec, sectionId: 11, templateId: 2, prefixElements: false);
    }

    public static function rosSection(): Section
    {
        [, , $findings] = self::ros();

        return new Section(
            section_key: 'ros',
            findings: $findings,
            render_mode: Section::MODE_PARAGRAPH,
            template_label: 'Comprehensive Adult Problem ROS',
        );
    }

    // ----------------------------------------------------- PHYSICAL EXAM

    /** @return array{0:list<array>,1:list<array>,2:list<Finding>} */
    public static function pe(): array
    {
        $en = self::EN_DASH;
        $em = self::EM_DASH;

        // [element_id, group_label, render_prefix, options]
        // Polarity convention, forced by §5.6's punctuation (README item 5):
        //   negative = an asserted absence  ("no swelling")
        //   neutral  = a normal descriptor  ("normal alignment", "normal gait")
        // Keeping "normal alignment" out of the negative run is what makes
        // "no induration, ecchymosis, or swelling; normal alignment" come out.
        $spec = [
            [300, 'Constitutional', 'General Appearance', [
                [3001, 'healthy-appearing', 'neutral'],
                [3002, 'NAD', 'neutral'],
                [3003, 'normal body habitus', 'neutral'],
            ]],
            [301, 'Constitutional', 'Gait and Station', [
                [3011, 'normal gait', 'neutral'],
                [3012, 'no limp', 'negative'],
                [3013, 'no assistive devices', 'negative'],
            ]],
            [310, 'Lumbar Spine', 'Inspection', [
                [3101, 'no induration', 'negative'],
                [3102, 'no ecchymosis', 'negative'],
                [3103, 'no swelling', 'negative'],
                [3104, 'normal alignment', 'neutral'],
            ]],
            [311, 'Lumbar Spine', 'Bony Palpation', [
                [3111, 'no tenderness of the spinous process', 'negative'],
                [3112, 'no tenderness of the facet joints', 'negative'],
                [3113, 'no tenderness of the sacroiliac joint(s)', 'negative'],
                [3114, 'no tenderness of the coccyx', 'negative'],
            ]],
            [312, 'Lumbar Spine', 'Active ROM', [
                [3121, 'no pain with motion', 'negative'],
            ]],
            [320, 'Motor Strength', null, [
                [3201, '{levels} ' . $em . ' 5/5 right and left', 'neutral'],
            ]],
            [330, 'Wrist', 'Sensation Right', [
                [3301, 'normal median nerve distribution', 'neutral'],
            ]],
            [331, 'Wrist', 'Sensation Left', [
                [3311, 'decreased median nerve distribution', 'positive'],
            ]],
            [332, 'Wrist', 'Special Tests Right', [
                [3321, "Tinel's negative", 'negative'],
                [3322, "Phalen's negative", 'negative'],
            ]],
            [333, 'Wrist', 'Special Tests Left', [
                [3331, "Tinel's at the median nerve positive", 'positive'],
                [3332, "Phalen's positive", 'positive'],
            ]],
            [340, 'Neurological', null, [
                [3401, 'Sensation {level} normal bilaterally', 'neutral'],
            ]],
            [341, 'Neurological', null, [
                [3411, 'Straight leg raise negative bilaterally', 'negative'],
            ]],
            [342, 'Neurological', null, [
                [3421, 'Babinski absent bilaterally', 'negative'],
            ]],
        ];

        $elements = [];
        $options = [];
        $findings = [];
        $order = 0;

        foreach ($spec as [$elementId, $group, $prefix, $opts]) {
            $elements[] = [
                'id' => $elementId,
                'label' => $prefix ?? $group,
                'render_prefix' => $prefix,
                'group_label' => $group,
                'sort_order' => $elementId,
                'template_id' => 3,
            ];
            foreach ($opts as $i => [$optionId, $label, $polarity]) {
                $options[] = [
                    'id' => $optionId,
                    'element_id' => $elementId,
                    'label' => $label,
                    'polarity' => $polarity,
                    'sort_order' => $i + 1,
                ];

                // Motor strength: one finding per tested level, same option.
                // Level aggregation collapses them into one clause.
                if ($optionId === 3201) {
                    foreach (['L1', 'L2' . $en . 'L4', 'L5', 'S1'] as $level) {
                        $findings[] = Finding::fromArray([
                            'encounter_section_id' => 12,
                            'template_id' => 3,
                            'element_id' => $elementId,
                            'option_id' => $optionId,
                            'polarity' => $polarity,
                            'laterality' => Finding::LAT_BILATERAL,
                            'anatomic_level' => $level,
                            'sort_order' => ++$order,
                        ]);
                    }
                    continue;
                }

                $findings[] = Finding::fromArray([
                    'encounter_section_id' => 12,
                    'template_id' => 3,
                    'element_id' => $elementId,
                    'option_id' => $optionId,
                    'polarity' => $polarity,
                    'laterality' => $optionId === 3401 ? Finding::LAT_BILATERAL : Finding::LAT_NA,
                    'anatomic_level' => $optionId === 3401 ? 'T12' . $en . 'S2' : null,
                    'sort_order' => ++$order,
                ]);
            }
        }

        return [$elements, $options, $findings];
    }

    public static function peSection(): Section
    {
        [, , $findings] = self::pe();

        return new Section(
            section_key: 'pe',
            findings: $findings,
            render_mode: Section::MODE_LIST,
            note_text: 'no thenar atrophy.',
            template_label: 'CSS Lumbar Spine + Wrist',
        );
    }

    // ------------------------------------------------------------- shared

    public static function templateMap(): TemplateMap
    {
        [$he, $ho] = self::hpi();
        [$re, $ro] = self::ros();
        [$pe, $po] = self::pe();

        return new TemplateMap(
            array_merge($he, $re, $pe),
            array_merge($ho, $ro, $po),
        );
    }

    /**
     * The non-findings sections of §5.6, supplied as pre-composed lines by
     * the packages that own them.
     *
     * @return list<TextSection>
     */
    public static function surroundingSections(): array
    {
        $em = self::EM_DASH;

        return [
            new TextSection('reason', ['low back pain']),
            new TextSection('vitals', [
                "Ht 5'10\" · Wt 210 lb · BMI 30.1 · BP 132/84 · RR 16 · T 98.4 °F ·",
                'HR 78 · O2 98% · Pain 6/10',
            ], title_suffix: '(11/14/2026 21:40)'),
            new TextSection('allergies', [
                'CODEINE · STRAWBERRY        (external, 11/14/2026 21:38; reviewed 21:55)',
            ]),
            new TextSection('medications', [
                'metFORMIN 500 mg tablet ' . $em . ' 1 tab PO BID',
                'lisinopril 10 mg tablet ' . $em . ' 1 tab PO daily',
            ]),
            new TextSection('problems', [
                'Essential hypertension (I10) · Diabetes type 2, controlled (E11.9) ·',
                'Obesity, class 1, BMI 30.0' . self::EN_DASH . '34.9 (E66.811) ·',
                'Carpal tunnel syndrome, left (G56.02)',
            ]),
        ];
    }

    /**
     * @param list<array{0:int,1:string,2:list<array{0:int,1:string,2:string}>}> $spec
     * @return array{0:list<array>,1:list<array>,2:list<Finding>}
     */
    private static function build(array $spec, int $sectionId, int $templateId, bool $prefixElements): array
    {
        $elements = [];
        $options = [];
        $findings = [];
        $order = 0;

        foreach ($spec as [$elementId, $label, $opts]) {
            $elements[] = [
                'id' => $elementId,
                'label' => $label,
                'render_prefix' => $prefixElements ? $label : null,
                'sort_order' => $elementId,
                'template_id' => $templateId,
            ];
            foreach ($opts as $i => [$optionId, $optionLabel, $polarity]) {
                $options[] = [
                    'id' => $optionId,
                    'element_id' => $elementId,
                    'label' => $optionLabel,
                    'polarity' => $polarity,
                    'sort_order' => $i + 1,
                ];
                $findings[] = Finding::fromArray([
                    'encounter_section_id' => $sectionId,
                    'template_id' => $templateId,
                    'element_id' => $elementId,
                    'option_id' => $optionId,
                    'polarity' => $polarity,
                    'sort_order' => ++$order,
                ]);
            }
        }

        return [$elements, $options, $findings];
    }
}
