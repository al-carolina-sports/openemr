<?php

/**
 * JSON endpoint for the chat panel: POST action=send (message) or
 * action=reset. Checks CSRF, that the assistant is enabled and that the user
 * may use it, then releases the PHP session lock before the (slow) model
 * call so other OpenEMR tabs keep working.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Web;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\AiAssistant\Access;
use OpenEMR\Modules\AiAssistant\AclObject;
use OpenEMR\Modules\AiAssistant\Agent\AgentReply;
use OpenEMR\Modules\AiAssistant\Agent\StaffUser;
use OpenEMR\Modules\AiAssistant\Bedrock\BedrockException;
use OpenEMR\Modules\AiAssistant\ModuleServices;
use OpenEMR\Modules\AiAssistant\Tools\PermissionSnapshot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class ChatEndpoint
{
    public function __construct(
        private Request $request,
        private SessionInterface $session,
        private Access $access,
        private ModuleServices $services,
    ) {
    }

    public static function create(): self
    {
        return new self(
            CurrentRequest::get(),
            SessionWrapperFactory::getInstance()->getActiveSession(),
            new Access(),
            new ModuleServices(),
        );
    }

    public function handle(): JsonResponse
    {
        if (!$this->request->isMethod('POST')) {
            return self::fail('Method not allowed', 405);
        }
        if (!CsrfUtils::verifyCsrfToken($this->request->request->getString('csrf_token'), session: $this->session)) {
            return self::fail('Your session has expired. Reload OpenEMR and try again.', 403);
        }
        if (!$this->access->can(AclObject::Use)) {
            return self::fail('You do not have permission to use the AI Assistant.', 403);
        }
        if (!$this->services->settings()->enabled) {
            return self::fail('The AI Assistant is turned off.', 403);
        }
        $user = $this->user();
        if ($user === null) {
            return self::fail('Your session has expired. Reload OpenEMR and try again.', 403);
        }

        // Read the rights now: the session (and its lock) is released before the slow model call.
        $permissions = PermissionSnapshot::of($this->access);
        $this->session->save();

        try {
            $assistant = $this->services->assistant($permissions);
            if ($this->request->request->getString('action') === 'reset') {
                $assistant->reset($user);
                return new JsonResponse(['reply' => '', 'error' => false, 'tools' => []]);
            }
            $reply = $assistant->handle($user, $this->request->request->getString('message'));
        } catch (BedrockException $e) {
            ServiceContainer::getLogger()->error('AI Assistant setup failed', ['exception' => $e]);
            $reply = AgentReply::failure($e->error->userMessage());
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error('AI Assistant request failed', ['exception' => $e]);
            $reply = AgentReply::failure('Something went wrong. Please try again, or click "New chat".');
        }
        return new JsonResponse($reply->toArray());
    }

    private function user(): ?StaffUser
    {
        $id = $this->session->get('authUserID');
        $username = $this->session->get('authUser');
        $group = $this->session->get('authProvider');
        if (!is_numeric($id) || (int) $id <= 0 || !is_string($username)) {
            return null;
        }
        $row = $this->services->db->fetchOne(
            'SELECT `fname`, `lname`, `title`, `authorized` FROM `users` WHERE `id` = ?',
            [(int) $id]
        );
        $first = is_string($row['fname'] ?? null) ? $row['fname'] : '';
        $last = is_string($row['lname'] ?? null) ? $row['lname'] : '';
        $title = is_string($row['title'] ?? null) ? $row['title'] : '';
        return new StaffUser(
            id: (int) $id,
            username: $username,
            displayName: trim(($title !== '' ? $title . ' ' : '') . $first . ' ' . $last),
            group: is_string($group) ? $group : 'Default',
            isProvider: in_array($row['authorized'] ?? null, [1, '1'], true),
        );
    }

    /**
     * @param literal-string $message
     */
    private static function fail(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['reply' => xl($message), 'error' => true, 'tools' => []], $status);
    }
}
