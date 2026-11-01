<?php

/**
 * One action the model may request. Phase 1 tools only read.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

use OpenEMR\Modules\AiAssistant\Bedrock\ToolSpec;

interface Tool
{
    public static function spec(): ToolSpec;

    /**
     * @throws InvalidToolInput when the arguments cannot be used
     */
    public function run(ToolInput $input): ToolResult;
}
