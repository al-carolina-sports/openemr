<?php

/**
 * The assistant's audit trail (mod_ai_assistant_log): every user message,
 * tool call, reply and error, grouped by request id. Patient reads are also
 * recorded in OpenEMR's own audit log through PatientAccessRecorder.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Audit;

use OpenEMR\Modules\AiAssistant\Agent\StaffUser;
use OpenEMR\Modules\AiAssistant\Bedrock\Json;
use OpenEMR\Modules\AiAssistant\Bedrock\ToolUse;
use OpenEMR\Modules\AiAssistant\Database\Database;
use OpenEMR\Modules\AiAssistant\Database\Row;
use OpenEMR\Modules\AiAssistant\Tools\ToolResult;
use Psr\Clock\ClockInterface;

final readonly class AuditLog
{
    public function __construct(
        private Database $db,
        private ClockInterface $clock,
        private PatientAccessRecorder $patientAccess,
    ) {
    }

    public function userMessage(StaffUser $user, string $requestId, string $text): void
    {
        $this->insert($user, $requestId, 'user', $text);
    }

    public function toolCall(StaffUser $user, string $requestId, ToolUse $call, ToolResult $result): void
    {
        $pids = $result->patientIds;
        $this->insert(
            $user,
            $requestId,
            'tool',
            null,
            toolName: $call->name,
            toolInput: Json::encode($call->input),
            summary: mb_substr($result->summary, 0, 1000),
            pid: count($pids) === 1 ? $pids[0] : null,
        );
        foreach ($pids as $pid) {
            $this->patientAccess->record($user, $pid, 'AI Assistant ' . $call->name);
        }
    }

    public function reply(StaffUser $user, string $requestId, string $text, string $modelId, int $inputTokens, int $outputTokens): void
    {
        $this->insert($user, $requestId, 'assistant', $text, modelId: $modelId, inputTokens: $inputTokens, outputTokens: $outputTokens);
    }

    public function error(StaffUser $user, string $requestId, string $message, string $modelId = ''): void
    {
        $this->insert($user, $requestId, 'error', mb_substr($message, 0, 2000), modelId: $modelId !== '' ? $modelId : null);
    }

    /**
     * How many messages the user sent since $since (for the hourly limit).
     */
    public function messagesSince(StaffUser $user, \DateTimeImmutable $since): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS `n` FROM `mod_ai_assistant_log` WHERE `user_id` = ? AND `role` = 'user' AND `created_at` >= ?",
            [$user->id, $since->format('Y-m-d H:i:s')]
        );
        return $row === null ? 0 : Row::int($row, 'n');
    }

    /**
     * Deletes log rows and idle conversations older than $days days.
     */
    public function purgeOlderThan(int $days): void
    {
        $cutoff = $this->clock->now()->modify('-' . max(1, $days) . ' days')->format('Y-m-d H:i:s');
        $this->db->execute('DELETE FROM `mod_ai_assistant_log` WHERE `created_at` < ?', [$cutoff]);
        $this->db->execute('DELETE FROM `mod_ai_assistant_conversation` WHERE `updated_at` < ?', [$cutoff]);
    }

    private function insert(
        StaffUser $user,
        string $requestId,
        string $role,
        ?string $content,
        ?string $toolName = null,
        ?string $toolInput = null,
        ?string $summary = null,
        ?int $pid = null,
        ?string $modelId = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
    ): void {
        $this->db->execute(
            'INSERT INTO `mod_ai_assistant_log` (`created_at`, `user_id`, `request_id`, `role`, `content`, `tool_name`, `tool_input`,'
            . ' `tool_result_summary`, `pid`, `model_id`, `input_tokens`, `output_tokens`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->clock->now()->format('Y-m-d H:i:s'),
                $user->id,
                $requestId,
                $role,
                $content,
                $toolName,
                $toolInput,
                $summary,
                $pid,
                $modelId,
                $inputTokens,
                $outputTokens,
            ]
        );
    }
}
