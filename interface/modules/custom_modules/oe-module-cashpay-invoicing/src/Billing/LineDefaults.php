<?php

/**
 * Who and where for new lines, taken from the document header.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

final readonly class LineDefaults
{
    public function __construct(
        public ?int $providerId,
        public ?int $sellerId,
        public ?int $facilityId,
        public \DateTimeImmutable $serviceDate,
    ) {
    }

    public static function of(Document $document): self
    {
        return new self($document->providerId, $document->sellerId, $document->facilityId, $document->date);
    }
}
