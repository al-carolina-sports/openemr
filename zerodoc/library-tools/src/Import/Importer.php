<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Import;

use PDO;
use PDOException;
use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Report;
use ZeroDoc\LibraryTools\Validation\Validator;
use ZeroDoc\LibraryTools\Validation\Vocabularies;

/**
 * Transactional library importer.
 *
 * ZeroDoc Clinical Encounter Workflow - WP-06.
 *
 * Contract:
 *   - validation runs first, in full, against the whole file set; nothing is
 *     written if there is a single error
 *   - everything else happens inside one transaction, and any failure - a
 *     constraint, an unresolvable reference, a PDO exception - rolls the whole
 *     import back. A half-imported library is worse than none, because the
 *     half that landed looks finished.
 *   - a code or name already present in the database is reported, not merged.
 *     Merging would make the import non-repeatable and hide a duplicate.
 */
final class Importer
{
    /** @var list<string> */
    private array $log = [];
    /** @var list<array{field:string,entry:string,value:string,gap:string,note:string}> */
    private array $deferred = [];
    /** @var array<string,int> */
    private array $stats = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dataDir,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{ok:bool,report:Report,log:list<string>,stats:array<string,int>,
     *                deferred:list<array<string,string>>,error:?string}
     */
    public function import(Library $library, bool $strict = false, bool $dryRun = false): array
    {
        $report = (new Validator($this->dataDir))->run($library);

        if (!$report->passed($strict)) {
            return [
                'ok' => false, 'report' => $report, 'log' => [],
                'stats' => [], 'deferred' => [],
                'error' => $strict
                    ? sprintf('Validation produced %d error(s) and %d warning(s); --strict treats both as blocking. Nothing was written.',
                        count($report->errors()), count($report->warnings()))
                    : sprintf('Validation failed with %d error(s). Nothing was written.',
                        count($report->errors())),
            ];
        }

        $error = null;
        $this->pdo->beginTransaction();
        try {
            $this->assertNoCollisions($library);

            $templateIds  = [];
            $canonicalKey = [];  // a template's key or name => its canonical key
            $elementIds   = [];  // "templateKey\0elementLabel" => id
            $optionIds    = [];  // "templateKey\0elementLabel\0optionLabel" => id

            foreach ($library->templates as $template) {
                $id = $this->insert(Schema::TEMPLATE, [
                    'name'        => $template['name'],
                    'specialty'   => $template['specialty'],
                    'body_region' => $template['body_region'],
                    'section_key' => $template['section_key'],
                    'is_active'   => $template['is_active'] ? 1 : 0,
                    'owner_scope' => $template['owner_scope'],
                ]);
                $templateIds[$template['key']] = $id;
                $templateIds[$template['name']] = $id;
                // A preset may name its template by key or by name; the exporter
                // writes the name, since there is no key column.
                $canonicalKey[$template['key']]  = $template['key'];
                $canonicalKey[$template['name']] = $template['key'];
                $this->count('templates');

                $this->defer('template.applies_to_sex', $template['name'], $template['applies_to_sex'] !== 'any' ? $template['applies_to_sex'] : null);
                $this->defer('template.applies_to_age_range', $template['name'], $template['applies_to_age_range']);
                $this->defer('entry.notes', $template['name'], $template['notes']);

                foreach ($template['elements'] as $element) {
                    $elementId = $this->insert(Schema::ELEMENT, [
                        'template_id'   => $id,
                        'label'         => $element['label'],
                        'sort_order'    => $element['sort_order'],
                        'allows_multi'  => $element['allows_multi'] ? 1 : 0,
                        'render_prefix' => $element['render_prefix'],
                    ]);
                    $elementIds[$template['key'] . "\0" . strtolower($element['label'])] = $elementId;
                    $this->count('elements');

                    $this->defer('template_element.vocabulary', "{$template['name']} / {$element['label']}", $element['vocabulary']);
                    $this->defer('template_element.side', "{$template['name']} / {$element['label']}", $element['side']);
                    $this->defer('template_element.pair_key', "{$template['name']} / {$element['label']}", $element['pair_key']);

                    // Options come from the element's own list, or are expanded
                    // from the vocabulary it references. The expansion is what
                    // keeps every element that uses a scale identical.
                    $options = $element['options'];
                    if ($element['vocabulary'] !== null) {
                        $options = $this->expandVocabulary($library, $element['vocabulary']);
                        $this->note(sprintf(
                            'Expanded vocabulary "%s" into %d options for %s / %s.',
                            $element['vocabulary'], count($options), $template['name'], $element['label']
                        ));
                    }

                    $pendingExcludes = [];
                    foreach ($options as $option) {
                        $optionId = $this->insert(Schema::OPTION, [
                            'element_id'          => $elementId,
                            'label'               => $option['label'],
                            'polarity'            => $option['polarity'],
                            'sort_order'          => $option['sort_order'],
                            'value_slots'         => $option['value_slots'] === []
                                ? null : json_encode($option['value_slots']),
                            'excludes_option_ids' => null,
                        ]);
                        $key = $template['key'] . "\0" . strtolower($element['label'])
                             . "\0" . strtolower($option['label']);
                        $optionIds[$key] = $optionId;
                        $this->count('options');
                        if ($option['excludes'] !== []) {
                            $pendingExcludes[$optionId] = $option['excludes'];
                        }
                    }

                    // excludes_option_ids holds ids, so it is written in a second
                    // pass once every option in the element has one.
                    foreach ($pendingExcludes as $optionId => $labels) {
                        $ids = [];
                        foreach ($labels as $label) {
                            $k = $template['key'] . "\0" . strtolower($element['label'])
                               . "\0" . strtolower($label);
                            if (!isset($optionIds[$k])) {
                                throw new ImportException(sprintf(
                                    'Template "%s", element "%s": option excludes "%s", which does not exist in that element.',
                                    $template['name'], $element['label'], $label
                                ));
                            }
                            $ids[] = $optionIds[$k];
                        }
                        $this->update(Schema::OPTION, $optionId, [
                            'excludes_option_ids' => json_encode($ids),
                        ]);
                    }
                }
            }

            // --- order sets before diagnosis sets: items link to them by id
            $orderSetIds = [];
            foreach ($library->orderSets as $set) {
                $id = $this->insert(Schema::ORDER_SET, [
                    'code'      => $set['code'],
                    'name'      => $set['name'],
                    'is_active' => $set['is_active'] ? 1 : 0,
                ]);
                $orderSetIds[$set['code']] = $id;
                $this->count('order_sets');
                $this->defer('entry.notes', $set['name'], $set['notes']);
                foreach ($set['items'] as $item) {
                    $this->insert(Schema::OS_ITEM, [
                        'order_set_id'     => $id,
                        'item_type'        => $item['item_type'],
                        'reference_id'     => $item['reference'],
                        'laterality'       => $item['laterality'],
                        'anatomic_level'   => $item['anatomic_level'],
                        'default_quantity' => $item['default_quantity'],
                        'sort_order'       => $item['sort_order'],
                    ]);
                    $this->count('order_set_items');
                }
            }

            foreach ($library->diagnosisSets as $set) {
                $id = $this->insert(Schema::DIAGNOSIS_SET, [
                    'code'              => $set['code'],
                    'name'              => $set['name'],
                    'specialty'         => $set['specialty'],
                    'body_region'       => $set['body_region'],
                    'is_active'         => $set['is_active'] ? 1 : 0,
                    'owner_scope'       => $set['owner_scope'],
                    'presentation_mode' => $set['presentation_mode'],
                    'laterality_param'  => $set['laterality_param'] ? 1 : 0,
                ]);
                $this->count('diagnosis_sets');
                $this->defer('entry.notes', $set['name'], $set['notes']);
                foreach ($set['items'] as $item) {
                    $linked = null;
                    if ($item['linked_order_set'] !== null) {
                        if (!isset($orderSetIds[$item['linked_order_set']])) {
                            $linked = $this->lookupId(Schema::ORDER_SET, 'code', $item['linked_order_set']);
                            if ($linked === null) {
                                throw new ImportException(sprintf(
                                    'Diagnosis set %s "%s", item "%s": links order set "%s", which is neither in this import nor already in the database.',
                                    $set['code'], $set['name'], $item['display_name'], $item['linked_order_set']
                                ));
                            }
                        } else {
                            $linked = $orderSetIds[$item['linked_order_set']];
                        }
                    }
                    $this->insert(Schema::DS_ITEM, [
                        'diagnosis_set_id'     => $id,
                        'icd10_code'           => $item['icd10_code'],
                        'display_name'         => $item['display_name'],
                        'laterality'           => $item['laterality'],
                        'anatomic_level'       => $item['anatomic_level'],
                        'default_plan_text'    => $item['default_plan_text'],
                        'default_selected'     => $item['default_selected'] ? 1 : 0,
                        'sort_order'           => $item['sort_order'],
                        'linked_order_set_id'  => $linked,
                        'linked_education_ids' => $item['linked_education'] === []
                            ? null : json_encode($item['linked_education']),
                    ]);
                    $this->count('diagnosis_set_items');
                }
            }

            foreach ($library->planGroups as $group) {
                $id = $this->insert(Schema::PLAN_GROUP, [
                    'name'      => $group['name'],
                    'is_active' => $group['is_active'] ? 1 : 0,
                ]);
                $this->count('plan_groups');
                $this->defer('entry.notes', $group['name'], $group['notes']);
                foreach ($group['codes'] as $code) {
                    $this->insert(Schema::PG_CODE, [
                        'plan_group_id' => $id,
                        'icd10_code'    => $code,
                    ]);
                    $this->count('plan_group_codes');
                }
                foreach ($group['options'] as $option) {
                    $params = $option['parameters'];
                    foreach ($params as $k => $param) {
                        if ($param['vocabulary'] !== null) {
                            $vocab = new Vocabularies($library);
                            $params[$k]['options'] = $vocab->options($param['vocabulary']);
                        }
                    }
                    $this->insert(Schema::PLAN_OPTION, [
                        'plan_group_id'         => $id,
                        'label'                 => $option['label'],
                        'sort_order'            => $option['sort_order'],
                        'narrative_template'    => $option['narrative_template'],
                        'creates_order_type_id' => $option['creates_order_type'],
                        'sets_followup'         => $option['sets_followup'] ? 1 : 0,
                        'queues_consent_id'     => $option['queues_consent'],
                        'parameters'            => $params === [] ? null : json_encode($params),
                    ]);
                    $this->count('plan_options');
                }
            }

            // --- saved findings last: presets reference element and option ids
            foreach ($library->savedFindings as $finding) {
                $id = $this->insert(Schema::SAVED_FINDING, [
                    'name'        => $finding['name'],
                    'section_key' => $finding['section_key'],
                    'owner_scope' => $finding['owner_scope'],
                    'is_active'   => $finding['is_active'] ? 1 : 0,
                ]);
                $this->count('saved_findings');
                $this->defer('saved_finding.laterality', $finding['name'],
                    $finding['laterality'] !== 'n/a' ? $finding['laterality'] : null);
                $this->defer('entry.notes', $finding['name'], $finding['notes']);

                foreach ($finding['templates'] as $ref) {
                    $templateId = $templateIds[$ref['template']]
                        ?? $this->lookupId(Schema::TEMPLATE, 'name', $ref['template']);
                    if ($templateId === null) {
                        throw new ImportException(sprintf(
                            'Saved finding "%s" bundles template "%s", which is neither in this import nor already in the database.',
                            $finding['name'], $ref['template']
                        ));
                    }
                    $this->insert(Schema::SF_TEMPLATE, [
                        'saved_finding_id' => $id,
                        'template_id'      => $templateId,
                        'sort_order'       => $ref['sort_order'],
                    ]);
                    $this->count('saved_finding_templates');
                }

                foreach ($finding['presets'] as $n => $preset) {
                    $templateKey = $canonicalKey[$preset['template']] ?? $preset['template'];
                    $elementKey  = $templateKey . "\0" . strtolower($preset['element']);
                    if (!isset($elementIds[$elementKey])) {
                        throw new ImportException(sprintf(
                            'Saved finding "%s", preset #%d: template "%s" has no element "%s" in this import.',
                            $finding['name'], $n + 1, $preset['template'], $preset['element']
                        ));
                    }
                    $optionId = null;
                    if ($preset['option'] !== null) {
                        $optionKey = $elementKey . "\0" . strtolower($preset['option']);
                        if (!isset($optionIds[$optionKey])) {
                            throw new ImportException(sprintf(
                                'Saved finding "%s", preset #%d: element "%s" does not offer the option "%s".',
                                $finding['name'], $n + 1, $preset['element'], $preset['option']
                            ));
                        }
                        $optionId = $optionIds[$optionKey];
                    }
                    $this->insert(Schema::SF_PRESET, [
                        'saved_finding_id' => $id,
                        'element_id'       => $elementIds[$elementKey],
                        'option_id'        => $optionId,
                        'value_text'       => $preset['value_text'],
                        'value_numeric'    => $preset['value_numeric'],
                        'laterality'       => $preset['laterality'],
                        'anatomic_level'   => $preset['anatomic_level'],
                    ]);
                    $this->count('presets');
                }
            }

            if ($dryRun) {
                $this->pdo->rollBack();
                $this->note('Dry run: the transaction was rolled back, nothing was written.');
            } else {
                $this->pdo->commit();
                $this->note('Committed.');
            }
        } catch (ImportException | PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $error = $e->getMessage();
            $this->note('Rolled back. No rows were written.');
        }

        return [
            'ok'       => $error === null,
            'report'   => $report,
            'log'      => $this->log,
            'stats'    => $this->stats,
            'deferred' => $this->deferred,
            'error'    => $error,
        ];
    }

    /** Codes and names that already exist are a conflict, never a merge. */
    private function assertNoCollisions(Library $library): void
    {
        foreach ($library->orderSets as $set) {
            if ($this->lookupId(Schema::ORDER_SET, 'code', $set['code']) !== null) {
                throw new ImportException(sprintf(
                    'Order set code "%s" ("%s") already exists in the database. '
                    . 'Codes are unique; rename or retire the existing set rather than importing over it.',
                    $set['code'], $set['name']
                ));
            }
        }
        foreach ($library->diagnosisSets as $set) {
            if ($this->lookupId(Schema::DIAGNOSIS_SET, 'code', $set['code']) !== null) {
                throw new ImportException(sprintf(
                    'Diagnosis set code "%s" ("%s") already exists in the database.',
                    $set['code'], $set['name']
                ));
            }
            if ($this->lookupId(Schema::ORDER_SET, 'code', $set['code']) !== null) {
                throw new ImportException(sprintf(
                    'Diagnosis set code "%s" ("%s") is already used by an order set in the database. '
                    . 'Set codes share one catalog.',
                    $set['code'], $set['name']
                ));
            }
        }
        foreach ($library->templates as $t) {
            if ($this->lookupId(Schema::TEMPLATE, 'name', $t['name']) !== null) {
                throw new ImportException(sprintf(
                    'Template "%s" already exists in the database. Importing a second one '
                    . 'would give the picker two entries with the same name.',
                    $t['name']
                ));
            }
        }
        foreach ($library->savedFindings as $s) {
            if ($this->lookupId(Schema::SAVED_FINDING, 'name', $s['name']) !== null) {
                throw new ImportException(sprintf(
                    'Saved finding "%s" already exists in the database.', $s['name']
                ));
            }
        }
    }

    /** @return list<array{label:string,polarity:string,sort_order:int,value_slots:list<mixed>,excludes:list<string>}> */
    private function expandVocabulary(Library $library, string $name): array
    {
        $vocab = new Vocabularies($library);
        if (!$vocab->has($name)) {
            throw new ImportException("Vocabulary \"{$name}\" is not defined.");
        }
        $out = [];
        foreach ($vocab->options($name) as $i => $label) {
            $out[] = [
                'label'       => $label,
                'polarity'    => $vocab->polarityOf($name, $label),
                'sort_order'  => $i + 1,
                'value_slots' => [],
                'excludes'    => [],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $values */
    private function insert(string $table, array $values): int
    {
        $allowed = Schema::COLUMNS[$table] ?? [];
        foreach (array_keys($values) as $col) {
            if (!in_array($col, $allowed, true)) {
                throw new ImportException(
                    "Internal error: column \"{$col}\" is not part of {$table}. "
                    . 'The schema is owned by the contracts package; this importer must '
                    . 'not invent columns.'
                );
            }
        }
        $cols = array_keys($values);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $stmt = $this->pdo->prepare($sql);
        foreach ($values as $col => $value) {
            $stmt->bindValue(':' . $col, $value, $value === null ? PDO::PARAM_NULL
                : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $values */
    private function update(string $table, int $id, array $values): void
    {
        $sets = [];
        foreach (array_keys($values) as $col) {
            if (!in_array($col, Schema::COLUMNS[$table] ?? [], true)) {
                throw new ImportException("Internal error: column \"{$col}\" is not part of {$table}.");
            }
            $sets[] = "{$col} = :{$col}";
        }
        $stmt = $this->pdo->prepare(
            sprintf('UPDATE %s SET %s WHERE id = :id', $table, implode(', ', $sets))
        );
        foreach ($values as $col => $value) {
            $stmt->bindValue(':' . $col, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function lookupId(string $table, string $column, string $value): ?int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$column} = :v LIMIT 1");
        $stmt->execute([':v' => $value]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function defer(string $field, string $entry, mixed $value): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }
        $meta = Schema::DEFERRED[$field] ?? ['gap' => '-', 'note' => ''];
        $this->deferred[] = [
            'field' => $field,
            'entry' => $entry,
            'value' => is_scalar($value) ? (string) $value : json_encode($value),
            'gap'   => $meta['gap'],
            'note'  => $meta['note'],
        ];
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    private function count(string $what): void
    {
        $this->stats[$what] = ($this->stats[$what] ?? 0) + 1;
    }
}
