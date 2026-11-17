<?php

/**
 * The promo chosen for a line and the discount it gives.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Pricing;

use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class AppliedPromo
{
    public function __construct(
        public PromoCandidate $promo,
        public Money $discount,
    ) {
    }
}
