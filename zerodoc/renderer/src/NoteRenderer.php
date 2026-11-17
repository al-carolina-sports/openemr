<?php

declare(strict_types=1);

namespace Aem\Renderer;

use Aem\Renderer\Contracts\PatientContext;
use Aem\Renderer\Contracts\Section;
use Aem\Renderer\Contracts\TextSection;

/**
 * Assembles the whole note, in the structure of spec 06 §5.6.
 *
 * Public entry point of the package. Pure: sections in, text out, no
 * database, no OpenEMR, no globals, no clock.
 *
 * §5.7 is enforced here: exactly one rendered note, always complete, and a
 * duplicate or near-duplicate section heading raises instead of producing
 * two sections with confusable names.
 */
final class NoteRenderer
{
    private SectionRenderer $sections;

    public function __construct(
        private readonly TemplateMap $map,
        private readonly PatientContext $patient = new PatientContext(),
        private readonly RenderOptions $defaults = new RenderOptions(),
        private readonly bool $includeInternalNotes = false,
    ) {
        $this->sections = new SectionRenderer($map, $patient, $defaults, $includeInternalNotes);
    }

    public function sectionRenderer(): SectionRenderer
    {
        return $this->sections;
    }

    /**
     * @param array<string,string>              $header   label => value, e.g. ['PATIENT' => '...']
     * @param list<Section|TextSection>         $sections in the order they appear in the note
     * @param string|null                       $signature rendered as the closing "SIGNED" line
     */
    public function render(array $header, array $sections, ?string $signature = null): string
    {
        $blocks = [];

        $headerLines = [];
        foreach ($header as $label => $value) {
            $headerLines[] = str_pad((string) $label, 10) . $value;
        }
        if ($headerLines !== []) {
            $blocks[] = implode("\n", $headerLines);
        }

        $seen = [];
        foreach ($sections as $section) {
            $heading = $section->heading();
            $key = $this->headingKey($heading);
            if (isset($seen[$key])) {
                throw new \LogicException(
                    "Duplicate or near-duplicate section heading: \"{$heading}\" collides with "
                    . "\"{$seen[$key]}\". Spec 06 §5.7 allows one rendered note with one name per section."
                );
            }
            $seen[$key] = $heading;

            $text = $section instanceof Section
                ? $this->sections->render($section)
                : $this->renderTextSection($section);

            if (trim($text) !== '') {
                $blocks[] = $text;
            }
        }

        if ($signature !== null && $signature !== '') {
            $blocks[] = 'SIGNED  ' . $signature;
        }

        return implode("\n\n", $blocks) . "\n";
    }

    private function renderTextSection(TextSection $section): string
    {
        $lines = array_values(array_filter($section->lines, static fn ($l) => trim((string) $l) !== ''));
        if ($lines === []) {
            return '';
        }

        return $section->heading() . "\n" . implode("\n", $lines);
    }

    /**
     * Normalises a heading for collision detection: the leading title only,
     * case- and punctuation-insensitive. "Assessments" and "Assessment /
     * Plan" are distinct; "ASSESSMENT / PLAN" and "Assessment/Plan" are not.
     */
    private function headingKey(string $heading): string
    {
        $title = preg_split('/\s+—\s+|\s+\(/u', $heading)[0] ?? $heading;
        $title = strtolower($title);

        return preg_replace('/[^a-z0-9]+/', '', $title) ?? $title;
    }
}
