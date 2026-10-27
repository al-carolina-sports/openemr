<?php

/**
 * Search, filter and page requested on a list page.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

final readonly class ListQuery
{
    /**
     * @param array<string, int> $filters lookup column => id
     */
    public function __construct(
        public string $search = '',
        public bool $includeInactive = false,
        public int $page = 1,
        public array $filters = [],
    ) {
    }
}
