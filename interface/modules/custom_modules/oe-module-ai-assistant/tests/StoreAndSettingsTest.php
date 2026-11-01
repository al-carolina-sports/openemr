<?php

/**
 * Conversation trimming and settings parsing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests;

use OpenEMR\Modules\AiAssistant\Agent\ConversationStore;
use OpenEMR\Modules\AiAssistant\Agent\StaffUser;
use OpenEMR\Modules\AiAssistant\Settings\Settings;
use OpenEMR\Modules\AiAssistant\Tests\Support\Dig;
use OpenEMR\Modules\AiAssistant\Tests\Support\FakeDatabase;
use OpenEMR\Modules\AiAssistant\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class StoreAndSettingsTest extends TestCase
{
    public function testTrimNeverStartsOnAToolResult(): void
    {
        $messages = [];
        for ($i = 0; $i < 12; $i++) {
            $messages[] = ['role' => 'user', 'content' => [['text' => 'q' . $i]]];
            $messages[] = ['role' => 'assistant', 'content' => [['toolUse' => ['toolUseId' => 't' . $i, 'name' => 'x', 'input' => []]]]];
            $messages[] = ['role' => 'user', 'content' => [['toolResult' => ['toolUseId' => 't' . $i, 'content' => [['text' => '{}']]]]]];
            $messages[] = ['role' => 'assistant', 'content' => [['text' => 'a' . $i]]];
        }
        $trimmed = ConversationStore::trim($messages);
        self::assertLessThanOrEqual(ConversationStore::MAX_MESSAGES, count($trimmed));
        self::assertTrue(ConversationStore::isTypedUserMessage($trimmed[0]));
        self::assertSame('a11', Dig::at($trimmed, count($trimmed) - 1, 'content', 0, 'text'));
        self::assertSame(array_slice($messages, 0, 4), ConversationStore::trim(array_slice($messages, 0, 4)));
    }

    public function testStaleOrCorruptConversationsLoadEmpty(): void
    {
        $user = new StaffUser(5, 'u', 'U', 'Default', false);
        $db = new FakeDatabase();
        $store = new ConversationStore($db, FixedClock::at('2026-11-01 12:00'));
        self::assertSame([], $store->load($user));
        self::assertSame([5, '2026-11-01 10:00:00'], $db->reads[0]['binds']);

        $db->on('SELECT', [['messages' => '{not json']]);
        self::assertSame([], $store->load($user));
    }

    public function testSettingsKeepDefaultsForBadValues(): void
    {
        $settings = Settings::fromArray([
            'enabled' => '1',
            'model_id' => 'us.meta.llama4-maverick-17b-instruct-v1:0',
            'region' => 'not a region',
            'credentials_file' => 'relative/path.env',
            'max_tool_calls' => '99',
            'messages_per_hour' => '60',
            'working_days' => ['1', '3', '3', '9'],
            'practice_name' => '<script>',
        ]);
        self::assertTrue($settings->enabled);
        self::assertSame('us-east-1', $settings->region);
        self::assertSame(Settings::DEFAULT_CREDENTIALS_FILE, $settings->credentialsFile);
        self::assertSame(8, $settings->maxToolCalls);
        self::assertSame(60, $settings->messagesPerHour);
        self::assertSame([1, 3], $settings->workingDays);
        self::assertSame('Carolina Sports & Spine', $settings->practiceName);
    }

    public function testSettingsRoundTripThroughStorage(): void
    {
        $settings = new Settings(enabled: true, maxToolCalls: 5, workingDays: [2, 4], useDefaultHours: false);
        self::assertEquals($settings, Settings::fromArray($settings->toStorage()));
        self::assertFalse((new Settings())->enabled, 'The assistant must be off until an admin turns it on');
    }
}
