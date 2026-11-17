<?php

/**
 * Mutable holder for values shared with FakeDatabase responders in tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Al Antony <alvin.antony@carolinasportsandspine.com>
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests\Support;

final class Holder
{
    public ?string $value = null;

    /** @var list<string> */
    public array $items = [];
}
