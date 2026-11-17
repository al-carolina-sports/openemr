<?php

declare(strict_types=1);

namespace Aem\Renderer\Contracts;

/**
 * A note section whose content is not made of `finding` rows: vitals,
 * allergies, medications, problems, assessment/plan, orders, education,
 * time spent, follow-up, and the signature line.
 *
 * Those sections are owned by other work packages. The renderer does not
 * reach into them; the caller passes already-composed lines and this class
 * only supplies the heading and the blank-line discipline, so the note is
 * assembled in one place and there is exactly one rendered note (§5.7).
 */
final class TextSection
{
    /** @param list<string> $lines */
    public function __construct(
        public readonly string $section_key,
        public readonly array $lines = [],
        public readonly ?string $title = null,
        public readonly ?string $title_suffix = null,
    ) {
    }

    public function heading(): string
    {
        $title = $this->title ?? Section::TITLES[$this->section_key] ?? strtoupper($this->section_key);
        if ($this->title_suffix !== null && $this->title_suffix !== '') {
            $title .= ' ' . $this->title_suffix;
        }

        return $title;
    }
}
