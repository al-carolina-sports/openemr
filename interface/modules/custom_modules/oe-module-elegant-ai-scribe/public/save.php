<?php

/**
 * Saves the clinician-reviewed note as an "AI Scribe Note" encounter form.
 * This is the only place the module writes to the chart, and only on the
 * clinician's explicit Save (standard form POST with csrf_token_form).
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
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ElegantAiScribe\Context;
use OpenEMR\Modules\ElegantAiScribe\SessionRepository;
use OpenEMR\Modules\ElegantAiScribe\Templates;

CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);
$ctx = Context::current();
if ($ctx === null) {
    http_response_code(403);
    echo xlt('Not authorized, or no encounter is open.');
    exit;
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$authorized = PatientSessionUtil::getUserAuthorized();

$field = static fn(string $k): string => mb_substr(trim((string)($_POST[$k] ?? '')), 0, 100000);
$formDir = (string)($_GET['dir'] ?? 'elegant_ai_scribe');
$formTemplate = Templates::forFormdir($formDir);
if ($formTemplate === null) {
    http_response_code(400);
    echo xlt('Unknown AI Scribe form.');
    exit;
}
$table = Templates::table($formDir);
$mode = ($_GET['mode'] ?? 'new') === 'update' ? 'update' : 'new';
// New notes always use the form's template; a reopened note keeps the template it was saved with.
$template = $mode === 'new' ? $formTemplate : Templates::id((string)($_POST['template'] ?? ''), $formTemplate);
$values = [];
foreach (Templates::COLUMNS as $k) {
    // Only the chosen template's sections are kept; others are cleared.
    $values[$k] = array_key_exists($k, Templates::sections($template)) ? $field($k) : '';
}
if (implode('', $values) === '') {
    http_response_code(400);
    echo xlt('The note is empty; nothing was saved.');
    exit;
}

$formId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$uuid = (string)($_POST['session_uuid'] ?? '');
$repo = new SessionRepository($ctx->userId, $ctx->pid, $ctx->encounter);
$scribe = $uuid !== '' ? $repo->find($uuid) : null;
$providers = $scribe ? trim(($scribe['transcriber'] ?? '') . '/' . ($scribe['generator'] ?? ''), '/') : '';

if ($mode === 'update') {
    // The form must belong to this patient and encounter.
    $owner = QueryUtils::querySingleRow(
        "SELECT f.`form_id` FROM `forms` f WHERE f.`formdir` = ? AND f.`form_id` = ? AND f.`pid` = ? AND f.`encounter` = ? AND f.`deleted` = 0",
        [$formDir, $formId, $ctx->pid, $ctx->encounter]
    );
    if (!is_array($owner)) {
        http_response_code(404);
        echo xlt('Form not found for this encounter.');
        exit;
    }
    $cols = Templates::COLUMNS;
    QueryUtils::sqlStatementThrowException(
        'UPDATE `' . $table . '` SET `date` = NOW(), `user` = ?, `authorized` = ?, `template` = ?, ' . implode(', ', array_map(static fn($c) => '`' . $c . '` = ?', $cols)) . ', `transcript` = ? WHERE `id` = ? AND `pid` = ?',
        array_merge([$ctx->username, $authorized, $template], array_values($values), [$field('transcript'), $formId, $ctx->pid])
    );
    $what = 'note updated (form ' . $formId . ')';
} else {
    $cols = Templates::COLUMNS;
    $formId = (int)QueryUtils::sqlInsert(
        'INSERT INTO `' . $table . '` (`date`, `pid`, `user`, `groupname`, `authorized`, `activity`, `template`, ' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols)) . ', `transcript`, `ai_providers`, `session_uuid`) '
        . 'VALUES (NOW(), ?, ?, ?, ?, 1, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?)',
        array_merge([$ctx->pid, $ctx->username, (string)$session->get('authProvider'), $authorized, $template], array_values($values), [$field('transcript'), $providers, $scribe ? $uuid : null])
    );
    addForm($ctx->encounter, Templates::ALL[$formTemplate]['form_name'], $formId, $formDir, $ctx->pid, $authorized);
    if ($scribe) {
        $repo->update($uuid, ['status' => 'saved', 'form_id' => $formId]);
    }
    $what = 'clinician saved ' . $template . ' note to chart (form ' . $formId . ($providers !== '' ? ', draft by ' . $providers : '') . ')';
}
EventAuditLogger::getInstance()->newEvent('patient-record-update', $ctx->username, (string)$session->get('authProvider'), 1, 'AI Scribe: ' . $what . ' (encounter ' . $ctx->encounter . ')', $ctx->pid);

formHeader('Redirecting....');
formJump();
formFooter();
