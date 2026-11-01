<?php

/**
 * The agent loop: send the conversation and tools to the model, run the
 * tools it asks for, send the results back, until it answers in words or
 * the tool-call limit is reached. A turn that fails is not kept, so the
 * stored conversation is always valid to send again.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Agent;

use OpenEMR\Modules\AiAssistant\Audit\AuditLog;
use OpenEMR\Modules\AiAssistant\Bedrock\BedrockException;
use OpenEMR\Modules\AiAssistant\Bedrock\ConverseClient;
use OpenEMR\Modules\AiAssistant\Bedrock\ConverseRequest;
use OpenEMR\Modules\AiAssistant\Settings\Settings;
use OpenEMR\Modules\AiAssistant\Tools\ToolRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidFactoryInterface;

final readonly class Assistant
{
    public const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        private ConverseClient $model,
        private ToolRegistry $tools,
        private ConversationStore $conversations,
        private AuditLog $audit,
        private Settings $settings,
        private ClockInterface $clock,
        private \DateTimeZone $timezone,
        private UuidFactoryInterface $uuids,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(StaffUser $user, string $text): AgentReply
    {
        $text = trim($text);
        if ($text === '') {
            return AgentReply::failure('Type a question first.');
        }
        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            return AgentReply::failure('That message is too long. Please keep it under ' . self::MAX_MESSAGE_LENGTH . ' characters.');
        }
        $now = $this->clock->now();
        if ($this->audit->messagesSince($user, $now->modify('-1 hour')) >= $this->settings->messagesPerHour) {
            return AgentReply::failure('You have reached the limit of ' . $this->settings->messagesPerHour . ' messages per hour. Please try again later.');
        }

        $requestId = $this->uuids->uuid4()->toString();
        $this->audit->userMessage($user, $requestId, $text);

        $messages = $this->conversations->load($user);
        $messages[] = ['role' => 'user', 'content' => [['text' => $text]]];
        $system = SystemPrompt::build($this->settings->practiceName, $user, $now->setTimezone($this->timezone));
        $specs = $this->tools->specs();
        $used = [];
        $inputTokens = 0;
        $outputTokens = 0;

        try {
            while (true) {
                $response = $this->model->converse(new ConverseRequest(
                    modelId: $this->settings->modelId,
                    system: $system,
                    messages: $messages,
                    tools: $specs,
                    maxTokens: $this->settings->maxOutputTokens,
                ));
                $inputTokens += $response->inputTokens;
                $outputTokens += $response->outputTokens;
                $messages[] = $response->message;

                if (!$response->wantsTools()) {
                    $answer = $response->text !== '' ? $response->text : 'Sorry, I don\'t have an answer for that. Try rephrasing.';
                    $this->conversations->save($user, $messages);
                    $this->audit->reply($user, $requestId, $answer, $this->settings->modelId, $inputTokens, $outputTokens);
                    return AgentReply::answer($answer, $used);
                }

                if (count($used) + count($response->toolUses) > $this->settings->maxToolCalls) {
                    $this->audit->error($user, $requestId, 'Tool call limit reached', $this->settings->modelId);
                    return AgentReply::failure('I couldn\'t finish that in ' . $this->settings->maxToolCalls . ' steps. Please ask in a more specific way, for example with the patient\'s date of birth or an exact date.', $used);
                }

                $results = [];
                foreach ($response->toolUses as $call) {
                    $result = $this->tools->run($call);
                    $used[] = $call->name;
                    $this->audit->toolCall($user, $requestId, $call, $result);
                    // Status is left out: not every model accepts it. Errors say so in the text.
                    $results[] = ['toolResult' => ['toolUseId' => $call->id, 'content' => [['text' => $result->toText()]]]];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (BedrockException $e) {
            $this->logger->error('AI Assistant model call failed', ['exception' => $e, 'status' => $e->httpStatus]);
            $this->audit->error($user, $requestId, $e->getMessage(), $this->settings->modelId);
            return AgentReply::failure($e->error->userMessage(), $used);
        }
    }

    public function reset(StaffUser $user): void
    {
        $this->conversations->clear($user);
    }
}
