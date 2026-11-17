<?php

/**
 * Encounter summary / patient report rendering of a ZeroDoc Encounter Note.
 *
 * Core's OpenEMR\Common\Forms\FormReportRenderer includes this file and then
 * calls a GLOBAL function named "<formdir>_report" -- the name is derived from
 * registry.directory, so it is fixed as zdoc_encounter_report(). If the
 * function is missing, core only logs an error and the note silently renders
 * as nothing in reports and the encounter summary.
 *
 * The function_exists() guard lets additional ZeroDoc form directories require
 * this file and delegate to the shared implementation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../../../../globals.php');

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ZeroDoc\Forms;

if (!function_exists('zdoc_render_report')) {
    /**
     * Shared renderer. $cols and $id come from core; $id is the
     * form_<formdir>.id of this note instance.
     */
    function zdoc_render_report($pid, $encounter, $cols, $id, string $formdir = 'zdoc_encounter'): void
    {
        $row = QueryUtils::querySingleRow(
            'SELECT * FROM `' . Forms::table($formdir) . '` WHERE `id` = ? AND `pid` = ?',
            [(int)$id, (int)$pid]
        );
        if (!is_array($row)) {
            return;
        }
        // SCAFFOLD: one field. ZeroDoc's real sections render here.
        if (trim((string)($row['body'] ?? '')) !== '') {
            printf(
                '<div><strong>%s:</strong><br>%s</div>',
                xlt('Note'),
                nl2br(text((string)$row['body']))
            );
        }
    }
}

if (!function_exists('zdoc_encounter_report')) {
    function zdoc_encounter_report($pid, $encounter, $cols, $id): void
    {
        zdoc_render_report($pid, $encounter, $cols, $id, 'zdoc_encounter');
    }
}
