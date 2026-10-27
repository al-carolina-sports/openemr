<?php

/**
 * A row of cpi_payment_method.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Payments;

use OpenEMR\Modules\CashpayInvoicing\Database\Row;

final readonly class PaymentMethod
{
    public function __construct(
        public int $id,
        public string $name,
        public MethodKind $kind,
        public bool $isRefundMethod,
        public bool $active,
    ) {
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            Row::int($row, 'id'),
            Row::str($row, 'name'),
            MethodKind::tryFrom(Row::str($row, 'kind')) ?? MethodKind::Other,
            Row::bool($row, 'is_refund_method'),
            Row::bool($row, 'active'),
        );
    }
}
