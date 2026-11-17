<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Export;

use PDO;
use ZeroDoc\LibraryTools\Import\Schema;
use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Vocabularies;
use ZeroDoc\LibraryTools\Yaml\Yaml;

/**
 * Exports the library out of the database and back into the import format.
 *
 * ZeroDoc Clinical Encounter Workflow - WP-06.
 *
 * Two uses:
 *   - review: a reviewer reads the YAML, not the tables
 *   - round-tripping: export, re-import into an empty database, and the two
 *     should agree. tests/run-tests.php does exactly that.
 *
 * Round-trip is lossy in one direction only, and knowably so: the fields listed
 * in Schema::DEFERRED have no column, so an export cannot reproduce them. The
 * exporter writes a `round_trip` note in the library header saying which fields
 * were not recoverable, so nobody mistakes the export for the source of truth
 * while those gaps stand.
 */
final class Exporter
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @return array<string,mixed> the format, as a nested array */
    public function toArray(string $libraryName = 'exported library'): array
    {
        $out = [
            'library' => [
                'name'       => $libraryName,
                'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'round_trip' => 'Fields with no column in the schema cannot be '
                    . 'exported, and are absent here: template.applies_to_sex, '
                    . 'template.applies_to_age_range, template_element.side, '
                    . 'template_element.pair_key, saved_finding.laterality, '
                    . 'last_used_at, notes. Vocabulary references are reconstructed '
                    . 'by matching the stored options. The YAML the content track '
                    . 'wrote stays the source of truth until those columns exist. '
                    . 'See NOTES.md.',
            ],
        ];

        $templates = [];
        $templateNames = [];
        foreach ($this->all(Schema::TEMPLATE) as $t) {
            $templateNames[(int) $t['id']] = $t['name'];
            $elements = [];
            foreach ($this->where(Schema::ELEMENT, 'template_id', (int) $t['id'], 'sort_order') as $e) {
                $options = [];
                $byId = [];
                $rows = $this->where(Schema::OPTION, 'element_id', (int) $e['id'], 'sort_order');
                foreach ($rows as $o) {
                    $byId[(int) $o['id']] = $o['label'];
                }
                foreach ($rows as $o) {
                    $option = [
                        'label'      => $o['label'],
                        'polarity'   => $o['polarity'],
                        'sort_order' => (int) $o['sort_order'],
                    ];
                    if (($o['value_slots'] ?? null) !== null && $o['value_slots'] !== '') {
                        $option['value_slots'] = json_decode((string) $o['value_slots'], true) ?: [];
                    }
                    if (($o['excludes_option_ids'] ?? null) !== null && $o['excludes_option_ids'] !== '') {
                        $ids = json_decode((string) $o['excludes_option_ids'], true) ?: [];
                        $labels = [];
                        foreach ($ids as $id) {
                            if (isset($byId[(int) $id])) {
                                $labels[] = $byId[(int) $id];
                            }
                        }
                        if ($labels !== []) {
                            $option['excludes'] = $labels;
                        }
                    }
                    $options[] = $option;
                }
                $element = [
                    'label'        => $e['label'],
                    'sort_order'   => (int) $e['sort_order'],
                    'allows_multi' => (bool) $e['allows_multi'],
                ];
                if (($e['render_prefix'] ?? null) !== null && $e['render_prefix'] !== '') {
                    $element['render_prefix'] = $e['render_prefix'];
                }
                // There is no column recording which vocabulary an element
                // referenced (NOTES.md gap G2), so the reference is reconstructed
                // by matching the stored options against the vocabularies. An
                // exact match is unambiguous in practice: an element whose
                // options are exactly a vocabulary is required to reference it,
                // so an identical hand-typed copy is not a legal alternative.
                $vocabulary = $this->vocabularyFor(array_column($options, 'label'));
                if ($vocabulary !== null) {
                    $element['vocabulary'] = $vocabulary;
                } else {
                    $element['options'] = $options;
                }
                $elements[] = $element;
            }
            $templates[] = array_filter([
                'name'        => $t['name'],
                'specialty'   => $t['specialty'],
                'body_region' => $t['body_region'],
                'section_key' => $t['section_key'],
                'owner_scope' => $t['owner_scope'],
                'is_active'   => (bool) $t['is_active'],
                'elements'    => $elements,
            ], static fn ($v) => $v !== null && $v !== '');
        }
        if ($templates !== []) {
            $out['templates'] = $templates;
        }

        $findings = [];
        foreach ($this->all(Schema::SAVED_FINDING) as $s) {
            $bundled = [];
            foreach ($this->where(Schema::SF_TEMPLATE, 'saved_finding_id', (int) $s['id'], 'sort_order') as $r) {
                $bundled[] = [
                    'template'   => $templateNames[(int) $r['template_id']] ?? ('#' . $r['template_id']),
                    'sort_order' => (int) $r['sort_order'],
                ];
            }
            $presets = [];
            foreach ($this->where(Schema::SF_PRESET, 'saved_finding_id', (int) $s['id'], 'id') as $p) {
                $element = $this->row(Schema::ELEMENT, (int) $p['element_id']);
                $option  = $p['option_id'] !== null ? $this->row(Schema::OPTION, (int) $p['option_id']) : null;
                $presets[] = array_filter([
                    'template'       => $element !== null
                        ? ($templateNames[(int) $element['template_id']] ?? '') : '',
                    'element'        => $element['label'] ?? '',
                    'option'         => $option['label'] ?? null,
                    'value_text'     => $p['value_text'],
                    'value_numeric'  => $p['value_numeric'],
                    'laterality'     => $p['laterality'],
                    'anatomic_level' => $p['anatomic_level'],
                ], static fn ($v) => $v !== null && $v !== '');
            }
            $findings[] = array_filter([
                'name'        => $s['name'],
                'section_key' => $s['section_key'],
                'owner_scope' => $s['owner_scope'],
                'is_active'   => (bool) $s['is_active'],
                'templates'   => $bundled,
                'presets'     => $presets,
            ], static fn ($v) => $v !== null && $v !== '');
        }
        if ($findings !== []) {
            $out['saved_findings'] = $findings;
        }

        $orderSetCodes = [];
        $orderSets = [];
        foreach ($this->all(Schema::ORDER_SET) as $o) {
            $orderSetCodes[(int) $o['id']] = $o['code'];
            $items = [];
            foreach ($this->where(Schema::OS_ITEM, 'order_set_id', (int) $o['id'], 'sort_order') as $i) {
                $items[] = array_filter([
                    'item_type'        => $i['item_type'],
                    'reference'        => $i['reference_id'],
                    'laterality'       => $i['laterality'],
                    'anatomic_level'   => $i['anatomic_level'],
                    'default_quantity' => (int) $i['default_quantity'],
                    'sort_order'       => (int) $i['sort_order'],
                ], static fn ($v) => $v !== null && $v !== '');
            }
            $orderSets[] = [
                'code'      => (string) $o['code'],
                'name'      => $o['name'],
                'is_active' => (bool) $o['is_active'],
                'items'     => $items,
            ];
        }
        if ($orderSets !== []) {
            $out['order_sets'] = $orderSets;
        }

        $diagnosisSets = [];
        foreach ($this->all(Schema::DIAGNOSIS_SET) as $d) {
            $items = [];
            foreach ($this->where(Schema::DS_ITEM, 'diagnosis_set_id', (int) $d['id'], 'sort_order') as $i) {
                $item = array_filter([
                    'icd10_code'        => $i['icd10_code'],
                    'display_name'      => $i['display_name'],
                    'laterality'        => $i['laterality'],
                    'anatomic_level'    => $i['anatomic_level'],
                    'default_plan_text' => $i['default_plan_text'],
                    'sort_order'        => (int) $i['sort_order'],
                ], static fn ($v) => $v !== null && $v !== '');
                $item['default_selected'] = (bool) $i['default_selected'];
                if ($i['linked_order_set_id'] !== null) {
                    $item['linked_order_set'] = (string) ($orderSetCodes[(int) $i['linked_order_set_id']] ?? '');
                }
                if (($i['linked_education_ids'] ?? null) !== null && $i['linked_education_ids'] !== '') {
                    $item['linked_education'] = json_decode((string) $i['linked_education_ids'], true) ?: [];
                }
                $items[] = $item;
            }
            $diagnosisSets[] = array_filter([
                'code'              => (string) $d['code'],
                'name'              => $d['name'],
                'specialty'         => $d['specialty'],
                'body_region'       => $d['body_region'],
                'owner_scope'       => $d['owner_scope'],
                'presentation_mode' => $d['presentation_mode'],
                'laterality_param'  => (bool) $d['laterality_param'],
                'is_active'         => (bool) $d['is_active'],
                'items'             => $items,
            ], static fn ($v) => $v !== null && $v !== '');
        }
        if ($diagnosisSets !== []) {
            $out['diagnosis_sets'] = $diagnosisSets;
        }

        $planGroups = [];
        foreach ($this->all(Schema::PLAN_GROUP) as $g) {
            $codes = [];
            foreach ($this->where(Schema::PG_CODE, 'plan_group_id', (int) $g['id'], 'id') as $c) {
                $codes[] = $c['icd10_code'];
            }
            $options = [];
            foreach ($this->where(Schema::PLAN_OPTION, 'plan_group_id', (int) $g['id'], 'sort_order') as $o) {
                $option = array_filter([
                    'label'              => $o['label'],
                    'sort_order'         => (int) $o['sort_order'],
                    'narrative_template' => $o['narrative_template'],
                    'creates_order_type' => $o['creates_order_type_id'],
                    'queues_consent'     => $o['queues_consent_id'],
                ], static fn ($v) => $v !== null && $v !== '');
                $option['sets_followup'] = (bool) $o['sets_followup'];
                if (($o['parameters'] ?? null) !== null && $o['parameters'] !== '') {
                    $params = json_decode((string) $o['parameters'], true) ?: [];
                    foreach ($params as $k => $p) {
                        unset($params[$k]['vocabulary']);
                        $vocabulary = $this->vocabularyFor($p['options'] ?? []);
                        if ($vocabulary !== null) {
                            $params[$k]['vocabulary'] = $vocabulary;
                            unset($params[$k]['options']);
                        }
                        $params[$k] = array_filter(
                            $params[$k],
                            static fn ($v) => $v !== null && $v !== [] && $v !== ''
                        );
                    }
                    $option['parameters'] = $params;
                }
                $options[] = $option;
            }
            $planGroups[] = [
                'name'      => $g['name'],
                'is_active' => (bool) $g['is_active'],
                'codes'     => $codes,
                'options'   => $options,
            ];
        }
        if ($planGroups !== []) {
            $out['plan_groups'] = $planGroups;
        }

        return $out;
    }

    public function toYaml(string $libraryName = 'exported library'): string
    {
        $header = "# ZeroDoc Clinical Encounter Workflow - exported template library\n"
                . "# Format: FORMAT.md. Re-importable as written.\n";
        return $header . Yaml::dump($this->toArray($libraryName));
    }

    /**
     * The name of the vocabulary whose members are exactly this option list, or
     * null. Order is ignored; membership is not.
     *
     * @param list<string> $labels
     */
    private function vocabularyFor(array $labels): ?string
    {
        if (count($labels) < 2) {
            return null;
        }
        $mine = array_map(Vocabularies::norm(...), $labels);
        sort($mine);
        foreach (Vocabularies::APPENDIX_B as $name => $members) {
            $theirs = array_map(Vocabularies::norm(...), $members);
            sort($theirs);
            if ($mine === $theirs) {
                return $name;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function all(string $table): array
    {
        return $this->pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function where(string $table, string $column, int $value, string $order): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE {$column} = :v ORDER BY {$order}");
        $stmt->execute([':v' => $value]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function row(string $table, int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
