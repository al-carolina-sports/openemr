<?php

/**
 * A sub-section of an edit page that loads and changes child rows.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin\Panels;

use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panel;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;

interface PanelHandler
{
    public function panel(): Panel;

    public function title(): string;

    /**
     * Data for the panel's template.
     *
     * @return array<string, mixed>
     */
    public function load(int $ownerId): array;

    /**
     * Applies one submitted operation and returns the confirmation to show.
     *
     * @throws ValidationException for bad input
     */
    public function handle(int $ownerId, string $op, FormInput $input, int $userId): Notice;
}
