<?php

/**
 * One Converse call: model, system prompt, conversation and tools.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

final readonly class ConverseRequest
{
    /**
     * @param list<array<string, mixed>> $messages Converse messages ({role, content})
     * @param list<ToolSpec> $tools
     */
    public function __construct(
        public string $modelId,
        public string $system,
        public array $messages,
        public array $tools,
        public int $maxTokens,
        public float $temperature = 0.0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $body = [
            'messages' => $this->messages,
            'system' => [['text' => $this->system]],
            'inferenceConfig' => ['maxTokens' => $this->maxTokens, 'temperature' => $this->temperature],
        ];
        if ($this->tools !== []) {
            $body['toolConfig'] = ['tools' => array_map(static fn(ToolSpec $t): array => $t->toConverse(), $this->tools)];
        }
        return $body;
    }
}
