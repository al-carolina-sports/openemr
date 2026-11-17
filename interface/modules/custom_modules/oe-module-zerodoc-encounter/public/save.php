<?php

/**
 * Saves a ZeroDoc Encounter Note to the chart.
 *
 * This is the only place the scaffold writes clinical data, and only on an
 * explicit form POST with csrf_token_form. Pattern from
 * oe-module-elegant-ai-scribe/public/save.php.
 *
 * Note core already 403s any script under this module's directory when the
 * module is not enabled (ModulesApplication::checkModuleScriptPathForEnabledModule),
 * so there is no enabled-check here.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../globals.php');
require_once(\OpenEMR\Core\OEGlobalsBag::getInstance()->getSrcDir() . '/forms.inc.php');
require_once(\OpenEMR\Core\OEGlobalsBag::getInstance()->getSrcDir() . '/api.inc.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Modules\ZeroDoc\AccessPolicy;
use OpenEMR\Modules\ZeroDoc\Context;
use OpenEMR\Modules\ZeroDoc\Forms;
use OpenEMR\Modules\ZeroDoc\RoleResolver;

CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);

$ctx = Context::current();
if ($ctx === null) {
    http_response_code(403);
    echo xlt('Not authorized, or no encounter is open.');
    exit;
}

$policy = new AccessPolicy((new RoleResolver())->titlesFor($ctx->username));
if (!$policy->canDocument()) {
    http_response_code(403);
    echo xlt('Not authorized.');
    exit;
}

$formDir = (string)($_GET['dir'] ?? 'zdoc_encounter');
if (!Forms::isOurs($formDir)) {
    http_response_code(400);
    echo xlt('Unknown ZeroDoc form.');
    exit;
}
$meta = Forms::meta($formDir);
$table = Forms::table($formDir);
$mode = ($_GET['mode'] ?? 'new') === 'update' ? 'update' : 'new';
$formId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

// SCAFFOLD FIELDS: one column. Replace with ZeroDoc's section columns.
$body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 100000);
if ($body === '') {
    http_response_code(400);
    echo xlt('The note is empty; nothing was saved.');
    exit;
}

if ($mode === 'update') {
    // The form must belong to this patient AND this encounter.
    $owner = QueryUtils::querySingleRow(
        'SELECT `form_id` FROM `forms` WHERE `formdir` = ? AND `form_id` = ? AND `pid` = ? AND `encounter` = ? AND `deleted` = 0',
        [$formDir, $formId, $ctx->pid, $ctx->encounter]
    );
    if (!is_array($owner)) {
        http_response_code(404);
        echo xlt('Form not found for this encounter.');
        exit;
    }
    QueryUtils::sqlStatementThrowException(
        'UPDATE `' . $table . '` SET `date` = NOW(), `user` = ?, `authorized` = ?, `body` = ? WHERE `id` = ? AND `pid` = ?',
        [$ctx->username, $ctx->authorized, $body, $formId, $ctx->pid]
    );
    $what = 'note updated (form ' . $formId . ')';
} else {
    $formId = (int)QueryUtils::sqlInsert(
        'INSERT INTO `' . $table . '` (`date`, `pid`, `user`, `groupname`, `authorized`, `activity`, `section_key`, `body`) '
        . 'VALUES (NOW(), ?, ?, ?, ?, 1, ?, ?)',
        [$ctx->pid, $ctx->username, $ctx->provider, $ctx->authorized, $meta['section_key'], $body]
    );
    // Registers the instance in core's `forms` table so it appears in the
    // encounter, the patient report and the EHI export.
    addForm($ctx->encounter, $meta['form_name'], $formId, $formDir, $ctx->pid, $ctx->authorized);
    $what = 'note saved to chart (form ' . $formId . ')';
}

EventAuditLogger::getInstance()->newEvent(
    'patient-record-update',
    $ctx->username,
    $ctx->provider,
    1,
    'ZeroDoc: ' . $what . ' (encounter ' . $ctx->encounter . ')',
    $ctx->pid
);

formHeader('Redirecting....');
formJump();
formFooter();
