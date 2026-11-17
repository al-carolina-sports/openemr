<?php

/**
 * Charges a membership invoice to the patient's saved card.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Membership;

use OpenEMR\Modules\CashpayInvoicing\Billing\Document;

interface AutopayCharger
{
    /**
     * @return string|null null when charged, otherwise why it was not
     */
    public function chargeInvoice(Document $invoice): ?string;
}
