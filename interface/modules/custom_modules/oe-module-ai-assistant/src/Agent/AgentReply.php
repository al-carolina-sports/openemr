<?php

/**
 * What the chat panel shows for one user message.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Agent;

final readonly class AgentReply
{
    /**
     * @param list<string> $toolsUsed names of the tools called, in order
     */
    private function __construct(
        public string $text,
        public bool $isError,
        public array $toolsUsed,
    ) {
    }

    /**
     * @param list<string> $toolsUsed
     */
    public static function answer(string $text, array $toolsUsed): self
    {
        return new self($text, false, $toolsUsed);
    }

    /**
     * @param list<string> $toolsUsed
     */
    public static function failure(string $text, array $toolsUsed = []): self
    {
        return new self($text, true, $toolsUsed);
    }

    /**
     * @return array{reply: string, error: bool, tools: list<string>}
     */
    public function toArray(): array
    {
        return ['reply' => $this->text, 'error' => $this->isError, 'tools' => $this->toolsUsed];
    }
}
