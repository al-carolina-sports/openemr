<?php

/**
 * What a tool hands back to the model, plus what the audit log keeps.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

use OpenEMR\Modules\AiAssistant\Bedrock\Json;

final readonly class ToolResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<int> $patientIds patients whose data the result contains (for the audit log)
     */
    private function __construct(
        public bool $ok,
        public array $data,
        public string $summary,
        public array $patientIds,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<int> $patientIds
     */
    public static function ok(array $data, string $summary, array $patientIds = []): self
    {
        return new self(true, $data, $summary, $patientIds);
    }

    public static function error(string $message): self
    {
        return new self(false, ['error' => $message], 'error: ' . $message, []);
    }

    /**
     * The text given to the model as the toolResult content.
     */
    public function toText(): string
    {
        return Json::encode($this->data);
    }
}
