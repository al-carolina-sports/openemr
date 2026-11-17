<?php

/**
 * Appointment Hover Card endpoint (read-only):
 *   GET ?eid=<appointment id>           JSON card
 *   GET ?action=photo&pid=<patient id>   JPEG thumbnail
 * The CSRF token is sent in the X-CSRF-Token header (never the URL).
 *
 * Requires a logged-in session, a valid CSRF token, calendar access, and a
 * role allowed to see the card. Returns 403 otherwise. DOB, contact data and
 * the photo additionally need core's patients/demo ACL.
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

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
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

$canSeeDemographics = AclMain::aclCheckCore('patients', 'demo');

if (($_GET['action'] ?? '') === 'photo') {
    $pid = filter_var($_GET['pid'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($pid) || $pid <= 0) {
        apptHoverCardRespond(400, ['error' => 'bad_request']);
    }
    if (!$canSeeDemographics || !(bool)$settings['photo_show']) {
        apptHoverCardRespond(403, ['error' => 'forbidden']);
    }
    $size = \OpenEMR\Modules\ApptHoverCard\Settings::PHOTO_SIZES[(string)$settings['photo_size']] ?? 100;
    try {
        // 2x for high-density screens; the browser scales it down.
        $jpeg = (new \OpenEMR\Modules\ApptHoverCard\PhotoService())->thumbnailFor($pid, (string)$settings['photo_category'], $size * 2);
    } catch (Throwable $e) {
        error_log('oe-module-appt-hovercard: ' . $e::class . ' while building photo for patient ' . $pid);
        apptHoverCardRespond(500, ['error' => 'server_error']);
    }
    if ($jpeg === null) {
        apptHoverCardRespond(404, ['error' => 'not_found']);
    }
    http_response_code(200);
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $jpeg;
    exit;
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
    $card = $service->build($eid, $canSeeDemographics);
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
