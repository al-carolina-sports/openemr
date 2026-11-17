<?php

/**
 * ZeroDoc JSON endpoint -- the security template every later ZeroDoc endpoint
 * copies. Pattern from oe-module-appt-hovercard/public/ajax.php, which is the
 * most security-conscious endpoint in this install.
 *
 *   GET ?action=status   -> module/schema status for the open encounter
 *
 * Every request must satisfy, IN THIS ORDER, before anything is read:
 *   1. a logged-in session                       -> 401
 *   2. a valid CSRF token in the X-CSRF-Token
 *      HEADER, never in the URL or the body      -> 403
 *   3. the relevant core ACL (encounters/notes)  -> 403
 *   4. ZeroDoc's own ACO object + allowed role,
 *      checked SERVER-SIDE                       -> 403
 *   5. a patient and encounter open in the
 *      session -- never taken from the URL       -> 403
 * Fields a role may not see are dropped HERE, on the server. Never send them
 * and hide them in JavaScript. All SQL uses bound parameters.
 *
 * Core additionally 403s every script in this directory when the module is
 * disabled, so there is no enabled-check here.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ZeroDoc\AccessPolicy;
use OpenEMR\Modules\ZeroDoc\Capability;
use OpenEMR\Modules\ZeroDoc\Context;
use OpenEMR\Modules\ZeroDoc\Forms;
use OpenEMR\Modules\ZeroDoc\RoleResolver;
use OpenEMR\Modules\ZeroDoc\SchemaVersion;

/**
 * @param array<string, mixed> $body
 */
function zdocRespond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// 1. session
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$username = $session->get('authUser');
if (!is_string($username) || $username === '') {
    zdocRespond(401, ['error' => 'not_authenticated']);
}

// 2. CSRF in the header
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($token) || !CsrfUtils::verifyCsrfToken($token, session: $session)) {
    zdocRespond(403, ['error' => 'forbidden']);
}

// 3 + 5. core ACL, open patient and encounter
$ctx = Context::current();
if ($ctx === null) {
    zdocRespond(403, ['error' => 'no_access_or_no_open_encounter']);
}

// 4. ZeroDoc's own permission and allowed role
$policy = new AccessPolicy((new RoleResolver())->titlesFor($ctx->username));
if (!$policy->canViewUi()) {
    zdocRespond(403, ['error' => 'forbidden']);
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Read-only actions may be GET; everything else must be POST.
if ($action !== 'status' && $method !== 'POST') {
    zdocRespond(405, ['error' => 'method_not_allowed']);
}

if ($action === 'status') {
    $body = [
        'ok' => true,
        'module' => 'zerodoc',
        'version' => \OpenEMR\Modules\ZeroDoc\Bootstrap::ASSET_VERSION,
        'encounter' => $ctx->encounter,
        'forms' => Forms::formdirs(),
        'schema' => SchemaVersion::all(),
        'can_document' => $policy->canDocument(),
        // Capability answers let the UI degrade instead of erroring when a
        // neighbouring module is disabled (survey R11).
        'capabilities' => [
            'prior_auth' => Capability::isEnabled(Capability::PRIOR_AUTH),
            'cashpay' => Capability::isEnabled(Capability::CASHPAY),
            'faxsms' => Capability::isEnabled(Capability::FAXSMS),
        ],
    ];
    // SERVER-SIDE field redaction: only a signer sees the attestation block.
    if ($policy->canSign()) {
        $body['can_sign'] = true;
    }
    zdocRespond(200, $body);
}

zdocRespond(400, ['error' => 'unknown_action']);
