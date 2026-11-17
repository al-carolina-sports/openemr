<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Audit;

use ZeroDoc\LibraryTools\Model\Library;

/**
 * Spec 06 §1.5: "An admin report MUST list, for every saved finding, which
 * templates it bundles and how many findings it would emit."
 *
 * This class is that report's engine. bin/bundle-audit.php renders it; BundleRule
 * uses the same numbers to fail an import whose bundles overreach.
 *
 * "Findings emitted" is counted the way the encounter will see it: one finding
 * row per preset that resolves, plus - for a bundled template with elements the
 * presets do not touch - nothing, because a blank element emits no finding. That
 * keeps the number honest: it is what lands in the note, not how big the
 * templates are. The element total is reported separately, because a bundle that
 * inserts twelve blank systems is its own problem.
 */
final class BundleAudit
{
    public function __construct(private readonly Library $library)
    {
    }

    /**
     * @return list<array{
     *   finding: array<string,mixed>,
     *   templates: list<array<string,mixed>>,
     *   emitted_findings: int,
     *   total_elements: int,
     *   sex_specific: list<string>,
     *   orphan_presets: list<array{locus:string,message:string}>
     * }>
     */
    public function rows(): array
    {
        $out = [];
        foreach ($this->library->savedFindings as $finding) {
            $templates = [];
            $emitted = 0;
            $elements = 0;
            $sexSpecific = [];
            $orphans = [];

            $bundled = [];
            foreach ($finding['templates'] as $ref) {
                $bundled[$ref['template']] = $ref['sort_order'];
            }

            foreach ($bundled as $ref => $sortOrder) {
                $template = $this->library->templateByKey((string) $ref);
                // Keep the original index so a message can name the preset the
                // way it appears in the file, not its position within one template.
                $presets = array_filter(
                    $finding['presets'],
                    static fn ($p) => $p['template'] === $ref
                );

                if ($template === null) {
                    $templates[] = [
                        'ref' => (string) $ref, 'name' => (string) $ref,
                        'missing' => true, 'element_count' => 0,
                        'option_count' => 0, 'preset_count' => count($presets),
                        'section_key' => '', 'applies_to_sex' => 'any',
                        'sort_order' => $sortOrder, 'untouched_elements' => [],
                    ];
                    continue;
                }

                $elementLabels = array_column($template['elements'], 'label');
                $touched = [];
                $resolved = 0;
                foreach ($presets as $i => $preset) {   // $i is the file-order index
                    $element = $this->findElement($template, $preset['element']);
                    if ($element === null) {
                        $orphans[] = [
                            'locus'   => 'preset #' . ($i + 1) . " -> element \"{$preset['element']}\"",
                            'message' => sprintf(
                                'Template "%s" has no element named "%s", so this preset '
                                . 'would apply nothing. Its elements are: %s.',
                                $template['name'], $preset['element'],
                                $elementLabels === [] ? '(none)' : '"' . implode('", "', $elementLabels) . '"'
                            ),
                        ];
                        continue;
                    }
                    $touched[$element['label']] = true;
                    if ($preset['option'] !== null && !$this->hasOption($element, $preset['option'], $this->library)) {
                        $orphans[] = [
                            'locus'   => "preset #" . ($i + 1) . " -> option \"{$preset['option']}\"",
                            'message' => sprintf(
                                'Element "%s" of template "%s" does not offer the option "%s".',
                                $element['label'], $template['name'], $preset['option']
                            ),
                        ];
                        continue;
                    }
                    $resolved++;
                }

                $untouched = array_values(array_diff($elementLabels, array_keys($touched)));
                $emitted  += $resolved;
                $elements += count($template['elements']);
                if (($template['applies_to_sex'] ?? 'any') !== 'any') {
                    $sexSpecific[] = $template['name'] . ' (' . $template['applies_to_sex'] . ')';
                }

                $templates[] = [
                    'ref'           => (string) $ref,
                    'name'          => $template['name'],
                    'missing'       => false,
                    'element_count' => count($template['elements']),
                    // An element referencing a vocabulary has no option list of
                    // its own in the file; it has as many options as the
                    // vocabulary it points at.
                    'option_count'  => array_sum(array_map(
                        fn ($e) => $e['vocabulary'] !== null
                            ? count((new \ZeroDoc\LibraryTools\Validation\Vocabularies($this->library))
                                ->options($e['vocabulary']))
                            : count($e['options']),
                        $template['elements']
                    )),
                    'preset_count'  => $resolved,
                    'section_key'   => $template['section_key'],
                    'applies_to_sex' => $template['applies_to_sex'] ?? 'any',
                    'sort_order'    => $sortOrder,
                    'untouched_elements' => $untouched,
                ];
            }

            usort($templates, static fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

            $out[] = [
                'finding'          => $finding,
                'templates'        => $templates,
                'emitted_findings' => $emitted,
                'total_elements'   => $elements,
                'sex_specific'     => $sexSpecific,
                'orphan_presets'   => $orphans,
            ];
        }

        usort($out, static fn ($a, $b) => $b['emitted_findings'] <=> $a['emitted_findings']);
        return $out;
    }

    private function findElement(array $template, string $label): ?array
    {
        foreach ($template['elements'] as $element) {
            if (strcasecmp($element['label'], $label) === 0) {
                return $element;
            }
        }
        return null;
    }

    private function hasOption(array $element, string $option, Library $library): bool
    {
        foreach ($element['options'] as $o) {
            if (strcasecmp($o['label'], $option) === 0) {
                return true;
            }
        }
        if ($element['vocabulary'] !== null) {
            $vocab = new \ZeroDoc\LibraryTools\Validation\Vocabularies($library);
            foreach ($vocab->options($element['vocabulary']) as $o) {
                if (strcasecmp($o, $option) === 0) {
                    return true;
                }
            }
        }
        return false;
    }
}
