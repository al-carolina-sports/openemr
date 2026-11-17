<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * Cross-reference integrity and set-content sanity - spec 06 §4.3, §3.2, §3.8.
 *
 * Defect classes covered:
 *   - a set named for a procedure that contains only a diagnosis code, so the
 *     procedure order never gets placed and the work reappears downstream
 *     (06 §4.3; acceptance test 10)
 *   - a preset answering a template the saved finding does not bundle
 *   - an active entry depending on a retired one
 *   - mutual-exclusion references pointing at options that do not exist
 *   - a value slot promised by an option's text but not declared (the "__/10"
 *     and "__ %" case), and the reverse
 *   - monitoring/compliance elements embedded in a mechanical-pain template,
 *     which 06 §3.2 requires to be a separately attachable template
 */
final class IntegrityRule implements Rule
{
    /** Words in a set name that assert a procedure will be done. */
    private const PROCEDURE_WORDS = [
        'esi', 'ilesi', 'tfesi', 'cesi', 'injection', 'inject', 'block',
        'rfa', 'rfta', 'ablation', 'neurotomy', 'rhizotomy', 'discogram',
        'discography', 'myelogram', 'arthrocentesis', 'aspiration',
        'trigger point', 'tpi', 'epidural', 'facet', 'medial branch',
        'kyphoplasty', 'vertebroplasty', 'stimulator', 'trial', 'implant',
        'viscosupplementation', 'hydrodissection', 'nerve block', 'sympathetic',
    ];

    /** Elements that belong in a controlled-substance monitoring template. */
    private const MONITORING_MARKERS = [
        'relief w/ current meds', 'relief with current meds',
        'relief with current medication', 'percent relief',
        'driving impairment', 'pdmp', 'urine drug', 'uds', 'opioid agreement',
        'pill count', 'aberrant behaviour', 'aberrant behavior',
        'controlled substance agreement',
    ];

    public function code(): string
    {
        return 'INTEGRITY';
    }

    public function describes(): string
    {
        return 'Cross-references resolve; set contents match their names (06 §4.3, test 10)';
    }

    public function check(Library $library, Context $context): array
    {
        $issues = [];

        // --- order sets: name promises a procedure, contents do not deliver one
        foreach ($library->orderSets as $set) {
            $types = array_column($set['items'], 'item_type');
            $name  = strtolower((string) $set['name']);
            $claimsProcedure = $this->matches($name, self::PROCEDURE_WORDS);
            $hasProcedure = in_array('procedure', $types, true);

            if ($claimsProcedure && !$hasProcedure) {
                $issues[] = Issue::warning(
                    $this->code(), Context::describe($set), 'items',
                    sprintf(
                        'The name promises a procedure but the set contains no item of '
                        . 'type "procedure" - only: %s.',
                        $types === [] ? '(nothing)' : implode(', ', array_unique($types))
                    ),
                    'Add the procedure item. A set named for a procedure that holds only '
                    . 'a diagnosis code is a diagnosis shortcut wearing a procedure name: '
                    . 'the order is never placed and someone redoes the work by hand later.',
                    $set['_file']
                );
            }

            if ($types !== [] && array_unique($types) === ['diagnosis']) {
                $issues[] = Issue::warning(
                    $this->code(), Context::describe($set), 'items',
                    'This order set contains diagnosis codes and nothing else.',
                    'Either add the orders the set is meant to place, or make it a '
                    . 'diagnosis set rather than an order set, so its name says what '
                    . 'it does.',
                    $set['_file']
                );
            }
        }

        // --- saved finding presets must target a bundled template
        foreach ($library->savedFindings as $finding) {
            $bundled = array_column($finding['templates'], 'template');
            foreach ($finding['presets'] as $i => $preset) {
                if ($preset['template'] === '' || in_array($preset['template'], $bundled, true)) {
                    continue;
                }
                $issues[] = Issue::error(
                    $this->code(), Context::describe($finding), 'preset #' . ($i + 1),
                    sprintf(
                        'This preset answers template "%s", which the saved finding does '
                        . 'not bundle. Bundled: %s.',
                        $preset['template'],
                        $bundled === [] ? '(none)' : '"' . implode('", "', $bundled) . '"'
                    ),
                    'Add the template to "templates:", or point the preset at one that '
                    . 'is already there.',
                    $finding['_file']
                );
            }
        }

        // --- active entries must not depend on retired ones
        $active = [];
        foreach ($library->allEntries() as $e) {
            $active[$e['_type'] . ':' . ($e['key'] ?? '')] = (bool) ($e['is_active'] ?? true);
            if (($e['code'] ?? '') !== '') {
                $active[$e['_type'] . ':' . $e['code']] = (bool) ($e['is_active'] ?? true);
            }
        }
        foreach ($library->savedFindings as $finding) {
            if (!$finding['is_active']) {
                continue;
            }
            foreach ($finding['templates'] as $ref) {
                $template = $library->templateByKey($ref['template']);
                if ($template !== null && !$template['is_active']) {
                    $issues[] = Issue::error(
                        $this->code(), Context::describe($finding), "template \"{$ref['template']}\"",
                        'This active saved finding bundles a retired template '
                        . "(\"{$template['name']}\" has is_active: false).",
                        'Retire the saved finding too, or point it at the replacement '
                        . 'template. Retirement preserves historical notes; it is not a '
                        . 'place to keep live dependencies (06 §3.8).',
                        $finding['_file']
                    );
                }
            }
        }

        // --- option-level checks
        foreach ($library->templates as $template) {
            $entry = Context::describe($template);
            foreach ($template['elements'] as $element) {
                $labels = array_map('strtolower', array_column($element['options'], 'label'));

                if ($this->matches(strtolower($element['label']), self::MONITORING_MARKERS)
                    && !$this->isMonitoringTemplate($template)) {
                    $issues[] = Issue::warning(
                        $this->code(), $entry, "element \"{$element['label']}\"",
                        'This is a controlled-substance monitoring field sitting inside a '
                        . 'general clinical template.',
                        'Move it to a monitoring/compliance template that can be attached '
                        . 'independently, so it is documented when it applies and absent '
                        . 'when it does not (06 §3.2).',
                        $template['_file']
                    );
                }

                foreach ($element['options'] as $option) {
                    foreach ($option['excludes'] as $target) {
                        if (!in_array(strtolower($target), $labels, true)) {
                            $issues[] = Issue::error(
                                $this->code(), $entry,
                                "element \"{$element['label']}\" / option \"{$option['label']}\"",
                                "Excludes \"{$target}\", which is not an option of this element.",
                                'Mutual exclusion works within one element. Check the '
                                . 'spelling, or move the option into this element.',
                                $template['_file']
                            );
                        }
                    }

                    $promisesSlot = preg_match('/__|\b\d+\s*\/\s*10\b|%/', $option['label']) === 1;
                    if ($promisesSlot && $option['value_slots'] === []) {
                        $issues[] = Issue::warning(
                            $this->code(), $entry,
                            "element \"{$element['label']}\" / option \"{$option['label']}\"",
                            'The option text has a blank to fill in but declares no value slot, '
                            . 'so there is nothing to type into.',
                            'Add "value_slots:" with the type, and the max and suffix where '
                            . 'they apply - e.g. type: numeric, max: 10, suffix: "/10".',
                            $template['_file']
                        );
                    }
                    if (!$promisesSlot && $option['value_slots'] !== []) {
                        $issues[] = Issue::warning(
                            $this->code(), $entry,
                            "element \"{$element['label']}\" / option \"{$option['label']}\"",
                            'The option declares a value slot but its text shows no blank, so '
                            . 'the entry field will have no visible place in the rendered line.',
                            'Put "__" in the label where the value belongs, or remove the slot.',
                            $template['_file']
                        );
                    }
                }
            }
        }

        // --- plan options: a required parameter with nothing to choose from
        foreach ($library->planGroups as $group) {
            foreach ($group['options'] as $option) {
                foreach ($option['parameters'] as $param) {
                    if ($param['required'] && $param['options'] === [] && $param['vocabulary'] === null) {
                        $issues[] = Issue::error(
                            $this->code(), Context::describe($group),
                            "plan option \"{$option['label']}\" / parameter \"{$param['key']}\"",
                            'This parameter is required but offers no values.',
                            'Give it "options:" or a "vocabulary:". A required parameter with '
                            . 'no values cannot be satisfied, so the option cannot be used.',
                            $group['_file']
                        );
                    }
                }
                $narrative = (string) ($option['narrative_template'] ?? '');
                foreach ($option['parameters'] as $param) {
                    $token = '@' . strtoupper($param['key']) . '@';
                    if ($narrative !== '' && $param['required'] && !str_contains($narrative, $token)
                        && !str_contains($narrative, '{' . $param['key'] . '}')) {
                        $issues[] = Issue::warning(
                            $this->code(), Context::describe($group),
                            "plan option \"{$option['label']}\"",
                            sprintf(
                                'Required parameter "%s" is never used in the narrative, so the '
                                . 'value the provider picks does not appear in the note.',
                                $param['key']
                            ),
                            "Reference it as {$token} in \"narrative_template\", or make the "
                            . 'parameter optional if it only drives the order.',
                            $group['_file']
                        );
                    }
                }
            }
        }

        return $issues;
    }

    private function isMonitoringTemplate(array $template): bool
    {
        $name = strtolower((string) $template['name']);
        foreach (['monitor', 'compliance', 'cot', 'controlled', 'opioid', 'medication management'] as $m) {
            if (str_contains($name, $m)) {
                return true;
            }
        }
        return false;
    }

    private function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (preg_match('/\b' . preg_quote($n, '/') . '\b/', $haystack) === 1) {
                return true;
            }
        }
        return false;
    }
}
