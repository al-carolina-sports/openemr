<?php

/**
 * Admin > AI Assistant: turn the assistant on or off, pick the model and
 * limits, and test the AWS connection (sends no patient data).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Web;

use GuzzleHttp\Client;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\AiAssistant\Access;
use OpenEMR\Modules\AiAssistant\AclObject;
use OpenEMR\Modules\AiAssistant\Bedrock\BedrockException;
use OpenEMR\Modules\AiAssistant\Bedrock\ConverseRequest;
use OpenEMR\Modules\AiAssistant\Bedrock\HttpConverseClient;
use OpenEMR\Modules\AiAssistant\ModuleServices;
use OpenEMR\Modules\AiAssistant\ModuleTwig;
use OpenEMR\Modules\AiAssistant\Settings\Settings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Twig\Environment;

final readonly class SettingsPage
{
    public const CSRF_FIELD = 'csrf_token_form';

    public function __construct(
        private Request $request,
        private SessionInterface $session,
        private Environment $twig,
        private Access $access,
        private ModuleServices $services,
        private int $userId,
    ) {
    }

    public static function create(): self
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $userId = $session->get('authUserID');
        return new self(
            CurrentRequest::get(),
            $session,
            ModuleTwig::environment(),
            new Access(),
            new ModuleServices(),
            is_numeric($userId) ? (int) $userId : 0,
        );
    }

    public function handle(): Response
    {
        if (!$this->access->can(AclObject::Settings)) {
            return new Response(xlt('Access denied'), 403);
        }
        $notice = null;
        $testResult = null;
        if ($this->request->isMethod('POST')) {
            if (!CsrfUtils::verifyCsrfToken($this->request->request->getString(self::CSRF_FIELD), session: $this->session)) {
                return new Response(xlt('Authentication error'), 403);
            }
            if ($this->request->request->getString('action') === 'test') {
                $testResult = $this->testConnection();
            } else {
                $this->services->settingsRepository->save($this->submitted(), $this->userId);
                $path = explode('?', $this->request->getRequestUri(), 2)[0];
                return new RedirectResponse($path . '?saved=1');
            }
        } elseif ($this->request->query->getString('saved') === '1') {
            $notice = 'Settings saved.';
        }

        $settings = $this->services->settingsRepository->load();
        return new Response($this->twig->render('@ai_assistant/settings.html.twig', [
            'settings' => $settings,
            'credentialsSource' => $this->services->credentials()->describeSource(),
            'notice' => $notice,
            'test' => $testResult,
            'csrfField' => self::CSRF_FIELD,
            'csrfToken' => CsrfUtils::collectCsrfToken($this->session),
            'weekdays' => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'],
            'officeHours' => $this->services->officeHours(),
            'timezone' => ModuleServices::timezone()->getName(),
        ]));
    }

    private function submitted(): Settings
    {
        $post = $this->request->request;
        return Settings::fromArray([
            'enabled' => $post->has('enabled'),
            'model_id' => $post->getString('model_id'),
            'region' => $post->getString('region'),
            'credentials_file' => $post->getString('credentials_file'),
            'max_tool_calls' => $post->getString('max_tool_calls'),
            'messages_per_hour' => $post->getString('messages_per_hour'),
            'log_retention_days' => $post->getString('log_retention_days'),
            'max_output_tokens' => $post->getString('max_output_tokens'),
            'use_default_hours' => $post->has('use_default_hours'),
            'working_days' => $post->all('working_days'),
            'practice_name' => $post->getString('practice_name'),
        ]);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function testConnection(): array
    {
        $settings = $this->services->settingsRepository->load();
        $client = new HttpConverseClient(
            new Client(['timeout' => 60, 'connect_timeout' => 10, 'http_errors' => false]),
            ServiceContainer::getRequestFactory(),
            ServiceContainer::getStreamFactory(),
            $this->services->credentials(),
            ServiceContainer::getClock(),
            $settings->region,
        );
        $started = microtime(true);
        try {
            $response = $client->converse(new ConverseRequest(
                $settings->modelId,
                'Answer with one word.',
                [['role' => 'user', 'content' => [['text' => 'Reply with the word OK.']]]],
                [],
                20,
            ));
        } catch (BedrockException $e) {
            ServiceContainer::getLogger()->warning('AI Assistant connection test failed', ['exception' => $e]);
            return ['ok' => false, 'message' => $e->error->userMessage() . ' (' . $e->getMessage() . ')'];
        }
        return [
            'ok' => true,
            'message' => sprintf('%s answered "%s" in %.1f seconds.', $settings->modelId, mb_substr($response->text, 0, 60), microtime(true) - $started),
        ];
    }
}
