<?php

/**
 * Each user's running conversation, in Converse message format, kept in the
 * database (not the PHP session) and forgotten after 2 idle hours.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Agent;

use OpenEMR\Modules\AiAssistant\Bedrock\Json;
use OpenEMR\Modules\AiAssistant\Database\Database;
use OpenEMR\Modules\AiAssistant\Database\Row;
use Psr\Clock\ClockInterface;

final readonly class ConversationStore
{
    public const IDLE_HOURS = 2;
    /** Messages kept for context; older turns are dropped. */
    public const MAX_MESSAGES = 30;

    public function __construct(private Database $db, private ClockInterface $clock)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function load(StaffUser $user): array
    {
        $row = $this->db->fetchOne(
            'SELECT `messages` FROM `mod_ai_assistant_conversation` WHERE `user_id` = ? AND `updated_at` >= ?',
            [$user->id, $this->clock->now()->modify('-' . self::IDLE_HOURS . ' hours')->format('Y-m-d H:i:s')]
        );
        if ($row === null) {
            return [];
        }
        try {
            $decoded = json_decode(Row::str($row, 'messages'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }
        $messages = [];
        foreach ($decoded as $message) {
            if (is_array($message) && isset($message['role'], $message['content'])) {
                $clean = [];
                foreach ($message as $k => $v) {
                    if (is_string($k)) {
                        $clean[$k] = $v;
                    }
                }
                $messages[] = $clean;
            }
        }
        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function save(StaffUser $user, array $messages): void
    {
        $this->db->execute(
            'INSERT INTO `mod_ai_assistant_conversation` (`user_id`, `messages`, `updated_at`) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `messages` = VALUES(`messages`), `updated_at` = VALUES(`updated_at`)',
            [$user->id, Json::encode(self::trim($messages)), $this->clock->now()->format('Y-m-d H:i:s')]
        );
    }

    public function clear(StaffUser $user): void
    {
        $this->db->execute('DELETE FROM `mod_ai_assistant_conversation` WHERE `user_id` = ?', [$user->id]);
    }

    /**
     * Keeps at most MAX_MESSAGES, cutting only before a user's typed message
     * so a tool call is never separated from its result.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function trim(array $messages): array
    {
        $count = count($messages);
        if ($count <= self::MAX_MESSAGES) {
            return $messages;
        }
        for ($i = $count - self::MAX_MESSAGES; $i < $count; $i++) {
            if (self::isTypedUserMessage($messages[$i])) {
                return array_slice($messages, $i);
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function isTypedUserMessage(array $message): bool
    {
        if (($message['role'] ?? null) !== 'user' || !is_array($message['content'] ?? null)) {
            return false;
        }
        foreach ($message['content'] as $block) {
            if (is_array($block) && isset($block['toolResult'])) {
                return false;
            }
        }
        return true;
    }
}
