<?php

/**
 * The model sent arguments a tool cannot use. The message goes back to the
 * model so it can correct itself, so it must not contain patient data.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

final class InvalidToolInput extends \DomainException
{
}
