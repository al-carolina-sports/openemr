<?php

/**
 * A procedure price override for a provider, an office, or both.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Pricing;

use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class FeeOverride
{
    public function __construct(
        public ?int $providerId,
        public ?int $facilityId,
        public Money $price,
    ) {
    }
}
