<?php

/**
 * Kinds of fields on the catalog admin forms.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

enum FieldType
{
    case Text;
    case LongText;
    case Money;
    case Decimal;
    case Integer;
    case Boolean;
    case Date;
    /** Foreign key chosen from a LookupSource. */
    case Lookup;
    /** One of a fixed set of stored strings. */
    case Choice;
}
