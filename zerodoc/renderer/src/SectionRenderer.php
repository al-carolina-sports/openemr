<?php

declare(strict_types=1);

namespace Aem\Renderer;

use Aem\Renderer\Contracts\PatientContext;
use Aem\Renderer\Contracts\Section;
use Aem\Renderer\Support\TextWrapper;

/**
 * Renders one findings section: dispatches on `render_mode` (§5.1) and
 * appends the section's free-text NOTE row (§3.3) as a final "Note:" line.
 *
 * An internal-visibility note (§6.4) is omitted unless the caller asks for
 * the internal view.
 */
final class SectionRenderer
{
    public function __construct(
        private readonly TemplateMap $map,
        private readonly PatientContext $patient = new PatientContext(),
        private readonly RenderOptions $defaults = new RenderOptions(),
        private readonly bool $includeInternalNotes = false,
    ) {
    }

    /** Body only, without the heading. */
    public function renderBody(Section $section): string
    {
        $options = $section->options ?? $this->optionsFor($section);

        $body = match ($section->mode()) {
            Section::MODE_PARAGRAPH => (new ParagraphModeRenderer($this->map, $this->patient, $options))
                ->render($section->findings),
            default => (new ListModeRenderer($this->map, $options))
                ->render($section->findings),
        };

        $note = $this->noteLine($section, $options);
        if ($note !== '') {
            $body = $body === '' ? $note : $body . "\n" . $note;
        }

        return $body;
    }

    public function render(Section $section): string
    {
        $body = $this->renderBody($section);
        $heading = $section->heading();

        return $body === '' ? $heading : $heading . "\n" . $body;
    }

    private function noteLine(Section $section, RenderOptions $options): string
    {
        $text = trim((string) $section->note_text);
        if ($text === '') {
            return '';
        }
        if ($section->note_visibility === Section::VIS_INTERNAL && !$this->includeInternalNotes) {
            return '';
        }

        return TextWrapper::wrap('Note: ' . $text, $options->wrapWidth, $options->hangingIndent);
    }

    /** Per-section defaults that reproduce §5.6 without the caller restating them. */
    private function optionsFor(Section $section): RenderOptions
    {
        if ($section->mode() === Section::MODE_PARAGRAPH) {
            return $this->defaults->with(
                polarityGrouping: true,
                minStemWords: 2,
                minItemsForShortStem: PHP_INT_MAX,
            );
        }

        if ($section->section_key === 'pe') {
            return $this->defaults->with(
                listStyle: RenderOptions::LIST_EXAM,
                minStemWords: 2,
                minItemsForShortStem: 3,
                hangingIndent: 2,
            );
        }

        return $this->defaults;
    }
}
