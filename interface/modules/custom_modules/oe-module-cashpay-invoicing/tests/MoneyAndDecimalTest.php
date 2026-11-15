<?php

/**
 * Integer-cent money and fixed-point decimals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyAndDecimalTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function testParsesAmounts(string $text, int $cents): void
    {
        $this->assertSame($cents, Money::parse($text)->cents);
    }

    /**
     * @return array<string, array{string, int}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function validAmounts(): array
    {
        return [
            'whole' => ['125', 12500],
            'two places' => ['125.50', 12550],
            'one place' => ['7.5', 750],
            'dollar sign and comma' => ['$1,234.56', 123456],
            'negative' => ['-3.25', -325],
            'leading dot' => ['.99', 99],
            'trailing dot' => ['12.', 1200],
            'spaces' => [' 40.00 ', 4000],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsBadAmounts(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::parse($text);
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidAmounts(): array
    {
        return [
            'empty' => [''],
            'letters' => ['abc'],
            'three places' => ['1.234'],
            'only dot' => ['.'],
            'only minus' => ['-'],
            'two dots' => ['1.2.3'],
        ];
    }

    public function testFormatsForDisplayAndStorage(): void
    {
        $this->assertSame('$1,234.05', (new Money(123405))->format());
        $this->assertSame('-$0.07', (new Money(-7))->format());
        $this->assertSame('1234.05', (new Money(123405))->toDecimalString());
        $this->assertSame('-0.07', (new Money(-7))->toDecimalString());
        $this->assertSame('0.00', Money::zero()->toDecimalString());
    }

    public function testQuantityMultiplicationRoundsHalfAwayFromZero(): void
    {
        // 6 x $266.67
        $this->assertSame(160002, (new Money(26667))->multiplyByMilli(6000)->cents);
        // 0.5 x $0.05 = 2.5 cents -> 3
        $this->assertSame(3, (new Money(5))->multiplyByMilli(500)->cents);
        $this->assertSame(-3, (new Money(-5))->multiplyByMilli(500)->cents);
    }

    public function testPercentRoundsToTheCent(): void
    {
        // 6.75% of $10.01 = 0.675675 -> $0.68
        $this->assertSame(68, (new Money(1001))->percentOf(67500)->cents);
        // 3% of $100
        $this->assertSame(300, (new Money(10000))->percentOf(30000)->cents);
        // 2.5% of $0.10 = 0.25 cents -> 0
        $this->assertSame(0, (new Money(10))->percentOf(25000)->cents);
    }

    public function testArithmetic(): void
    {
        $this->assertSame(150, (new Money(100))->add(new Money(50))->cents);
        $this->assertTrue((new Money(100))->subtract(new Money(150))->isNegative());
    }

    public function testDecimalParseAndFormat(): void
    {
        $this->assertSame(12500, Decimal::parse('12.5', 3));
        $this->assertSame(-1500, Decimal::parse('-1.5', 3));
        $this->assertSame(67500, Decimal::parse('6.7500', 4));
        $this->assertSame(300, Decimal::parse('3%', 2));
        $this->assertSame('12.500', Decimal::format(12500, 3));
        $this->assertSame('-0.050', Decimal::format(-50, 3));
        $this->assertSame('7', Decimal::format(7, 0));
    }

    public function testDecimalRejectsTooManyPlaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::parse('1.2345', 3);
    }

    public function testDecimalRejectsText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::parse('ten', 2);
    }

    public function testDecimalDisplayTrimsZeros(): void
    {
        $this->assertSame('12.5', Decimal::display('12.500'));
        $this->assertSame('3', Decimal::display('3.000'));
        $this->assertSame('0', Decimal::display('0.000'));
        $this->assertSame('-2', Decimal::display('-2.000'));
        $this->assertSame('40', Decimal::display('40'));
        $this->assertSame('', Decimal::display(null));
        $this->assertSame('', Decimal::display('abc'));
    }
}
