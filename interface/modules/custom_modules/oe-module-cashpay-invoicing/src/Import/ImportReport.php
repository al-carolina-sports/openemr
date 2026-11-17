<?php

/**
 * What an import did, for the confirmation page.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Import;

final class ImportReport
{
    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $warnings = [];

    public function count(string $what, int $by = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $by;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
