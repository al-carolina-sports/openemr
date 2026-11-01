<?php

/**
 * The parsed result of a Converse call.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

final readonly class ConverseResponse
{
    /**
     * @param array{role: string, content: list<array<string, mixed>>} $message the assistant message, as Converse returned it
     * @param list<ToolUse> $toolUses
     */
    public function __construct(
        public array $message,
        public string $text,
        public array $toolUses,
        public string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
    ) {
    }

    public function wantsTools(): bool
    {
        return $this->toolUses !== [];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $output = $body['output'] ?? null;
        $message = is_array($output) ? ($output['message'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;
        if (!is_array($content) || !array_is_list($content)) {
            throw new BedrockException(BedrockError::Service, 'Converse response has no output message');
        }

        $blocks = [];
        $texts = [];
        $toolUses = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            $clean = [];
            foreach ($block as $k => $v) {
                if (is_string($k)) {
                    $clean[$k] = $v;
                }
            }
            if (isset($clean['text']) && is_string($clean['text'])) {
                $texts[] = $clean['text'];
                $blocks[] = ['text' => $clean['text']];
                continue;
            }
            $use = $clean['toolUse'] ?? null;
            if (is_array($use)) {
                $id = $use['toolUseId'] ?? null;
                $name = $use['name'] ?? null;
                $input = $use['input'] ?? [];
                if (!is_string($id) || !is_string($name)) {
                    continue;
                }
                $args = [];
                if (is_array($input)) {
                    foreach ($input as $k => $v) {
                        if (is_string($k)) {
                            $args[$k] = $v;
                        }
                    }
                }
                $toolUses[] = new ToolUse($id, $name, $args);
                $blocks[] = ['toolUse' => ['toolUseId' => $id, 'name' => $name, 'input' => $args]];
                continue;
            }
            // Reasoning or other block types are not replayed.
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $stop = $body['stopReason'] ?? '';
        return new self(
            message: ['role' => 'assistant', 'content' => $blocks],
            text: trim(implode("\n", $texts)),
            toolUses: $toolUses,
            stopReason: is_string($stop) ? $stop : '',
            inputTokens: is_int($usage['inputTokens'] ?? null) ? $usage['inputTokens'] : 0,
            outputTokens: is_int($usage['outputTokens'] ?? null) ? $usage['outputTokens'] : 0,
        );
    }
}
