<?php

/**
 * One tool call requested by the model.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

final readonly class ToolUse
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
    ) {
    }
}
