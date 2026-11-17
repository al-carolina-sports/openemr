<?php

/**
 * Edit a saved AI Scribe Note (same page as new.php, without the recorder).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../../../../globals.php');

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Modules\ElegantAiScribe\Templates;

$easFormDir = $easFormDir ?? 'elegant_ai_scribe';

$easFormId = (int)($_GET['id'] ?? 0);
$easEdit = QueryUtils::querySingleRow('SELECT * FROM `' . Templates::table($easFormDir) . '` WHERE `id` = ? AND `pid` = ?', [$easFormId, PatientSessionUtil::getPid()]);
if (!is_array($easEdit)) {
    echo xlt('Form not found.');
    return;
}
require __DIR__ . '/new.php';
