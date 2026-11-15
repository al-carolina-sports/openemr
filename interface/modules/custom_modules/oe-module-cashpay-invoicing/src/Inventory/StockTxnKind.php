<?php

/**
 * Why stock moved. Backed because the value is stored in cpi_stock_txn.kind.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Inventory;

enum StockTxnKind: string
{
    case Sale = 'sale';
    case ReceiveOrder = 'receive_order';
    case Adjust = 'adjust';
    case Count = 'count';
    case ReturnToStock = 'return';
    case ProcedureUse = 'procedure_use';
}
