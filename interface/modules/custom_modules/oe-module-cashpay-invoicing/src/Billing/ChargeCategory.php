<?php

/**
 * Rows of the invoice totals matrix. Backed because the value is stored in
 * cpi_line.charge_category.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

enum ChargeCategory: string
{
    case Provider = 'provider';
    case Product = 'product';
    case OperatingRoom = 'or';
    case Anesthesia = 'anesthesia';
    case Recovery = 'recovery';
    case Assistant = 'assistant';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Provider => 'Provider',
            self::Product => 'Product',
            self::OperatingRoom => 'OR Facility',
            self::Anesthesia => 'Anesthesia',
            self::Recovery => 'Recovery',
            self::Assistant => 'Assistant',
            self::Other => 'Other',
        };
    }

    /**
     * Categories added as free-text fee lines from the editor buttons.
     *
     * @return list<self>
     */
    public static function feeLines(): array
    {
        return [self::OperatingRoom, self::Anesthesia, self::Recovery, self::Assistant, self::Other];
    }
}
