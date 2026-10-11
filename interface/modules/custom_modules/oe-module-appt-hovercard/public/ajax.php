<?php

/**
 * Appointment Hover Card endpoint: GET ?eid=<appointment id>&csrf_token_form=<token>
 *
 * Read-only. Requires a logged-in session, a valid CSRF token, calendar
 * access, and a role allowed to see the card. Returns 403 otherwise.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../globals.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ApptHoverCard\AccessPolicy;
use OpenEMR\Modules\ApptHoverCard\HoverCardService;
use OpenEMR\Modules\ApptHoverCard\RoleResolver;
use OpenEMR\Modules\ApptHoverCard\SettingsRepository;

/**
 * @param array<string, mixed> $body
 */
function apptHoverCardRespond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$username = $session->get('authUser');
if (!is_string($username) || $username === '') {
    apptHoverCardRespond(401, ['error' => 'not_authenticated']);
}

$token = $_GET['csrf_token_form'] ?? '';
if (!is_string($token) || !CsrfUtils::verifyCsrfToken($token, session: $session)) {
    apptHoverCardRespond(403, ['error' => 'forbidden']);
}

if (!AclMain::aclCheckCore('patients', 'appt')) {
    apptHoverCardRespond(403, ['error' => 'forbidden']);
}

$settings = (new SettingsRepository())->all();
$access = new AccessPolicy($settings, (new RoleResolver())->titlesFor($username));
if (!$access->canViewCard()) {
    apptHoverCardRespond(403, ['error' => 'forbidden']);
}

$eid = filter_var($_GET['eid'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($eid) || $eid <= 0) {
    apptHoverCardRespond(400, ['error' => 'bad_request']);
}

try {
    $service = new HoverCardService(
        $settings,
        $access,
        new DateTimeImmutable('today'),
        OEGlobalsBag::getInstance()->getWebRoot()
    );
    $card = $service->build($eid);
} catch (Throwable $e) {
    error_log('oe-module-appt-hovercard: ' . $e::class . ' while building card for appointment ' . $eid);
    apptHoverCardRespond(500, ['error' => 'server_error']);
}

if ($card === null) {
    apptHoverCardRespond(404, ['error' => 'not_found']);
}

// One audit entry per patient per login session, not per hover.
if ((bool)$settings['audit_views'] && (int)$card['pid'] > 0) {
    $seen = $session->get('appt_hovercard_audited');
    $seen = is_array($seen) ? $seen : [];
    $pid = (int)$card['pid'];
    if (!in_array($pid, $seen, true)) {
        $seen[] = $pid;
        $session->set('appt_hovercard_audited', $seen);
        EventAuditLogger::getInstance()->newEvent(
            'view',
            $username,
            (string)$session->get('authProvider'),
            1,
            'Appointment hover card viewed',
            $pid
        );
    }
}

apptHoverCardRespond(200, $card);
