<?php

/**
 * AWS Bedrock note generator through the model-agnostic Converse API (e.g. Meta Llama 4 Maverick).
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Provider;

use OpenEMR\Modules\ElegantAiScribe\Aws\AwsClient;
use RuntimeException;

class BedrockNoteGenerator implements NoteGeneratorInterface
{
    /** @var array<string, int>|null */
    private ?array $usage = null;

    public function __construct(
        private readonly AwsClient $aws,
        private readonly string $modelId,
        private readonly int $maxTokens,
        private readonly float $temperature = 0.1
    ) {
        if (!preg_match('/^[A-Za-z0-9._:\/-]{3,200}$/', $modelId)) {
            throw new RuntimeException('Invalid Bedrock model id');
        }
    }

    public function id(): string
    {
        return 'aws';
    }

    public function lastUsage(): ?array
    {
        return $this->usage;
    }

    public function generate(string $transcript, string $prompt, array $sections): array
    {
        $headings = implode(', ', array_values($sections));
        $res = $this->aws->bedrockConverse($this->modelId, [
            'system' => [['text' => $prompt]],
            'messages' => [[
                'role' => 'user',
                'content' => [['text' => "Visit transcript:\n<transcript>\n" . $transcript . "\n</transcript>\n"
                    . "Write the note now. Put each heading (" . $headings . ") on its own line followed by a colon. Plain text only."]],
            ]],
            'inferenceConfig' => ['maxTokens' => max(256, min(8000, $this->maxTokens)), 'temperature' => max(0.0, min(1.0, $this->temperature))],
        ]);
        $text = '';
        foreach ($res['output']['message']['content'] ?? [] as $part) {
            $text .= (string)($part['text'] ?? '');
        }
        $this->usage = [
            'input_tokens' => (int)($res['usage']['inputTokens'] ?? 0),
            'output_tokens' => (int)($res['usage']['outputTokens'] ?? 0),
            'latency_ms' => (int)($res['metrics']['latencyMs'] ?? 0),
        ];
        if (trim($text) === '') {
            throw new RuntimeException('Bedrock returned an empty note (stopReason ' . substr((string)($res['stopReason'] ?? '?'), 0, 30) . ')');
        }
        return NoteParser::parse($text, $sections);
    }
}
