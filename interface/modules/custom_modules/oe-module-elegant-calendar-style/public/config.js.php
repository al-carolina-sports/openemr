<?php

/**
 * Client configuration for the logged-in user: Custom Views endpoint and
 * CSRF token, plus the Hide Saturday / Hide Sunday state (site default,
 * the user's own choice, effective value).
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
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ElegantCalendarStyle\Bootstrap;
use OpenEMR\Modules\ElegantCalendarStyle\WeekendSettings;

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$username = $session->get('authUser');
if (!is_string($username) || $username === '') {
    echo "window.oeEcsConfig = null;\n";
    exit;
}

$config = [
    'endpoint' => OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_PATH . '/public/views.php',
    'csrf' => CsrfUtils::collectCsrfToken(session: $session),
    'user' => $username,
    'weekends' => (new WeekendSettings())->forUser((int)$session->get('authUserID')),
    'labels' => [
        'title' => xl('Custom Views'),
        'choose' => xl('Choose a view'),
        'add' => xl('Add view'),
        'edit' => xl('Edit view'),
        'delete' => xl('Delete view'),
        'name' => xl('View name'),
        'shared' => xl('Shared with everyone'),
        'sharedAdminOnly' => xl('Only administrators can share views'),
        'members' => xl('Calendar users'),
        'save' => xl('Save'),
        'cancel' => xl('Cancel'),
        'confirmDelete' => xl('Delete this view?'),
        'needName' => xl('Enter a view name.'),
        'needMembers' => xl('Choose at least one calendar user.'),
        'error' => xl('Could not save the view.'),
        'sharedTag' => xl('shared'),
        'layout' => xl('Week layout'),
        'stack' => xl('Stack'),
        'stretch' => xl('Stretch'),
        'hide' => xl('Hide'),
        'sat' => xl('Sat'),
        'sun' => xl('Sun'),
        'hideSat' => xl('Hide Saturday'),
        'hideSun' => xl('Hide Sunday'),
        'satHiddenAppts' => xl('Saturday appointment(s) hidden'),
        'sunHiddenAppts' => xl('Sunday appointment(s) hidden'),
        'siteDefault' => xl('site default'),
        'yourChoice' => xl('your choice'),
        'useSiteDefault' => xl('Weekends: use site default'),
        'prefError' => xl('Could not save your weekend setting.'),
    ],
];

echo 'window.oeEcsConfig = ' . json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";\n";
