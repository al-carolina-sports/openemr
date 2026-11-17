<?php

declare(strict_types=1);

namespace Aem\Renderer\Contracts;

use Aem\Renderer\RenderOptions;

/**
 * One `encounter_section` (spec 06 §1.2) plus the findings it contains.
 *
 * Only columns the spec defines are carried: section_key, render_mode,
 * note_text, note_visibility. `title` and `template_label` are presentation
 * inputs the caller supplies — §5.6 heads each section
 * "HPI — Pain Management L-spine (reported by patient)", and neither the
 * template name nor the reported-by attribution is a column on
 * `encounter_section`. See README "Fields the renderer needs that the
 * schema does not define".
 */
final class Section
{
    public const MODE_LIST = 'list';
    public const MODE_PARAGRAPH = 'paragraph';

    public const VIS_EVERYONE = 'everyone';
    public const VIS_INTERNAL = 'internal';

    /** Default render modes from spec 06 §5.1. */
    public const DEFAULT_MODES = [
        'hpi' => self::MODE_LIST,
        'ros' => self::MODE_PARAGRAPH,
        'pe'  => self::MODE_LIST,
    ];

    /** Section headings, in §5.6's wording. One name per section (§5.7). */
    public const TITLES = [
        'reason' => 'CHIEF COMPLAINT',
        'vitals' => 'VITALS',
        'allergies' => 'REVIEWED ALLERGIES',
        'medications' => 'REVIEWED MEDICATIONS',
        'problems' => 'PROBLEMS',
        'family_hx' => 'FAMILY HISTORY',
        'social_hx' => 'SOCIAL HISTORY',
        'surgical_hx' => 'SURGICAL HISTORY',
        'past_medical_hx' => 'PAST MEDICAL HISTORY',
        'screening' => 'SCREENING',
        'hpi' => 'HPI',
        'ros' => 'ROS',
        'pe' => 'PHYSICAL EXAM',
        'assessment_plan' => 'ASSESSMENT / PLAN',
        'orders' => 'ORDERS SIGNED',
        'procedures' => 'PROCEDURES',
        'time_spent' => 'TIME SPENT',
        'patient_instructions' => 'PATIENT EDUCATION PROVIDED',
        'discussion_notes' => 'DISCUSSION',
        'follow_up' => 'FOLLOW-UP',
    ];

    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public readonly string $section_key,
        public readonly array $findings = [],
        public readonly ?string $render_mode = null,
        public readonly ?string $note_text = null,
        public readonly string $note_visibility = self::VIS_EVERYONE,
        public readonly ?string $title = null,
        public readonly ?string $template_label = null,
        public readonly ?string $reported_by = null,
        public readonly ?RenderOptions $options = null,
    ) {
    }

    public function mode(): string
    {
        return $this->render_mode
            ?? self::DEFAULT_MODES[$this->section_key]
            ?? self::MODE_LIST;
    }

    public function heading(): string
    {
        $title = $this->title ?? self::TITLES[$this->section_key] ?? strtoupper($this->section_key);
        if ($this->template_label !== null && $this->template_label !== '') {
            $title .= ' — ' . $this->template_label;
        }
        if ($this->reported_by !== null && $this->reported_by !== '') {
            $title .= ' (reported by ' . $this->reported_by . ')';
        }

        return $title;
    }
}
