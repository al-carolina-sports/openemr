<?php

/**
 * Encounter summary / report rendering of an AI Scribe Note.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../../../../globals.php');

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ElegantAiScribe\Templates;

if (!function_exists('elegant_ai_scribe_report')) {
function elegant_ai_scribe_report($pid, $encounter, $cols, $id, string $formdir = 'elegant_ai_scribe'): void
{
    $row = QueryUtils::querySingleRow('SELECT * FROM `' . Templates::table($formdir) . '` WHERE `id` = ? AND `pid` = ?', [(int)$id, (int)$pid]);
    if (!is_array($row)) {
        return;
    }
    $tpl = Templates::ofRow($row);
    printf('<div class="text-muted small">%s</div>', text(Templates::ALL[$tpl]['label']));
    foreach (Templates::sections($tpl) as $key => $label) {
        if (trim((string)$row[$key]) !== '') {
            printf('<div><strong>%s:</strong><br>%s</div>', xlt($label), nl2br(text((string)$row[$key])));
        }
    }
    if (!empty($row['ai_providers'])) {
        printf('<div class="text-muted small">%s %s</div>', xlt('Drafted with AI Scribe'), text('(' . $row['ai_providers'] . ')'));
    }
}
}
