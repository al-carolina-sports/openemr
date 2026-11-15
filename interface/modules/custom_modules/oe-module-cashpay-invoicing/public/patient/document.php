<?php

/**
 * Cash-Pay patient page entry point.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../../globals.php');

use OpenEMR\Modules\CashpayInvoicing\Patient\PatientPagesFactory;

PatientPagesFactory::create()->document()->send();
