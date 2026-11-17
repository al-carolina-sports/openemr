<?php

/**
 * The background job that bills due memberships and charges autopay.
 * Safe to run as often as wanted: only memberships whose next bill date has
 * come are billed, and each bill moves the date on.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Membership;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use Psr\Log\LoggerInterface;

final readonly class MembershipJob
{
    /** Never bill more than this many periods for one membership in one run. */
    private const MAX_CATCH_UP = 3;

    public function __construct(
        private MembershipService $memberships,
        private ?AutopayCharger $autopay,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{billed: int, charged: int, failed: int}
     */
    public function run(\DateTimeImmutable $today): array
    {
        $result = ['billed' => 0, 'charged' => 0, 'failed' => 0];
        for ($round = 0; $round < self::MAX_CATCH_UP; $round++) {
            $due = $this->memberships->due($today);
            $billedThisRound = 0;
            foreach ($due as $row) {
                if (!Row::bool($row, 'auto_monthly_invoice')) {
                    continue;
                }
                try {
                    $invoice = $this->memberships->billPeriod(Row::int($row, 'id'), null, 0, false);
                } catch (ValidationException $e) {
                    $this->logger->warning('Membership could not be billed', ['membership_id' => Row::int($row, 'id'), 'errors' => $e->errors]);
                    continue;
                }
                if ($invoice === null) {
                    continue;
                }
                $billedThisRound++;
                $result['billed']++;
                if (!Row::bool($row, 'autopay') || $this->autopay === null) {
                    continue;
                }
                $problem = $this->autopay->chargeInvoice($invoice);
                $this->memberships->recordAutopayResult(Row::int($row, 'id'), $problem);
                if ($problem === null) {
                    $result['charged']++;
                } else {
                    $result['failed']++;
                    $this->logger->warning('Membership autopay failed', ['membership_id' => Row::int($row, 'id'), 'invoice_id' => $invoice->id, 'reason' => $problem]);
                }
            }
            if ($billedThisRound === 0) {
                break;
            }
        }
        return $result;
    }
}
