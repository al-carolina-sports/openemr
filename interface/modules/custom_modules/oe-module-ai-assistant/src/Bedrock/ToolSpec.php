<?php

/**
 * A tool definition sent to the model (Converse toolConfig.tools[].toolSpec).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

final readonly class ToolSpec
{
    /**
     * @param array<string, mixed> $inputSchema JSON schema of type "object"
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
    ) {
    }

    /**
     * @return array{toolSpec: array{name: string, description: string, inputSchema: array{json: mixed}}}
     */
    public function toConverse(): array
    {
        return ['toolSpec' => [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => ['json' => Json::objects($this->inputSchema)],
        ]];
    }
}
