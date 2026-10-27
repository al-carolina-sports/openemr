<?php

/**
 * The admin definitions, cleanup script and code must agree with table.sql.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\AclObject;
use OpenEMR\Modules\CashpayInvoicing\Admin\EntityRegistry;
use OpenEMR\Modules\CashpayInvoicing\Admin\FieldType;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupSource;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panel;
use PHPUnit\Framework\TestCase;

final class SchemaConsistencyTest extends TestCase
{
    /** @var array<string, array<string, string>> table => column => type */
    private static array $schema = [];

    public static function setUpBeforeClass(): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../table.sql');
        preg_match_all('/CREATE TABLE `(cpi_[a-z_]+)` \((.*?)\n\) ENGINE/s', $sql, $tables, PREG_SET_ORDER);
        foreach ($tables as [, $table, $body]) {
            preg_match_all('/^\s+`([a-z_0-9]+)` ([A-Z]+(?:\(\d+(?:,\d+)?\))?)/m', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [, $column, $type]) {
                self::$schema[$table][$column] = $type;
            }
        }
    }

    public function testSchemaHasAllTables(): void
    {
        $this->assertCount(44, self::$schema);
    }

    public function testEveryAdminFieldIsAColumnOfTheRightKind(): void
    {
        foreach (EntityRegistry::all() as $entity) {
            $this->assertArrayHasKey($entity->table, self::$schema, $entity->key);
            $columns = self::$schema[$entity->table];
            $this->assertArrayHasKey('id', $columns, $entity->table);
            foreach ($entity->fields as $field) {
                $this->assertArrayHasKey($field->name, $columns, $entity->table . '.' . $field->name);
                $type = $columns[$field->name];
                $expected = match ($field->type) {
                    FieldType::Money => '/^DECIMAL\(12,2\)$/',
                    FieldType::Decimal => '/^DECIMAL\(\d+,' . $field->scale . '\)$/',
                    FieldType::Boolean => '/^TINYINT\(1\)$/',
                    FieldType::Integer, FieldType::Lookup => '/^(INT|BIGINT|TINYINT)/',
                    FieldType::Date => '/^DATE$/',
                    FieldType::LongText => '/^(TEXT|MEDIUMTEXT)$/',
                    FieldType::Text, FieldType::Choice => '/^VARCHAR\(\d+\)$/',
                };
                $this->assertMatchesRegularExpression($expected, $type, $entity->table . '.' . $field->name);
                if ($field->type === FieldType::Text && preg_match('/VARCHAR\((\d+)\)/', $type, $m) === 1) {
                    $this->assertLessThanOrEqual((int) $m[1], $field->maxLength, $entity->table . '.' . $field->name . ' maxLength');
                }
            }
            if ($entity->uniqueField !== null) {
                $this->assertArrayHasKey($entity->uniqueField, $columns);
            }
        }
    }

    public function testLookupTablesExist(): void
    {
        foreach (LookupSource::cases() as $source) {
            $table = $source->moduleTable();
            if ($table === null) {
                continue;
            }
            $this->assertArrayHasKey($table[0], self::$schema, $source->name);
            $this->assertArrayHasKey($table[1], self::$schema[$table[0]], $source->name);
        }
    }

    public function testCleanupDropsEveryTable(): void
    {
        $cleanup = (string) file_get_contents(__DIR__ . '/../cleanup.sql');
        preg_match_all('/`(cpi_[a-z_]+)`/', $cleanup, $m);
        $this->assertEqualsCanonicalizing(array_keys(self::$schema), array_values(array_unique($m[1])));
    }

    public function testEveryPanelHasATemplate(): void
    {
        foreach (Panel::cases() as $panel) {
            $this->assertFileExists(__DIR__ . '/../templates/admin/panels/' . $panel->key() . '.html.twig');
            $this->assertSame($panel, Panel::fromKey($panel->key()));
        }
        $this->assertNull(Panel::fromKey('nope'));
    }

    public function testSeedRowsTargetRealColumns(): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../table.sql');
        preg_match_all('/^#IfNotRow (cpi_[a-z_]+) ([a-z_]+) (.+)$\n^INSERT INTO `(cpi_[a-z_]+)` \(([^)]*)\)/m', $sql, $rows, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(20, count($rows));
        foreach ($rows as [, $guardTable, $guardColumn, , $insertTable, $columnList]) {
            $this->assertSame($guardTable, $insertTable);
            $this->assertArrayHasKey($guardColumn, self::$schema[$guardTable]);
            foreach (explode(',', str_replace(['`', ' '], '', $columnList)) as $column) {
                $this->assertArrayHasKey($column, self::$schema[$insertTable], $insertTable . '.' . $column);
            }
        }
    }

    public function testEveryAclObjectGoesToAdministrators(): void
    {
        foreach (AclObject::cases() as $object) {
            $this->assertContains('Administrators', $object->defaultGroups(), $object->value);
            $this->assertNotSame('', $object->title());
        }
    }
}
