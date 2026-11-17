<?php

/**
 * Parsed header fields of a quote or invoice.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class HeaderInput
{
    public function __construct(
        public string $name,
        public \DateTimeImmutable $date,
        public InvoiceType $invoiceType,
        public ?int $facilityId,
        public ?int $providerId,
        public ?int $sellerId,
        public string $comments,
        public Money $deposit,
        public ?\DateTimeImmutable $expiresOn = null,
        public bool $isActive = true,
        public Money $consultCredit = new Money(0),
        public bool $applyToLines = false,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public static function fromForm(FormInput $form, DocumentKind $kind): self
    {
        $errors = [];
        $name = $form->string('name');
        if ($name === '' || mb_strlen($name) > 255) {
            $errors['name'] = 'Enter a name (up to 255 characters)';
        }
        $date = self::date($form->string('date'));
        if ($date === null) {
            $errors['date'] = 'Enter a date as YYYY-MM-DD';
        }
        $type = InvoiceType::tryFrom($form->string('invoice_type'));
        if ($type === null) {
            $errors['invoice_type'] = 'Pick an invoice type';
        }
        $expires = null;
        if ($kind === DocumentKind::Quote && $form->string('expires_on') !== '') {
            $expires = self::date($form->string('expires_on'));
            if ($expires === null) {
                $errors['expires_on'] = 'Enter a date as YYYY-MM-DD';
            }
        }
        $deposit = Money::zero();
        $credit = Money::zero();
        $facilityId = null;
        $providerId = null;
        $sellerId = null;
        try {
            $deposit = $form->money('deposit', false) ?? Money::zero();
            $credit = $kind === DocumentKind::Quote ? ($form->money('consult_credit', false) ?? Money::zero()) : Money::zero();
            $facilityId = $form->optionalId('facility_id');
            $providerId = $form->optionalId('provider_id');
            $sellerId = $form->optionalId('seller_id');
        } catch (ValidationException $e) {
            $errors += $e->errors;
        }
        if ($deposit->isNegative() || $credit->isNegative()) {
            $errors['deposit'] = 'Amounts cannot be negative';
        }
        if ($errors !== [] || $date === null || $type === null) {
            throw new ValidationException($errors);
        }
        return new self(
            name: $name,
            date: $date,
            invoiceType: $type,
            facilityId: $facilityId,
            providerId: $providerId,
            sellerId: $sellerId,
            comments: mb_substr($form->string('comments'), 0, 5000),
            deposit: $deposit,
            expiresOn: $expires,
            isActive: $kind === DocumentKind::Invoice || $form->bool('is_active'),
            consultCredit: $credit,
            applyToLines: $form->bool('apply_to_lines'),
        );
    }

    private static function date(string $text): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        return $date !== false && $date->format('Y-m-d') === $text ? $date : null;
    }
}
