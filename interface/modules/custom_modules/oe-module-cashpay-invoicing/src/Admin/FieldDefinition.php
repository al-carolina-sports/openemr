<?php

/**
 * One editable column of a catalog table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

final readonly class FieldDefinition
{
    /**
     * @param array<string, string> $choices stored value => label, for FieldType::Choice
     */
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type,
        public bool $required = false,
        public bool $inList = false,
        public int $maxLength = 255,
        public int $scale = 2,
        public ?LookupSource $lookup = null,
        public array $choices = [],
        public string $default = '',
        public string $help = '',
    ) {
        if ($type === FieldType::Lookup && $lookup === null) {
            throw new \LogicException('Lookup field needs a source: ' . $name);
        }
        if ($type === FieldType::Choice && $choices === []) {
            throw new \LogicException('Choice field needs choices: ' . $name);
        }
    }
}
