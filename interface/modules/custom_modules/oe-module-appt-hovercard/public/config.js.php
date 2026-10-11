<?php

/**
 * Emits the page-level configuration the hover card script needs. Served as
 * JavaScript so the CSRF token and display settings reach hovercard.js without
 * touching any core template. Nothing patient-related is in here.
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
use OpenEMR\Modules\ApptHoverCard\Bootstrap;
use OpenEMR\Modules\ApptHoverCard\SettingsRepository;

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$username = $session->get('authUser');
if (!is_string($username) || $username === '') {
    echo "window.oeApptHoverCardConfig = null;\n";
    exit;
}

$settings = (new SettingsRepository())->all();
$config = [
    'endpoint' => OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_PATH . '/public/ajax.php',
    'csrf' => CsrfUtils::collectCsrfToken(session: $session),
    'views' => $settings['views'],
    'hoverDelayMs' => $settings['hover_delay_ms'],
    'hideDelayMs' => $settings['hide_delay_ms'],
    'clickMode' => $settings['click_mode'],
    'cardWidth' => $settings['card_width'],
];

echo 'window.oeApptHoverCardConfig = ' . json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP) . ";\n";
