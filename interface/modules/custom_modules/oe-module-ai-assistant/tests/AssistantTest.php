<?php

/**
 * The agent loop against a scripted model: tool round trips, limits,
 * failures, and what gets saved and audited.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests;

use OpenEMR\Modules\AiAssistant\Agent\Assistant;
use OpenEMR\Modules\AiAssistant\Agent\ConversationStore;
use OpenEMR\Modules\AiAssistant\Agent\StaffUser;
use OpenEMR\Modules\AiAssistant\Audit\AuditLog;
use OpenEMR\Modules\AiAssistant\Audit\PatientAccessRecorder;
use OpenEMR\Modules\AiAssistant\Bedrock\BedrockError;
use OpenEMR\Modules\AiAssistant\Bedrock\BedrockException;
use OpenEMR\Modules\AiAssistant\Bedrock\ConverseClient;
use OpenEMR\Modules\AiAssistant\Bedrock\ConverseRequest;
use OpenEMR\Modules\AiAssistant\Bedrock\ConverseResponse;
use OpenEMR\Modules\AiAssistant\Bedrock\Json;
use OpenEMR\Modules\AiAssistant\Settings\Settings;
use OpenEMR\Modules\AiAssistant\Tests\Support\Dig;
use OpenEMR\Modules\AiAssistant\Tests\Support\FakeDatabase;
use OpenEMR\Modules\AiAssistant\Tests\Support\Fakes;
use OpenEMR\Modules\AiAssistant\Tests\Support\FixedClock;
use OpenEMR\Modules\AiAssistant\Tools\FindPatientTool;
use OpenEMR\Modules\AiAssistant\Tools\PermissionSnapshot;
use OpenEMR\Modules\AiAssistant\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\UuidFactory;

final class AssistantTest extends TestCase
{
    private FakeDatabase $db;
    /** @var object{views: list<array{int, int, string}>}&PatientAccessRecorder */
    private PatientAccessRecorder $recorder;
    private StaffUser $user;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->recorder = new class () implements PatientAccessRecorder {
            /** @var list<array{int, int, string}> */
            public array $views = [];

            public function record(StaffUser $user, int $pid, string $comment): void
            {
                $this->views[] = [$user->id, $pid, $comment];
            }
        };
        $this->user = new StaffUser(5, 'frontdesk', 'Sam Front', 'Default', false);
    }

    public function testToolRoundTripAnswersSavesAndAudits(): void
    {
        $model = $this->model(
            self::toolCall('tu-1', 'find_patient', ['last_name' => 'Test', 'first_name' => 'Patricia']),
            self::text('Patricia Test (DOB 1968-03-14) was found.'),
        );
        $reply = $this->assistant($model)->handle($this->user, '  Find Patricia Test  ');

        self::assertFalse($reply->isError);
        self::assertSame('Patricia Test (DOB 1968-03-14) was found.', $reply->text);
        self::assertSame(['find_patient'], $reply->toolsUsed);

        // Second call carries the tool result back, paired by id.
        self::assertCount(2, $model->requests);
        $messages = $model->requests[1]->messages;
        self::assertCount(3, $messages);
        self::assertSame('Find Patricia Test', Dig::at($messages, 0, 'content', 0, 'text'));
        $result = Dig::at($messages, 2, 'content', 0, 'toolResult');
        self::assertIsArray($result);
        self::assertSame('tu-1', $result['toolUseId']);
        self::assertArrayNotHasKey('status', $result);
        self::assertStringContainsString('Patricia Test', Json::encode($result['content']));

        // The full exchange is saved for the next question.
        $saved = $this->db->writesTo('mod_ai_assistant_conversation');
        self::assertCount(1, $saved);
        $stored = json_decode((string) $saved[0][1], true);
        self::assertIsArray($stored);
        self::assertCount(4, $stored);

        // Audit: user message, tool call (with pid), reply; one patient view in the core log.
        $roles = array_map(static fn(array $b): mixed => $b[3], $this->db->writesTo('mod_ai_assistant_log'));
        self::assertSame(['user', 'tool', 'assistant'], $roles);
        $tool = $this->db->writesTo('mod_ai_assistant_log')[1];
        self::assertSame('find_patient', $tool[5]);
        self::assertSame(101, $tool[8]);
        self::assertSame([[5, 101, 'AI Assistant find_patient']], $this->recorder->views);
    }

    public function testToolLimitFailsWithoutSavingTheTurn(): void
    {
        $calls = [];
        for ($i = 0; $i < 3; $i++) {
            $calls[] = self::toolCall('tu-' . $i, 'find_patient', ['last_name' => 'Test']);
        }
        $reply = $this->assistant($this->model(...$calls), new Settings(maxToolCalls: 2))->handle($this->user, 'Loop forever');

        self::assertTrue($reply->isError);
        self::assertStringContainsString('2 steps', $reply->text);
        self::assertSame(['find_patient', 'find_patient'], $reply->toolsUsed);
        self::assertSame([], $this->db->writesTo('mod_ai_assistant_conversation'));
        self::assertSame('error', $this->db->writesTo('mod_ai_assistant_log')[3][3] ?? null);
    }

    public function testModelErrorGivesAFriendlyMessage(): void
    {
        $model = $this->model(new BedrockException(BedrockError::Throttled, 'ThrottlingException: Too many requests', 429));
        $reply = $this->assistant($model)->handle($this->user, 'Who is on today?');

        self::assertTrue($reply->isError);
        self::assertSame(BedrockError::Throttled->userMessage(), $reply->text);
        self::assertStringNotContainsString('ThrottlingException', $reply->text);
        self::assertSame([], $this->db->writesTo('mod_ai_assistant_conversation'));
    }

    public function testEmptyLongAndRateLimitedMessagesNeverReachTheModel(): void
    {
        $model = $this->model();
        $assistant = $this->assistant($model, new Settings(messagesPerHour: 3));

        self::assertTrue($assistant->handle($this->user, '   ')->isError);
        self::assertTrue($assistant->handle($this->user, str_repeat('a', Assistant::MAX_MESSAGE_LENGTH + 1))->isError);
        $this->db->on('COUNT(*)', [['n' => 3]]);
        $limited = $assistant->handle($this->user, 'One more');
        self::assertStringContainsString('3 messages per hour', $limited->text);
        self::assertSame([], $model->requests);
        self::assertSame([], $this->db->writes);
    }

    public function testEarlierConversationIsSentAgain(): void
    {
        $history = [
            ['role' => 'user', 'content' => [['text' => 'Hi']]],
            ['role' => 'assistant', 'content' => [['text' => 'Hello.']]],
        ];
        $this->db->on('SELECT `messages`', [['messages' => Json::encode($history)]]);
        $model = $this->model(self::text('Sure.'));
        $this->assistant($model)->handle($this->user, 'And now?');

        self::assertSame('Hi', Dig::at($model->requests[0]->messages, 0, 'content', 0, 'text'));
        self::assertSame('And now?', Dig::at($model->requests[0]->messages, 2, 'content', 0, 'text'));
        self::assertStringContainsString('Sam Front', $model->requests[0]->system);
    }

    public function testResetClearsTheConversation(): void
    {
        $this->assistant($this->model())->reset($this->user);
        self::assertSame([[5]], $this->db->writesTo('DELETE FROM `mod_ai_assistant_conversation`'));
    }

    private function assistant(ConverseClient $model, ?Settings $settings = null): Assistant
    {
        $clock = FixedClock::at('2026-11-01 12:00');
        $tools = new ToolRegistry([
            new FindPatientTool(Fakes::patients(Fakes::patient(101, 'Patricia', 'Test', '1968-03-14')), new PermissionSnapshot(true, true)),
        ], new NullLogger());
        return new Assistant(
            $model,
            $tools,
            new ConversationStore($this->db, $clock),
            new AuditLog($this->db, $clock, $this->recorder),
            $settings ?? new Settings(enabled: true),
            $clock,
            new \DateTimeZone('America/New_York'),
            new UuidFactory(),
            new NullLogger(),
        );
    }

    /**
     * @return ConverseClient&object{requests: list<ConverseRequest>}
     */
    private function model(ConverseResponse|BedrockException ...$script): ConverseClient
    {
        return new class (array_values($script)) implements ConverseClient {
            /** @var list<ConverseRequest> */
            public array $requests = [];

            /**
             * @param list<ConverseResponse|BedrockException> $script
             */
            public function __construct(private array $script)
            {
            }

            public function converse(ConverseRequest $request): ConverseResponse
            {
                $this->requests[] = $request;
                $next = array_shift($this->script);
                if ($next === null) {
                    throw new \LogicException('The scripted model has no more replies');
                }
                if ($next instanceof BedrockException) {
                    throw $next;
                }
                return $next;
            }
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function toolCall(string $id, string $name, array $input): ConverseResponse
    {
        return ConverseResponse::fromBody([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['toolUse' => ['toolUseId' => $id, 'name' => $name, 'input' => $input]],
            ]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 100, 'outputTokens' => 10],
        ]);
    }

    private static function text(string $text): ConverseResponse
    {
        return ConverseResponse::fromBody([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => $text]]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 120, 'outputTokens' => 20],
        ]);
    }
}
