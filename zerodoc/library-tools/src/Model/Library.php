<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Model;

use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Yaml\Yaml;
use ZeroDoc\LibraryTools\Yaml\YamlException;

/**
 * In-memory representation of one or more library files.
 *
 * The loader does three things and nothing more:
 *   1. reads the YAML/JSON,
 *   2. checks the *shape* of the format (unknown keys, wrong types, missing
 *      required fields) - these are hard STRUCTURE errors, reported with the
 *      file and the offending entry name,
 *   3. normalises each entry so the validators and the importer can assume a
 *      canonical shape.
 *
 * Clinical/library-quality judgements live in src/Validation/Rules.
 */
final class Library
{
    /** @var list<array<string,mixed>> */
    public array $templates = [];
    /** @var list<array<string,mixed>> */
    public array $savedFindings = [];
    /** @var list<array<string,mixed>> */
    public array $diagnosisSets = [];
    /** @var list<array<string,mixed>> */
    public array $planGroups = [];
    /** @var list<array<string,mixed>> */
    public array $orderSets = [];
    /** @var array<string,list<string>> extra practice-local vocabularies */
    public array $localVocabularies = [];
    /** @var array<string,mixed> */
    public array $meta = [];
    /** @var list<Issue> */
    public array $structureIssues = [];
    /** @var list<string> */
    public array $files = [];

    public const SECTION_KEYS = [
        'reason', 'vitals', 'allergies', 'medications', 'problems', 'family_hx',
        'social_hx', 'surgical_hx', 'past_medical_hx', 'screening', 'hpi', 'ros',
        'pe', 'assessment_plan', 'orders', 'procedures', 'time_spent',
        'patient_instructions', 'discussion_notes', 'follow_up',
    ];
    public const OWNER_SCOPES  = ['system', 'practice', 'provider'];
    public const POLARITIES    = ['positive', 'negative', 'neutral'];
    public const LATERALITIES   = ['right', 'left', 'bilateral', 'n/a'];
    public const ITEM_TYPES    = [
        'diagnosis', 'procedure', 'imaging', 'lab', 'referral', 'education',
        'consent', 'medication',
    ];
    public const PRESENTATION_MODES = ['insert_all', 'pick_list'];

    public static function load(string ...$paths): self
    {
        $lib = new self();
        $expanded = [];
        foreach ($paths as $p) {
            if (is_dir($p)) {
                foreach (glob(rtrim($p, '/') . '/*.{yaml,yml,json}', GLOB_BRACE) ?: [] as $f) {
                    $expanded[] = $f;
                }
            } else {
                $expanded[] = $p;
            }
        }
        sort($expanded);
        foreach ($expanded as $file) {
            $lib->files[] = $file;
            try {
                $data = Yaml::parseFile($file);
            } catch (YamlException $e) {
                $lib->structureIssues[] = Issue::error(
                    'STRUCTURE', basename($file), 'file',
                    'The file could not be parsed: ' . $e->getMessage(),
                    'Fix the YAML at the line named above. Indent with spaces only, two per level.'
                );
                continue;
            }
            $lib->absorb($data, basename($file));
        }
        return $lib;
    }

    /** @param array<string,mixed> $data */
    private function absorb(array $data, string $file): void
    {
        $known = ['library', 'vocabularies', 'templates', 'saved_findings',
                  'diagnosis_sets', 'plan_groups', 'order_sets'];
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $known, true)) {
                $this->structureIssues[] = Issue::error(
                    'STRUCTURE', $file, "top-level key \"{$k}\"",
                    "\"{$k}\" is not a section of this format.",
                    'Allowed top-level keys: ' . implode(', ', $known) . '.'
                );
            }
        }
        if (isset($data['library']) && is_array($data['library'])) {
            $this->meta = array_merge($this->meta, $data['library']);
        }
        foreach ((array) ($data['vocabularies'] ?? []) as $name => $opts) {
            if (!is_array($opts)) {
                $this->structureIssues[] = Issue::error(
                    'STRUCTURE', $file, "vocabulary \"{$name}\"",
                    'A vocabulary must be a list of option labels.',
                    'Write it as:  my_scale: [first, second, third]'
                );
                continue;
            }
            $this->localVocabularies[(string) $name] = array_map(
                static fn ($v) => (string) $v,
                array_values($opts)
            );
        }
        foreach ($this->seq($data, 'templates', $file) as $i => $t) {
            $this->templates[] = $this->normaliseTemplate($t, $file, $i);
        }
        foreach ($this->seq($data, 'saved_findings', $file) as $i => $s) {
            $this->savedFindings[] = $this->normaliseSavedFinding($s, $file, $i);
        }
        foreach ($this->seq($data, 'diagnosis_sets', $file) as $i => $d) {
            $this->diagnosisSets[] = $this->normaliseDiagnosisSet($d, $file, $i);
        }
        foreach ($this->seq($data, 'plan_groups', $file) as $i => $p) {
            $this->planGroups[] = $this->normalisePlanGroup($p, $file, $i);
        }
        foreach ($this->seq($data, 'order_sets', $file) as $i => $o) {
            $this->orderSets[] = $this->normaliseOrderSet($o, $file, $i);
        }
    }

    /** @return list<array<string,mixed>> */
    private function seq(array $data, string $key, string $file): array
    {
        $v = $data[$key] ?? [];
        if ($v === null) {
            return [];
        }
        if (!is_array($v) || ($v !== [] && !array_is_list($v))) {
            $this->structureIssues[] = Issue::error(
                'STRUCTURE', $file, $key,
                "\"{$key}\" must be a list of entries.",
                "Each entry starts with \"- \" at the same indentation."
            );
            return [];
        }
        $out = [];
        foreach ($v as $i => $entry) {
            if (!is_array($entry)) {
                $this->structureIssues[] = Issue::error(
                    'STRUCTURE', $file, "{$key}[" . ($i + 1) . ']',
                    'This entry is not a mapping of fields.',
                    'An entry looks like:  - name: Something'
                );
                continue;
            }
            $out[] = $entry;
        }
        return $out;
    }

    private function require(array $e, string $field, string $file, string $where, string $hint = ''): void
    {
        if (($e[$field] ?? null) === null || $e[$field] === '') {
            $this->structureIssues[] = Issue::error(
                'STRUCTURE', $file, $where,
                "Required field \"{$field}\" is missing or empty.",
                $hint !== '' ? $hint : "Add \"{$field}:\" to this entry."
            );
        }
    }

    private function enum(array $e, string $field, array $allowed, string $file, string $where, bool $required = true): void
    {
        $v = $e[$field] ?? null;
        if ($v === null) {
            if ($required) {
                $this->require($e, $field, $file, $where);
            }
            return;
        }
        if (!in_array((string) $v, $allowed, true)) {
            $this->structureIssues[] = Issue::error(
                'STRUCTURE', $file, $where,
                "\"{$field}: {$v}\" is not one of the allowed values.",
                'Allowed: ' . implode(' | ', $allowed) . '.'
            );
        }
    }

    private function unknownKeys(array $e, array $allowed, string $file, string $where): void
    {
        foreach (array_keys($e) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                $this->structureIssues[] = Issue::error(
                    'STRUCTURE', $file, $where,
                    "\"{$k}\" is not a field of this entry type.",
                    'Allowed fields: ' . implode(', ', $allowed) . '. '
                    . 'If you need a field that does not exist, record it in NOTES.md '
                    . 'instead of inventing one - the schema is owned elsewhere.'
                );
            }
        }
    }

    // ------------------------------------------------------------- templates

    private function normaliseTemplate(array $t, string $file, int $i): array
    {
        $where = 'template "' . ($t['name'] ?? '#' . ($i + 1)) . '"';
        $this->unknownKeys($t, [
            'key', 'name', 'specialty', 'body_region', 'section_key', 'is_active',
            'owner_scope', 'applies_to_sex', 'applies_to_age_range', 'elements',
            'notes',
        ], $file, $where);
        $this->require($t, 'name', $file, $where);
        $this->require($t, 'section_key', $file, $where);
        $this->enum($t, 'section_key', self::SECTION_KEYS, $file, $where);
        $this->enum($t, 'owner_scope', self::OWNER_SCOPES, $file, $where, false);
        $this->enum($t, 'applies_to_sex', ['any', 'male', 'female'], $file, $where, false);

        $elements = [];
        $raw = $t['elements'] ?? [];
        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) {
            $this->structureIssues[] = Issue::error(
                'STRUCTURE', $file, $where,
                '"elements" must be a list.',
                'Each element starts with "- label: ...".'
            );
            $raw = [];
        }
        foreach ($raw as $n => $el) {
            if (!is_array($el)) {
                continue;
            }
            $elements[] = $this->normaliseElement($el, $file, $where, $n);
        }

        return [
            '_file'      => $file,
            '_type'      => 'template',
            'key'        => (string) ($t['key'] ?? self::slug((string) ($t['name'] ?? ''))),
            'name'       => (string) ($t['name'] ?? ''),
            'specialty'  => $t['specialty'] ?? null,
            'body_region' => $t['body_region'] ?? null,
            'section_key' => (string) ($t['section_key'] ?? ''),
            'is_active'  => (bool) ($t['is_active'] ?? true),
            'owner_scope' => (string) ($t['owner_scope'] ?? 'practice'),
            'applies_to_sex' => (string) ($t['applies_to_sex'] ?? 'any'),
            'applies_to_age_range' => $t['applies_to_age_range'] ?? null,
            'notes'      => $t['notes'] ?? null,
            'elements'   => $elements,
        ];
    }

    private function normaliseElement(array $el, string $file, string $parent, int $n): array
    {
        $where = $parent . ' / element "' . ($el['label'] ?? '#' . ($n + 1)) . '"';
        $this->unknownKeys($el, [
            'label', 'sort_order', 'allows_multi', 'render_prefix', 'options',
            'vocabulary', 'side', 'pair_key', 'notes',
        ], $file, $where);
        $this->require($el, 'label', $file, $where);
        $this->enum($el, 'side', ['right', 'left', 'bilateral'], $file, $where, false);

        if (isset($el['options']) && isset($el['vocabulary'])) {
            $this->structureIssues[] = Issue::error(
                'STRUCTURE', $file, $where,
                'This element has both "vocabulary" and "options".',
                'Use one. "vocabulary: name" when the option list is a shared '
                . 'vocabulary; "options:" only for lists unique to this element.'
            );
        }

        $options = [];
        foreach ((array) ($el['options'] ?? []) as $m => $op) {
            if (is_string($op)) {
                $op = ['label' => $op];
            }
            if (!is_array($op)) {
                continue;
            }
            $ow = $where . ' / option "' . ($op['label'] ?? '#' . ($m + 1)) . '"';
            $this->unknownKeys($op, [
                'label', 'polarity', 'sort_order', 'value_slots', 'excludes', 'notes',
            ], $file, $ow);
            $this->require($op, 'label', $file, $ow);
            $this->enum($op, 'polarity', self::POLARITIES, $file, $ow, false);
            $slots = [];
            foreach ((array) ($op['value_slots'] ?? []) as $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                $this->unknownKeys($slot, ['type', 'max', 'min', 'suffix', 'unit', 'options'], $file, $ow . ' / value_slot');
                $this->enum($slot, 'type', ['numeric', 'text', 'date', 'duration', 'percent'], $file, $ow . ' / value_slot');
                $slots[] = $slot;
            }
            $options[] = [
                'label'       => (string) ($op['label'] ?? ''),
                'polarity'    => (string) ($op['polarity'] ?? 'neutral'),
                'sort_order'  => (int) ($op['sort_order'] ?? ($m + 1)),
                'value_slots' => $slots,
                'excludes'    => array_map('strval', (array) ($op['excludes'] ?? [])),
                'notes'       => $op['notes'] ?? null,
            ];
        }

        return [
            'label'        => (string) ($el['label'] ?? ''),
            'sort_order'   => (int) ($el['sort_order'] ?? ($n + 1)),
            'allows_multi' => (bool) ($el['allows_multi'] ?? true),
            'render_prefix' => $el['render_prefix'] ?? null,
            'vocabulary'   => isset($el['vocabulary']) ? (string) $el['vocabulary'] : null,
            'side'         => isset($el['side']) ? (string) $el['side'] : null,
            'pair_key'     => isset($el['pair_key']) ? (string) $el['pair_key'] : null,
            'notes'        => $el['notes'] ?? null,
            'options'      => $options,
        ];
    }

    // --------------------------------------------------------- saved findings

    private function normaliseSavedFinding(array $s, string $file, int $i): array
    {
        $where = 'saved finding "' . ($s['name'] ?? '#' . ($i + 1)) . '"';
        $this->unknownKeys($s, [
            'key', 'name', 'section_key', 'owner_scope', 'is_active', 'laterality',
            'templates', 'presets', 'notes',
        ], $file, $where);
        $this->require($s, 'name', $file, $where);
        $this->require($s, 'section_key', $file, $where);
        $this->enum($s, 'section_key', self::SECTION_KEYS, $file, $where);
        $this->enum($s, 'owner_scope', self::OWNER_SCOPES, $file, $where, false);
        $this->enum($s, 'laterality', self::LATERALITIES, $file, $where, false);

        $templates = [];
        foreach ((array) ($s['templates'] ?? []) as $n => $ref) {
            if (is_string($ref)) {
                $ref = ['template' => $ref];
            }
            if (!is_array($ref)) {
                continue;
            }
            $this->unknownKeys($ref, ['template', 'sort_order'], $file, $where . ' / templates');
            $this->require($ref, 'template', $file, $where . ' / templates');
            $templates[] = [
                'template'   => (string) ($ref['template'] ?? ''),
                'sort_order' => (int) ($ref['sort_order'] ?? ($n + 1)),
            ];
        }
        if ($templates === []) {
            $this->structureIssues[] = Issue::error(
                'STRUCTURE', $file, $where,
                'A saved finding must list at least one template.',
                'A saved finding is "template(s) + answers". Add a "templates:" list.'
            );
        }

        $presets = [];
        foreach ((array) ($s['presets'] ?? []) as $n => $p) {
            if (!is_array($p)) {
                continue;
            }
            $pw = $where . ' / preset #' . ($n + 1);
            $this->unknownKeys($p, [
                'template', 'element', 'option', 'value_text', 'value_numeric',
                'laterality', 'anatomic_level', 'notes',
            ], $file, $pw);
            $this->require($p, 'template', $file, $pw);
            $this->require($p, 'element', $file, $pw);
            $this->enum($p, 'laterality', self::LATERALITIES, $file, $pw, false);
            $presets[] = [
                'template'       => (string) ($p['template'] ?? ''),
                'element'        => (string) ($p['element'] ?? ''),
                'option'         => isset($p['option']) ? (string) $p['option'] : null,
                'value_text'     => $p['value_text'] ?? null,
                'value_numeric'  => $p['value_numeric'] ?? null,
                'laterality'     => (string) ($p['laterality'] ?? 'n/a'),
                'anatomic_level' => $p['anatomic_level'] ?? null,
            ];
        }

        return [
            '_file'       => $file,
            '_type'       => 'saved_finding',
            'key'         => (string) ($s['key'] ?? self::slug((string) ($s['name'] ?? ''))),
            'name'        => (string) ($s['name'] ?? ''),
            'section_key' => (string) ($s['section_key'] ?? ''),
            'owner_scope' => (string) ($s['owner_scope'] ?? 'practice'),
            'is_active'   => (bool) ($s['is_active'] ?? true),
            'laterality'  => (string) ($s['laterality'] ?? 'n/a'),
            'notes'       => $s['notes'] ?? null,
            'templates'   => $templates,
            'presets'     => $presets,
        ];
    }

    // --------------------------------------------------------- diagnosis sets

    private function normaliseDiagnosisSet(array $d, string $file, int $i): array
    {
        $where = 'diagnosis set "' . ($d['name'] ?? '#' . ($i + 1)) . '"';
        $this->unknownKeys($d, [
            'code', 'name', 'specialty', 'body_region', 'is_active', 'owner_scope',
            'presentation_mode', 'laterality_param', 'items', 'notes',
        ], $file, $where);
        $this->require($d, 'code', $file, $where, 'Every set carries a catalog number, e.g. code: "857".');
        $this->require($d, 'name', $file, $where);
        $this->enum($d, 'presentation_mode', self::PRESENTATION_MODES, $file, $where);
        $this->enum($d, 'owner_scope', self::OWNER_SCOPES, $file, $where, false);

        $items = [];
        foreach ((array) ($d['items'] ?? []) as $n => $it) {
            if (!is_array($it)) {
                continue;
            }
            $iw = $where . ' / item "' . ($it['display_name'] ?? $it['icd10_code'] ?? '#' . ($n + 1)) . '"';
            $this->unknownKeys($it, [
                'icd10_code', 'display_name', 'laterality', 'anatomic_level',
                'default_plan_text', 'default_selected', 'sort_order',
                'linked_order_set', 'linked_education', 'notes',
            ], $file, $iw);
            $this->require($it, 'icd10_code', $file, $iw);
            $this->require($it, 'display_name', $file, $iw);
            $this->enum($it, 'laterality', self::LATERALITIES, $file, $iw, false);
            $items[] = [
                'icd10_code'        => strtoupper(trim((string) ($it['icd10_code'] ?? ''))),
                'display_name'      => (string) ($it['display_name'] ?? ''),
                'laterality'        => (string) ($it['laterality'] ?? 'n/a'),
                'anatomic_level'    => $it['anatomic_level'] ?? null,
                'default_plan_text' => $it['default_plan_text'] ?? null,
                'default_selected'  => (bool) ($it['default_selected'] ?? false),
                'sort_order'        => (int) ($it['sort_order'] ?? ($n + 1)),
                'linked_order_set'  => isset($it['linked_order_set']) ? (string) $it['linked_order_set'] : null,
                'linked_education'  => array_map('strval', (array) ($it['linked_education'] ?? [])),
            ];
        }
        if ($items === []) {
            $this->structureIssues[] = Issue::error(
                'STRUCTURE', $file, $where,
                'A diagnosis set has no items.',
                'An empty set is the defect being replaced: a set that carries a '
                . 'name and nothing usable. Add its codes under "items:".'
            );
        }

        return [
            '_file'             => $file,
            '_type'             => 'diagnosis_set',
            'code'              => trim((string) ($d['code'] ?? '')),
            'key'               => (string) ($d['code'] ?? ''),
            'name'              => (string) ($d['name'] ?? ''),
            'specialty'         => $d['specialty'] ?? null,
            'body_region'       => $d['body_region'] ?? null,
            'is_active'         => (bool) ($d['is_active'] ?? true),
            'owner_scope'       => (string) ($d['owner_scope'] ?? 'practice'),
            'presentation_mode' => (string) ($d['presentation_mode'] ?? 'pick_list'),
            'laterality_param'  => (bool) ($d['laterality_param'] ?? false),
            'notes'             => $d['notes'] ?? null,
            'items'             => $items,
        ];
    }

    // ----------------------------------------------------------- plan groups

    private function normalisePlanGroup(array $p, string $file, int $i): array
    {
        $where = 'plan group "' . ($p['name'] ?? '#' . ($i + 1)) . '"';
        $this->unknownKeys($p, ['key', 'name', 'is_active', 'codes', 'options', 'notes'], $file, $where);
        $this->require($p, 'name', $file, $where);

        $codes = array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            (array) ($p['codes'] ?? [])
        );
        if ($codes === []) {
            $this->structureIssues[] = Issue::error(
                'STRUCTURE', $file, $where,
                'A plan group lists no ICD-10 codes.',
                'A plan group is reached from a diagnosis code. List every code '
                . 'that should show these options, e.g. codes: [M54.16, M54.17].'
            );
        }

        $options = [];
        foreach ((array) ($p['options'] ?? []) as $n => $o) {
            if (!is_array($o)) {
                continue;
            }
            $ow = $where . ' / plan option "' . ($o['label'] ?? '#' . ($n + 1)) . '"';
            $this->unknownKeys($o, [
                'label', 'sort_order', 'narrative_template', 'creates_order_type',
                'sets_followup', 'queues_consent', 'parameters', 'notes',
            ], $file, $ow);
            $this->require($o, 'label', $file, $ow);
            $params = [];
            foreach ((array) ($o['parameters'] ?? []) as $prm) {
                if (!is_array($prm)) {
                    continue;
                }
                $this->unknownKeys($prm, ['key', 'options', 'vocabulary', 'required', 'default'], $file, $ow . ' / parameter');
                $this->require($prm, 'key', $file, $ow . ' / parameter');
                if (isset($prm['options']) && isset($prm['vocabulary'])) {
                    $this->structureIssues[] = Issue::error(
                        'STRUCTURE', $file, $ow . ' / parameter "' . ($prm['key'] ?? '?') . '"',
                        'A parameter has both "vocabulary" and "options".',
                        'Use one.'
                    );
                }
                $params[] = [
                    'key'        => (string) ($prm['key'] ?? ''),
                    'options'    => array_map('strval', (array) ($prm['options'] ?? [])),
                    'vocabulary' => isset($prm['vocabulary']) ? (string) $prm['vocabulary'] : null,
                    'required'   => (bool) ($prm['required'] ?? false),
                    'default'    => $prm['default'] ?? null,
                ];
            }
            $options[] = [
                'label'              => (string) ($o['label'] ?? ''),
                'sort_order'         => (int) ($o['sort_order'] ?? ($n + 1)),
                'narrative_template' => $o['narrative_template'] ?? null,
                'creates_order_type' => isset($o['creates_order_type']) ? (string) $o['creates_order_type'] : null,
                'sets_followup'      => (bool) ($o['sets_followup'] ?? false),
                'queues_consent'     => isset($o['queues_consent']) ? (string) $o['queues_consent'] : null,
                'parameters'         => $params,
            ];
        }

        return [
            '_file'     => $file,
            '_type'     => 'plan_group',
            'key'       => (string) ($p['key'] ?? self::slug((string) ($p['name'] ?? ''))),
            'name'      => (string) ($p['name'] ?? ''),
            'is_active' => (bool) ($p['is_active'] ?? true),
            'notes'     => $p['notes'] ?? null,
            'codes'     => $codes,
            'options'   => $options,
        ];
    }

    // ------------------------------------------------------------ order sets

    private function normaliseOrderSet(array $o, string $file, int $i): array
    {
        $where = 'order set "' . ($o['name'] ?? '#' . ($i + 1)) . '"';
        $this->unknownKeys($o, ['code', 'name', 'is_active', 'items', 'notes'], $file, $where);
        $this->require($o, 'code', $file, $where, 'Every set is numbered, e.g. code: "511".');
        $this->require($o, 'name', $file, $where);

        $items = [];
        foreach ((array) ($o['items'] ?? []) as $n => $it) {
            if (!is_array($it)) {
                continue;
            }
            $iw = $where . ' / item #' . ($n + 1);
            $this->unknownKeys($it, [
                'item_type', 'reference', 'display_name', 'laterality',
                'anatomic_level', 'default_quantity', 'sort_order', 'notes',
            ], $file, $iw);
            $this->enum($it, 'item_type', self::ITEM_TYPES, $file, $iw);
            $this->require($it, 'reference', $file, $iw,
                'Point at what the item is: an ICD-10 code for a diagnosis item, '
                . 'or the catalog key of the procedure / imaging / lab / referral.');
            $this->enum($it, 'laterality', self::LATERALITIES, $file, $iw, false);
            $items[] = [
                'item_type'        => (string) ($it['item_type'] ?? ''),
                'reference'        => (string) ($it['reference'] ?? ''),
                'display_name'     => $it['display_name'] ?? null,
                'laterality'       => (string) ($it['laterality'] ?? 'n/a'),
                'anatomic_level'   => $it['anatomic_level'] ?? null,
                'default_quantity' => (int) ($it['default_quantity'] ?? 1),
                'sort_order'       => (int) ($it['sort_order'] ?? ($n + 1)),
            ];
        }
        if ($items === []) {
            $this->structureIssues[] = Issue::error(
                'STRUCTURE', $file, $where,
                'An order set has no items.',
                'A set with no items is the defect being replaced. Add "items:".'
            );
        }

        return [
            '_file'     => $file,
            '_type'     => 'order_set',
            'code'      => trim((string) ($o['code'] ?? '')),
            'key'       => (string) ($o['code'] ?? ''),
            'name'      => (string) ($o['name'] ?? ''),
            'is_active' => (bool) ($o['is_active'] ?? true),
            'notes'     => $o['notes'] ?? null,
            'items'     => $items,
        ];
    }

    // -------------------------------------------------------------- helpers

    /** @return list<array<string,mixed>> every entry, of every type */
    public function allEntries(): array
    {
        return array_merge(
            $this->templates, $this->savedFindings, $this->diagnosisSets,
            $this->planGroups, $this->orderSets
        );
    }

    public function templateByKey(string $keyOrName): ?array
    {
        foreach ($this->templates as $t) {
            if ($t['key'] === $keyOrName || $t['name'] === $keyOrName) {
                return $t;
            }
        }
        return null;
    }

    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
        return trim($s, '_');
    }

    public function counts(): array
    {
        return [
            'templates'      => count($this->templates),
            'saved_findings' => count($this->savedFindings),
            'diagnosis_sets' => count($this->diagnosisSets),
            'plan_groups'    => count($this->planGroups),
            'order_sets'     => count($this->orderSets),
        ];
    }
}
