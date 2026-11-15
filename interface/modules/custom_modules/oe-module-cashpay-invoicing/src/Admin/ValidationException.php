<?php

/**
 * Form input that could not be accepted, with one message per field.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

final class ValidationException extends \DomainException
{
    /**
     * @param array<string, string> $errors field name => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid input');
    }
}
