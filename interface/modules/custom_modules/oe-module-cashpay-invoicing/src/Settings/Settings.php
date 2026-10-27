<?php

/**
 * Module settings, parsed and validated.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Settings;

use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class Settings
{
    /** Highest card service charge allowed: 3% (card network cap). In basis points. */
    public const MAX_CARD_FEE_BP = 300;

    public const DEFAULT_QUOTE_FOOTER = 'This quote is for information only and is not a bill. '
        . 'Prices are valid until the expiration date shown. Sign below to accept.';

    /**
     * @param int $cardFeeBasisPoints credit card service charge, 100 = 1%
     * @param int $pointsPerDollarHundredths award points earned per dollar paid, 100 = 1 point
     * @param Money $pointValue what one point is worth when redeemed
     */
    public function __construct(
        public bool $cardFeeEnabled = false,
        public int $cardFeeBasisPoints = 0,
        public string $cardFeeLabel = 'Credit card service charge',
        public bool $rewardsEnabled = false,
        public int $pointsPerDollarHundredths = 100,
        public Money $pointValue = new Money(1),
        public int $minRedeemPoints = 0,
        public int $quoteValidDays = 30,
        public string $quoteFooter = self::DEFAULT_QUOTE_FOOTER,
        public string $receiptFooter = '',
    ) {
        if ($cardFeeBasisPoints < 0 || $cardFeeBasisPoints > self::MAX_CARD_FEE_BP) {
            throw new \DomainException('Card service charge out of range');
        }
    }

    /**
     * Builds settings from stored values, falling back to defaults for
     * anything missing or malformed so a bad row never breaks billing.
     *
     * @param array<string, mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $d = new self();
        $bp = self::int($stored['card_fee_bp'] ?? null, $d->cardFeeBasisPoints);
        return new self(
            cardFeeEnabled: self::bool($stored['card_fee_enabled'] ?? null, $d->cardFeeEnabled),
            cardFeeBasisPoints: $bp >= 0 && $bp <= self::MAX_CARD_FEE_BP ? $bp : 0,
            cardFeeLabel: self::string($stored['card_fee_label'] ?? null, $d->cardFeeLabel),
            rewardsEnabled: self::bool($stored['rewards_enabled'] ?? null, $d->rewardsEnabled),
            pointsPerDollarHundredths: max(0, self::int($stored['points_per_dollar_x100'] ?? null, $d->pointsPerDollarHundredths)),
            pointValue: new Money(max(0, self::int($stored['point_value_cents'] ?? null, $d->pointValue->cents))),
            minRedeemPoints: max(0, self::int($stored['min_redeem_points'] ?? null, $d->minRedeemPoints)),
            quoteValidDays: max(1, self::int($stored['quote_valid_days'] ?? null, $d->quoteValidDays)),
            quoteFooter: self::string($stored['quote_footer'] ?? null, $d->quoteFooter),
            receiptFooter: self::string($stored['receipt_footer'] ?? null, $d->receiptFooter),
        );
    }

    /**
     * @throws ValidationException
     */
    public static function fromInput(FormInput $input): self
    {
        $errors = [];
        $bp = 0;
        try {
            $bp = Decimal::parse($input->string('card_fee_pct') === '' ? '0' : $input->string('card_fee_pct'), 2);
            if ($bp < 0 || $bp > self::MAX_CARD_FEE_BP) {
                $errors['card_fee_pct'] = 'Enter 0 to 3 percent';
            }
        } catch (\InvalidArgumentException) {
            $errors['card_fee_pct'] = 'Enter a percent like 3 or 2.5';
        }
        $points = 100;
        try {
            $points = Decimal::parse($input->string('points_per_dollar') === '' ? '0' : $input->string('points_per_dollar'), 2);
            if ($points < 0) {
                $errors['points_per_dollar'] = 'Cannot be negative';
            }
        } catch (\InvalidArgumentException) {
            $errors['points_per_dollar'] = 'Enter a number like 1 or 0.5';
        }
        $pointValue = Money::zero();
        try {
            $pointValue = Money::parse($input->string('point_value') === '' ? '0' : $input->string('point_value'));
            if ($pointValue->isNegative()) {
                $errors['point_value'] = 'Cannot be negative';
            }
        } catch (\InvalidArgumentException) {
            $errors['point_value'] = 'Enter an amount like 0.05';
        }
        $minRedeem = self::wholeNumber($input->string('min_redeem_points'), 0, 'min_redeem_points', $errors);
        $validDays = self::wholeNumber($input->string('quote_valid_days'), 30, 'quote_valid_days', $errors);
        if ($validDays < 1 || $validDays > 365) {
            $errors['quote_valid_days'] = 'Enter 1 to 365 days';
        }
        $label = $input->string('card_fee_label');
        if (mb_strlen($label) > 60) {
            $errors['card_fee_label'] = 'Keep it under 60 characters';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return new self(
            cardFeeEnabled: $input->bool('card_fee_enabled') && $bp > 0,
            cardFeeBasisPoints: $bp,
            cardFeeLabel: $label === '' ? 'Credit card service charge' : $label,
            rewardsEnabled: $input->bool('rewards_enabled'),
            pointsPerDollarHundredths: $points,
            pointValue: $pointValue,
            minRedeemPoints: $minRedeem,
            quoteValidDays: $validDays,
            quoteFooter: mb_substr($input->string('quote_footer'), 0, 2000),
            receiptFooter: mb_substr($input->string('receipt_footer'), 0, 2000),
        );
    }

    /**
     * @return array<string, bool|int|string>
     */
    public function toStorage(): array
    {
        return [
            'card_fee_enabled' => $this->cardFeeEnabled,
            'card_fee_bp' => $this->cardFeeBasisPoints,
            'card_fee_label' => $this->cardFeeLabel,
            'rewards_enabled' => $this->rewardsEnabled,
            'points_per_dollar_x100' => $this->pointsPerDollarHundredths,
            'point_value_cents' => $this->pointValue->cents,
            'min_redeem_points' => $this->minRedeemPoints,
            'quote_valid_days' => $this->quoteValidDays,
            'quote_footer' => $this->quoteFooter,
            'receipt_footer' => $this->receiptFooter,
        ];
    }

    /** "3.00" for the form. */
    public function cardFeePercentText(): string
    {
        return Decimal::format($this->cardFeeBasisPoints, 2);
    }

    public function pointsPerDollarText(): string
    {
        return Decimal::format($this->pointsPerDollarHundredths, 2);
    }

    /**
     * The service charge on a credit card payment. Debit and prepaid cards
     * are never charged, nor is anything when the charge is turned off.
     */
    public function cardFeeOn(Money $amount, CardFunding $funding): Money
    {
        if (!$this->cardFeeEnabled || $funding !== CardFunding::Credit || $amount->cents <= 0) {
            return Money::zero();
        }
        // Basis points to ten-thousandths of a percent: 300 bp = 3% = 30000.
        return $amount->percentOf($this->cardFeeBasisPoints * 100);
    }

    private static function bool(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    private static function string(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, string> $errors
     */
    private static function wholeNumber(string $text, int $default, string $key, array &$errors): int
    {
        if ($text === '') {
            return $default;
        }
        if (preg_match('/^\d{1,9}$/', $text) !== 1) {
            $errors[$key] = 'Enter a whole number';
            return $default;
        }
        return (int) $text;
    }
}
