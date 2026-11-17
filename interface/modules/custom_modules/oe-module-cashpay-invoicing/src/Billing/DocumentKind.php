<?php

/**
 * Quotes and invoices share one editor and one line table.
 * Backed because the value is stored in cpi_line.parent_type.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

enum DocumentKind: string
{
    case Quote = 'quote';
    case Invoice = 'invoice';

    public function table(): string
    {
        return match ($this) {
            self::Quote => 'cpi_quote',
            self::Invoice => 'cpi_invoice',
        };
    }

    public function dateColumn(): string
    {
        return match ($this) {
            self::Quote => 'quote_date',
            self::Invoice => 'invoice_date',
        };
    }

    public function facilityColumn(): string
    {
        return match ($this) {
            self::Quote => 'facility_id',
            self::Invoice => 'income_facility_id',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Quote => 'Quote',
            self::Invoice => 'Invoice',
        };
    }
}
