<?php

/**
 * Reopen a saved ZeroDoc Encounter Note.
 *
 * Loaded by core's interface/patient_file/encounter/view_form.php through
 * LoadEncounterFormFilterEvent. Loads the row -- scoped to the patient open in
 * the session, never to a pid from the URL -- and hands off to new.php, which
 * renders the same page in edit mode.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../../../../globals.php');

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Modules\ZeroDoc\Forms;

$zdocFormDir = $zdocFormDir ?? 'zdoc_encounter';

$zdocFormId = (int)($_GET['id'] ?? 0);
$zdocEdit = QueryUtils::querySingleRow(
    'SELECT * FROM `' . Forms::table($zdocFormDir) . '` WHERE `id` = ? AND `pid` = ?',
    [$zdocFormId, PatientSessionUtil::getPid()]
);
if (!is_array($zdocEdit)) {
    echo xlt('Form not found.');
    return;
}
require __DIR__ . '/new.php';
