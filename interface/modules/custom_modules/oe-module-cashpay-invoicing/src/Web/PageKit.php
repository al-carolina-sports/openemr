<?php

/**
 * Rendering, redirects, access and CSRF checks shared by the module's pages.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Web;

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\CashpayInvoicing\Access;
use OpenEMR\Modules\CashpayInvoicing\AclObject;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Twig\Environment;

final readonly class PageKit
{
    public const CSRF_FIELD = 'csrf_token_form';

    /**
     * @param array<string, mixed> $globals variables every template gets
     */
    public function __construct(
        public Request $request,
        private SessionInterface $session,
        private Environment $twig,
        public Access $access,
        private array $globals,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context, int $status = 200): Response
    {
        $notice = Notice::fromName($this->request->query->getString('notice'));
        $context += $this->globals + [
            'csrfField' => self::CSRF_FIELD,
            'csrfToken' => CsrfUtils::collectCsrfToken(session: $this->session),
            'notice' => $notice?->text(),
        ];
        return new Response($this->twig->render($template, $context), $status);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function renderString(string $template, array $context): string
    {
        return $this->twig->render($template, $context + $this->globals);
    }

    /**
     * @param array<string, string|int> $params
     */
    public function redirect(string $url, array $params, Notice $notice, string $anchor = ''): RedirectResponse
    {
        $params['notice'] = $notice->name;
        return new RedirectResponse($url . '?' . http_build_query($params) . ($anchor !== '' ? '#' . rawurlencode($anchor) : ''));
    }

    public function deny(AclObject $object): ?Response
    {
        return $this->access->can($object) ? null : new Response(xlt('Access denied'), 403);
    }

    public function checkCsrf(): ?Response
    {
        $token = $this->request->request->getString(self::CSRF_FIELD);
        return CsrfUtils::verifyCsrfToken($token, session: $this->session) ? null : new Response(xlt('Authentication error'), 403);
    }

    public function isPost(): bool
    {
        return $this->request->isMethod('POST');
    }
}
