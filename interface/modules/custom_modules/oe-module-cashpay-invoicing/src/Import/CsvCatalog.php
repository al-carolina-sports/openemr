<?php

/**
 * CSV export and import of procedures and products, so prices and tax or
 * award flags can be bulk-edited in a spreadsheet and loaded back.
 *
 * Rows with an id update that record; rows without an id are added.
 * Category and manufacturer are written as names and created if missing.
 * The whole file is checked first and loaded in one transaction, so a file
 * with any bad row changes nothing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Import;

use OpenEMR\Modules\CashpayInvoicing\Admin\EntityDefinition;
use OpenEMR\Modules\CashpayInvoicing\Admin\EntityRegistry;
use OpenEMR\Modules\CashpayInvoicing\Admin\FieldParser;
use OpenEMR\Modules\CashpayInvoicing\Admin\FieldType;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;

final class CsvCatalog
{
    /** Lookup column => [CSV header, lookup table]. */
    private const LOOKUP_COLUMNS = [
        'procedure' => ['category_id' => ['category', 'cpi_procedure_category']],
        'product' => [
            'manufacturer_id' => ['manufacturer', 'cpi_manufacturer'],
            'category_id' => ['category', 'cpi_product_category'],
        ],
    ];

    private const MAX_ROWS = 5000;

    public function __construct(private readonly Database $db, private readonly FieldParser $parser = new FieldParser())
    {
    }

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return array_keys(self::LOOKUP_COLUMNS);
    }

    /**
     * @return list<string>
     */
    public function headers(string $entityKey): array
    {
        $entity = $this->entity($entityKey);
        $lookups = self::LOOKUP_COLUMNS[$entityKey];
        $headers = ['id'];
        foreach ($entity->fields as $field) {
            $headers[] = isset($lookups[$field->name]) ? $lookups[$field->name][0] : $field->name;
        }
        return $headers;
    }

    /**
     * Writes every row (active and inactive) as CSV to the stream.
     *
     * @param resource $out
     */
    public function export(string $entityKey, $out): void
    {
        $entity = $this->entity($entityKey);
        $lookups = self::LOOKUP_COLUMNS[$entityKey];
        $names = [];
        foreach ($lookups as $column => [, $table]) {
            $names[$column] = $this->names($table);
        }
        fputcsv($out, $this->headers($entityKey), escape: '');
        foreach ($this->db->fetchAll("SELECT * FROM `{$entity->table}` ORDER BY `name`, `id`") as $row) {
            $line = [self::cell($row['id'] ?? '')];
            foreach ($entity->fields as $field) {
                $value = $row[$field->name] ?? null;
                if (isset($lookups[$field->name])) {
                    $line[] = is_numeric($value) ? ($names[$field->name][(int) $value] ?? '') : '';
                    continue;
                }
                $line[] = self::csvSafe(self::cell($value));
            }
            fputcsv($out, $line, escape: '');
        }
    }

    /**
     * Checks a CSV file and, unless $dryRun, applies it.
     *
     * @throws ValidationException listing bad rows ("row 4: price: Enter an amount...")
     */
    public function import(string $entityKey, string $csv, bool $dryRun): ImportReport
    {
        $entity = $this->entity($entityKey);
        $rows = $this->parseCsv($csv);
        if ($rows === []) {
            throw new ValidationException(['file' => 'The file has no data rows']);
        }
        $headerMap = array_flip($this->headers($entityKey));
        $unknown = array_diff(array_keys($rows[0]), array_keys($headerMap));
        if ($unknown !== []) {
            throw new ValidationException(['file' => 'Unknown columns: ' . implode(', ', $unknown)]);
        }
        if (!array_key_exists('name', $rows[0])) {
            throw new ValidationException(['file' => 'The name column is required']);
        }

        $lookups = self::LOOKUP_COLUMNS[$entityKey];
        $planned = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $id = trim($row['id'] ?? '');
            if ($id !== '' && preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
                $errors['row ' . $rowNumber] = 'id must be a number or empty';
                continue;
            }
            $existing = $id === '' ? null : $this->db->fetchOne("SELECT * FROM `{$entity->table}` WHERE `id` = ?", [(int) $id]);
            if ($id !== '' && $existing === null) {
                $errors['row ' . $rowNumber] = 'no record with id ' . $id;
                continue;
            }
            $input = [];
            foreach ($entity->fields as $field) {
                if (isset($lookups[$field->name])) {
                    continue;
                }
                if (array_key_exists($field->name, $row)) {
                    $input[$field->name] = $row[$field->name];
                } elseif ($existing !== null) {
                    // Column left out of the file: keep the stored value.
                    $input[$field->name] = self::cell($existing[$field->name] ?? null);
                } else {
                    $input[$field->name] = $field->type === FieldType::Boolean ? $field->default : '';
                }
            }
            try {
                $values = $this->parser->parseAll($this->withoutLookups($entity, $lookups), $input);
            } catch (ValidationException $e) {
                foreach ($e->errors as $field => $message) {
                    $errors['row ' . $rowNumber . ': ' . $field] = $message;
                }
                continue;
            }
            $lookupNames = [];
            foreach ($lookups as $column => [$header, $table]) {
                if (array_key_exists($header, $row)) {
                    $lookupNames[$column] = [$table, trim($row[$header])];
                }
            }
            $planned[] = [$id === '' ? null : (int) $id, $values, $lookupNames];
        }
        if ($errors !== []) {
            throw new ValidationException(array_slice($errors, 0, 50, true));
        }

        $report = new ImportReport();
        foreach ($planned as [$id]) {
            $report->count($id === null ? 'added' : 'updated');
        }
        if ($dryRun) {
            return $report;
        }
        $this->db->transaction(function () use ($entity, $planned): void {
            foreach ($planned as [$id, $values, $lookupNames]) {
                foreach ($lookupNames as $column => [$table, $name]) {
                    $values[$column] = $name === '' ? null : $this->lookupId($table, $name);
                }
                $this->write($entity, $id, $values);
            }
        });
        return $report;
    }

    /**
     * @param array<string, string|int|null> $values
     */
    private function write(EntityDefinition $entity, ?int $id, array $values): void
    {
        $columns = array_keys($values);
        if ($id === null) {
            $this->db->insert(
                "INSERT INTO `{$entity->table}` (`" . implode('`, `', $columns) . '`) VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')',
                array_values($values)
            );
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "`$c` = ?", $columns));
        $this->db->execute("UPDATE `{$entity->table}` SET $sets WHERE `id` = ?", [...array_values($values), $id]);
    }

    /**
     * @param array<string, array{string, string}> $lookups
     */
    private function withoutLookups(EntityDefinition $entity, array $lookups): EntityDefinition
    {
        return new EntityDefinition(
            $entity->key,
            $entity->table,
            $entity->title,
            $entity->singular,
            array_values(array_filter($entity->fields, static fn ($f): bool => !isset($lookups[$f->name]))),
        );
    }

    private function lookupId(string $table, string $name): int
    {
        $row = $this->db->fetchOne("SELECT `id` FROM `$table` WHERE `name` = ?", [$name]);
        if ($row !== null && is_numeric($row['id'])) {
            return (int) $row['id'];
        }
        return $this->db->insert("INSERT INTO `$table` (`name`, `active`) VALUES (?, 1)", [$name]);
    }

    /**
     * @return array<int, string>
     */
    private function names(string $table): array
    {
        $map = [];
        foreach ($this->db->fetchAll("SELECT `id`, `name` FROM `$table`") as $row) {
            if (is_numeric($row['id'] ?? null) && is_string($row['name'] ?? null)) {
                $map[(int) $row['id']] = $row['name'];
            }
        }
        return $map;
    }

    /**
     * @return list<array<string, string>> header => cell
     */
    public function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $header = null;
        $rows = [];
        $buffer = '';
        foreach ($lines as $line) {
            // Re-join lines that are inside a quoted cell.
            $buffer = $buffer === '' ? $line : $buffer . "\n" . $line;
            if (substr_count($buffer, '"') % 2 === 1) {
                continue;
            }
            $cells = str_getcsv($buffer, escape: '');
            $buffer = '';
            if ($cells === [null] || $cells === ['']) {
                continue;
            }
            $cells = array_map(static function (?string $c): string {
                $c ??= '';
                // Undo the export's formula guard.
                return preg_match("/^'[=+\\-@]/", $c) === 1 ? substr($c, 1) : $c;
            }, $cells);
            if ($header === null) {
                $header = array_map(static fn (string $h): string => strtolower(trim($h)), $cells);
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new ValidationException(['file' => 'Too many rows (maximum ' . self::MAX_ROWS . ')']);
            }
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function entity(string $key): EntityDefinition
    {
        if (!isset(self::LOOKUP_COLUMNS[$key])) {
            throw new \OutOfBoundsException('CSV import is not available for this list');
        }
        return EntityRegistry::get($key);
    }

    private static function cell(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    /** Stops spreadsheet formula injection in exported text cells. */
    private static function csvSafe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && !is_numeric($value) ? "'" . $value : $value;
    }
}
