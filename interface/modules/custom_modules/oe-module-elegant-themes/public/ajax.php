<?php

/**
 * Annie's Elegant Themes AJAX endpoint: patient contact card, recent patients, footer booking context,
 * pinned patient menu (v1.5.3).
 * POST only, CSRF (default token of the main tabs page), ACL patients/demo read.
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
use OpenEMR\Menu\PatientMenuRole;
use OpenEMR\Modules\ElegantThemes\Appointments;
use OpenEMR\Modules\ElegantThemes\PatientExtras;
use OpenEMR\Modules\ElegantThemes\ThemeRepository;
use OpenEMR\Modules\ElegantThemes\Themes;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = static function (int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$userId = (int)$session->get('authUserID');
if ($userId <= 0) {
    $out(401, ['error' => 'not_authenticated']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $out(405, ['error' => 'post_only']);
}
if (!CsrfUtils::verifyCsrfToken((string)($_POST['csrf_token_form'] ?? ''), $session)) {
    $out(403, ['error' => 'csrf']);
}
$action = (string)($_POST['action'] ?? '');
// v1.5.0: light/dark toggle for paired themes (shadcn <-> shadcn-dark); saves only this user's own choice
if ($action === 'mode') {
    try {
        $repo = new ThemeRepository();
        $cur = $repo->resolve($userId);
        $dark = ($_POST['dark'] ?? '') === '1';
        foreach (Themes::MODE_PAIRS as $light => $darkKey) {
            if ($cur === $light || $cur === $darkKey) {
                $repo->save($userId, $dark ? $darkKey : $light);
                $out(200, ['ok' => true, 'theme' => $dark ? $darkKey : $light]);
            }
        }
        $out(200, ['ok' => false, 'error' => 'no_pair']);
    } catch (\Throwable $e) {
        error_log('oe-module-elegant-themes ajax: ' . $e::class);
        $out(500, ['error' => 'server']);
    }
}
if ($action === 'apptctx') {
    if (!AclMain::aclCheckCore('patients', 'appt', '', ['write', 'wsome'])) {
        $out(200, ['ok' => true, 'can' => false]);
    }
    try {
        $users = array_map('strval', array_slice((array)($_POST['cal_users'] ?? []), 0, 50));
        $out(200, ['ok' => true, 'can' => true] + (new Appointments())->context($userId, $users, (string)($_POST['now'] ?? '')));
    } catch (\Throwable $e) {
        error_log('oe-module-elegant-themes ajax: ' . $e::class);
        $out(500, ['error' => 'server']);
    }
}
if (!AclMain::aclCheckCore('patients', 'demo')) {
    $out(403, ['error' => 'acl']);
}

// v1.5.3 pinned patient menu: the stock patient menu, rendered by core for the session's
// current patient, for patient pages that do not draw it themselves (Documents, Auths, Cash-Pay).
if ($action === 'pmenu') {
    $sessionPid = (int)($session->get('pid') ?? 0);
    if ($sessionPid <= 0 || (int)($_POST['pid'] ?? 0) !== $sessionPid) {
        $out(200, ['ok' => false, 'error' => 'pid']);
    }
    $level = ob_get_level();
    try {
        ob_start();
        (new PatientMenuRole())->displayHorizNavBarMenu();
        $html = (string)ob_get_clean();
        $out(200, ['ok' => true, 'pid' => $sessionPid, 'html' => $html]);
    } catch (\Throwable $e) {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        error_log('oe-module-elegant-themes ajax: ' . $e::class);
        $out(500, ['error' => 'server']);
    }
}

$extras = new PatientExtras();
$pid = (int)($_POST['pid'] ?? 0);

try {
    switch ($action) {
        case 'contact':
            $c = $pid > 0 ? $extras->contact($pid) : null;
            $c === null ? $out(404, ['error' => 'not_found']) : $out(200, ['ok' => true, 'contact' => $c]);
            // no break (exits)
        case 'touch':
            $out(200, ['ok' => $pid > 0 && $extras->touch($userId, $pid)]);
            // no break (exits)
        case 'recent':
            $out(200, ['ok' => true, 'recent' => $extras->recent($userId)]);
            // no break (exits)
        default:
            $out(400, ['error' => 'bad_action']);
    }
} catch (\Throwable $e) {
    error_log('oe-module-elegant-themes ajax: ' . $e::class);
    $out(500, ['error' => 'server']);
}
