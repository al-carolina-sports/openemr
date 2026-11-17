<?php

/**
 * Custom Views endpoint (JSON). CSRF token in the X-CSRF-Token header.
 *   GET  ?action=list
 *   POST ?action=create    {name, shared, members[]}
 *   POST ?action=update    {id, name, shared, members[]}
 *   POST ?action=delete    {id}
 *   POST ?action=remember  {id|null}
 *   GET  ?action=weekends              current Sat/Sun state
 *   POST ?action=weekends  {day: 'sat'|'sun', hide: true|false|null}
 *        (null clears the user's choice so the site default applies)
 *
 * Anyone logged in sees shared views and their own; edits own views; only
 * admin/super may create or change shared views.
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
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ElegantCalendarStyle\ForbiddenException;
use OpenEMR\Modules\ElegantCalendarStyle\NotFoundException;
use OpenEMR\Modules\ElegantCalendarStyle\ViewRepository;
use OpenEMR\Modules\ElegantCalendarStyle\WeekendSettings;

function ecsRespond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$username = $session->get('authUser');
$userId = (int)$session->get('authUserID');
if (!is_string($username) || $username === '' || $userId <= 0) {
    ecsRespond(401, ['error' => 'not_authenticated']);
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($token) || !CsrfUtils::verifyCsrfToken($token, session: $session)) {
    ecsRespond(403, ['error' => 'forbidden']);
}

$isAdmin = AclMain::aclCheckCore('admin', 'super');
$repo = new ViewRepository($userId, $isAdmin);
$action = (string)($_GET['action'] ?? 'list');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($action === 'list') {
        ecsRespond(200, [
            'views' => $repo->visibleViews(),
            'users' => $repo->calendarUsers(),
            'isAdmin' => $isAdmin,
            'lastViewId' => $repo->lastViewId(),
        ]);
    }
    if ($action === 'weekends') {
        $weekends = new WeekendSettings();
        if ($method === 'POST') {
            $input = json_decode((string)file_get_contents('php://input'), true);
            $day = is_array($input) ? ($input['day'] ?? null) : null;
            $hide = is_array($input) && array_key_exists('hide', $input) ? $input['hide'] : 'missing';
            if (!WeekendSettings::isDay($day) || !($hide === null || is_bool($hide))) {
                ecsRespond(400, ['error' => 'bad_request']);
            }
            $weekends->setUserOverride($userId, $day, $hide);
        }
        ecsRespond(200, ['ok' => true, 'weekends' => $weekends->forUser($userId)]);
    }
    if ($method !== 'POST') {
        ecsRespond(405, ['error' => 'method_not_allowed']);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        ecsRespond(400, ['error' => 'bad_request']);
    }
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    switch ($action) {
        case 'create':
            $newId = $repo->create(ViewRepository::cleanName($input['name'] ?? null), !empty($input['shared']), $repo->cleanMembers($input['members'] ?? null));
            ecsRespond(200, ['ok' => true, 'id' => $newId]);
        case 'update':
            if (!is_int($id)) {
                ecsRespond(400, ['error' => 'bad_request']);
            }
            $repo->update($id, ViewRepository::cleanName($input['name'] ?? null), !empty($input['shared']), $repo->cleanMembers($input['members'] ?? null));
            ecsRespond(200, ['ok' => true, 'id' => $id]);
        case 'delete':
            if (!is_int($id)) {
                ecsRespond(400, ['error' => 'bad_request']);
            }
            $repo->delete($id);
            ecsRespond(200, ['ok' => true]);
        case 'remember':
            $repo->rememberView(is_int($id) ? $id : null);
            ecsRespond(200, ['ok' => true]);
        default:
            ecsRespond(400, ['error' => 'unknown_action']);
    }
} catch (InvalidArgumentException $e) {
    ecsRespond(400, ['error' => 'invalid_' . $e->getMessage()]);
} catch (ForbiddenException) {
    ecsRespond(403, ['error' => 'forbidden']);
} catch (NotFoundException) {
    ecsRespond(404, ['error' => 'not_found']);
} catch (Throwable $e) {
    error_log('oe-module-elegant-calendar-style: ' . $e::class . ' in views.php action ' . $action);
    ecsRespond(500, ['error' => 'server_error']);
}
