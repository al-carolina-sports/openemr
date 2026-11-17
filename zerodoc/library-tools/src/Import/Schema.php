<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Import;

/**
 * The table and column names this package reads and writes.
 *
 * ZeroDoc Clinical Encounter Workflow. Every table carries the module prefix
 * `zdoc_`. The columns are exactly those defined in spec 06 §1.4 / §1.5, 07
 * §11.2.4 and 08 §12.3 - nothing added.
 *
 * The DDL itself is owned elsewhere (the contracts and migrations package). This
 * file is a read-only mirror of it: if a name here disagrees with the migrations,
 * the migrations are right and this file is the bug. Fields the format carries
 * that have no column are listed in DEFERRED and reported at import time rather
 * than invented as columns - see NOTES.md.
 */
final class Schema
{
    public const PREFIX = 'zdoc_';

    public const TEMPLATE        = self::PREFIX . 'template';
    public const ELEMENT         = self::PREFIX . 'template_element';
    public const OPTION          = self::PREFIX . 'template_option';
    public const SAVED_FINDING   = self::PREFIX . 'saved_finding';
    public const SF_TEMPLATE     = self::PREFIX . 'saved_finding_template';
    public const SF_PRESET       = self::PREFIX . 'saved_finding_preset';
    public const DIAGNOSIS_SET   = self::PREFIX . 'diagnosis_set';
    public const DS_ITEM         = self::PREFIX . 'diagnosis_set_item';
    public const PLAN_GROUP      = self::PREFIX . 'plan_group';
    public const PG_CODE         = self::PREFIX . 'plan_group_code';
    public const PLAN_OPTION     = self::PREFIX . 'plan_option';
    public const ORDER_SET       = self::PREFIX . 'order_set';
    public const OS_ITEM         = self::PREFIX . 'order_set_item';

    /** @var array<string,list<string>> table => insertable columns */
    public const COLUMNS = [
        self::TEMPLATE => [
            'name', 'specialty', 'body_region', 'section_key', 'is_active', 'owner_scope',
        ],
        self::ELEMENT => [
            'template_id', 'label', 'sort_order', 'allows_multi', 'render_prefix',
        ],
        self::OPTION => [
            'element_id', 'label', 'polarity', 'sort_order', 'value_slots',
            'excludes_option_ids',
        ],
        self::SAVED_FINDING => [
            'name', 'section_key', 'owner_scope', 'is_active',
        ],
        self::SF_TEMPLATE => [
            'saved_finding_id', 'template_id', 'sort_order',
        ],
        self::SF_PRESET => [
            'saved_finding_id', 'element_id', 'option_id', 'value_text',
            'value_numeric', 'laterality', 'anatomic_level',
        ],
        self::DIAGNOSIS_SET => [
            'code', 'name', 'specialty', 'body_region', 'is_active', 'owner_scope',
            'presentation_mode', 'laterality_param',
        ],
        self::DS_ITEM => [
            'diagnosis_set_id', 'icd10_code', 'display_name', 'laterality',
            'anatomic_level', 'default_plan_text', 'default_selected', 'sort_order',
            'linked_order_set_id', 'linked_education_ids',
        ],
        self::PLAN_GROUP => [
            'name', 'is_active',
        ],
        self::PG_CODE => [
            'plan_group_id', 'icd10_code',
        ],
        self::PLAN_OPTION => [
            'plan_group_id', 'label', 'sort_order', 'narrative_template',
            'creates_order_type_id', 'sets_followup', 'queues_consent_id', 'parameters',
        ],
        self::ORDER_SET => [
            'code', 'name', 'is_active',
        ],
        self::OS_ITEM => [
            'order_set_id', 'item_type', 'reference_id', 'laterality',
            'anatomic_level', 'default_quantity', 'sort_order',
        ],
    ];

    /**
     * Fields the import format carries deliberately, for which no column exists
     * in the schema as specified. The importer records them in a deferred-fields
     * report instead of dropping them silently or adding a column.
     *
     * @var array<string,array{gap:string,note:string}>
     */
    public const DEFERRED = [
        'template.applies_to_sex' => [
            'gap'  => 'G1',
            'note' => '06 §1.5 requires templates to be sex-filtered at application '
                    . 'time, but §1.4 defines no applies_to_sex column.',
        ],
        'template.applies_to_age_range' => [
            'gap'  => 'G1',
            'note' => '06 §1.5 requires age filtering; §1.4 defines no column.',
        ],
        'template_element.vocabulary' => [
            'gap'  => 'G2',
            'note' => 'Appendix B requires elements to reference a vocabulary rather '
                    . 'than restate it; there is no column recording which vocabulary '
                    . 'an element references, so the import expands the options and the '
                    . 'reference is lost in the database.',
        ],
        'template_element.side' => [
            'gap'  => 'G3',
            'note' => 'The symmetry linter pairs elements by side; the side is only '
                    . 'expressible in render_prefix or the label today.',
        ],
        'template_element.pair_key' => [
            'gap'  => 'G3',
            'note' => 'Explicit pairing key for the symmetry linter; no column.',
        ],
        'saved_finding.laterality' => [
            'gap'  => 'G4',
            'note' => '06 §3.4 requires a saved finding to carry laterality as an '
                    . 'attribute; §1.5 puts laterality only on the preset rows.',
        ],
        'template.last_used_at' => [
            'gap'  => 'G5',
            'note' => '06 §3.8 asks for a last_used_at column and an unused-in-N-months '
                    . 'report; not present in §1.4.',
        ],
        'saved_finding.last_used_at' => [
            'gap'  => 'G5',
            'note' => 'As above, for saved findings.',
        ],
        'entry.notes' => [
            'gap'  => 'G6',
            'note' => 'Transcription notes ("flag unclear clinical intent rather than '
                    . 'guessing") have nowhere to live in the schema; they stay in the '
                    . 'YAML and the deferred report.',
        ],
    ];
}
