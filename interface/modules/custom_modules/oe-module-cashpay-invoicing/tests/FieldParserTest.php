<?php

/**
 * Parsing admin form input into stored column values.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Admin\EntityRegistry;
use OpenEMR\Modules\CashpayInvoicing\Admin\FieldDefinition;
use OpenEMR\Modules\CashpayInvoicing\Admin\FieldParser;
use OpenEMR\Modules\CashpayInvoicing\Admin\FieldType;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupSource;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use PHPUnit\Framework\TestCase;

final class FieldParserTest extends TestCase
{
    private FieldParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FieldParser();
    }

    public function testMoneyIsNormalized(): void
    {
        $field = new FieldDefinition('price', 'Price', FieldType::Money);
        $this->assertSame('1250.00', $this->parser->parse($field, '$1,250'));
        $this->assertSame('0', $this->parser->parse($field, ''));
    }

    public function testDecimalUsesScale(): void
    {
        $field = new FieldDefinition('amount', 'Amount', FieldType::Decimal, scale: 4);
        $this->assertSame('37.2500', $this->parser->parse($field, '37.25'));
    }

    public function testBooleanAcceptsCheckboxAndSpreadsheetValues(): void
    {
        $field = new FieldDefinition('tax1', 'Tax 1', FieldType::Boolean);
        foreach (['1', 'on', 'Yes', 'TRUE', 'y'] as $on) {
            $this->assertSame(1, $this->parser->parse($field, $on), $on);
        }
        foreach (['', '0', 'no', 'off'] as $off) {
            $this->assertSame(0, $this->parser->parse($field, $off), $off);
        }
    }

    public function testRequiredTextMustBePresent(): void
    {
        $field = new FieldDefinition('name', 'Name', FieldType::Text, required: true);
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse($field, '   ');
    }

    public function testTextLengthIsLimited(): void
    {
        $field = new FieldDefinition('code', 'Code', FieldType::Text, maxLength: 10);
        $this->assertSame('ReWellness', $this->parser->parse($field, 'ReWellness'));
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse($field, 'ReWellness+');
    }

    public function testOptionalTextAndLookupBecomeNull(): void
    {
        $this->assertNull($this->parser->parse(new FieldDefinition('cpt_code', 'CPT', FieldType::Text), ''));
        $lookup = new FieldDefinition('category_id', 'Category', FieldType::Lookup, lookup: LookupSource::ProcedureCategory);
        $this->assertNull($this->parser->parse($lookup, ''));
        $this->assertSame(7, $this->parser->parse($lookup, '7'));
    }

    public function testIntegerDefault(): void
    {
        $field = new FieldDefinition('sort_order', 'Sort', FieldType::Integer, default: '0');
        $this->assertSame(0, $this->parser->parse($field, ''));
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse($field, '1.5');
    }

    public function testDatesMustBeReal(): void
    {
        $field = new FieldDefinition('start_date', 'Starts', FieldType::Date);
        $this->assertSame('2026-02-23', $this->parser->parse($field, '2026-02-23'));
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse($field, '2026-02-30');
    }

    public function testChoiceMustBeListed(): void
    {
        $field = new FieldDefinition('kind', 'Kind', FieldType::Choice, choices: ['percent' => 'Percent', 'flat_each' => 'Each']);
        $this->assertSame('percent', $this->parser->parse($field, ''));
        $this->assertSame('flat_each', $this->parser->parse($field, 'flat_each'));
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse($field, 'free');
    }

    public function testParseAllCollectsEveryError(): void
    {
        $entity = EntityRegistry::get('procedure');
        try {
            $this->parser->parseAll($entity, ['name' => '', 'price' => 'abc', 'expected_hours' => '1.234']);
            $this->fail('Expected validation errors');
        } catch (ValidationException $e) {
            $this->assertSame(['name', 'price', 'expected_hours'], array_keys($e->errors));
        }
    }

    public function testParseAllTreatsMissingCheckboxesAsOff(): void
    {
        $values = $this->parser->parseAll(EntityRegistry::get('procedure'), ['name' => 'Consult', 'price' => '150']);
        $this->assertSame('Consult', $values['name']);
        $this->assertSame('150.00', $values['price']);
        $this->assertSame(0, $values['tax1']);
        $this->assertSame(0, $values['active']);
    }

    public function testLookupWithoutSourceIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        new FieldDefinition('x_id', 'X', FieldType::Lookup);
    }
}
