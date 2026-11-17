<?php

/**
 * "AI Scribe Note" encounter form: record, transcribe, draft, review, save.
 * Loaded by core's load_form.php / view_form.php through
 * LoadEncounterFormFilterEvent (see src/Bootstrap.php).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../../../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Modules\ElegantAiScribe\Bootstrap;
use OpenEMR\Modules\ElegantAiScribe\Settings;
use OpenEMR\Modules\ElegantAiScribe\Templates;

$easSession = SessionWrapperFactory::getInstance()->getActiveSession();
$easConfig = (new Settings())->all();
$easEdit = $easEdit ?? null; // set by view.php when editing a saved note
$easFormId = $easFormId ?? 0;
$easBase = Bootstrap::webPath();
$easFormDir = $easFormDir ?? 'elegant_ai_scribe'; // set by the wrapper of each form directory
$easAction = $easBase . '/public/save.php?dir=' . urlencode($easFormDir) . '&mode=' . ($easEdit ? 'update&id=' . (int)$easFormId : 'new');
$easVal = static fn(string $k): string => (string)($easEdit[$k] ?? '');
// New notes use the form's template; saved notes keep the template they were written with.
$easTemplate = $easEdit ? Templates::ofRow($easEdit) : (Templates::forFormdir($easFormDir) ?? 'hp');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo text(Templates::ALL[Templates::forFormdir($easFormDir) ?? 'hp']['form_name']); ?></title>
    <?php Header::setupHeader(); ?>
    <link rel="stylesheet" href="<?php echo attr($easBase); ?>/public/assets/scribe.css?v=0.4.0">
</head>
<body>
<div class="container-fluid mt-3 eas">
    <h2><?php echo text(Templates::ALL[Templates::forFormdir($easFormDir) ?? 'hp']['form_name']); ?><?php if ($easEdit && Templates::forFormdir($easFormDir) !== $easTemplate) { ?> <small class="text-muted">(<?php echo text(Templates::ALL[$easTemplate]['label']); ?>)</small><?php } ?></h2>
    <div class="card mb-3" id="eas-recorder"
         data-endpoint="<?php echo attr($easBase . '/public/api.php'); ?>"
         data-csrf="<?php echo attr(CsrfUtils::collectCsrfToken(session: $easSession)); ?>"
         data-max-minutes="<?php echo attr((string)(int)$easConfig['max_recording_minutes']); ?>"
         data-mock="<?php echo attr($easConfig['transcriber'] === 'mock' ? '1' : '0'); ?>">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center" style="gap:.5rem">
                <button type="button" class="btn btn-danger" id="eas-start"><?php echo xlt('Start recording'); ?></button>
                <button type="button" class="btn btn-secondary" id="eas-pause" disabled><?php echo xlt('Pause'); ?></button>
                <button type="button" class="btn btn-secondary" id="eas-stop" disabled><?php echo xlt('Stop'); ?></button>
                <span class="eas-timer" id="eas-timer">00:00</span>
                <span class="eas-dot" id="eas-dot" hidden></span>
                <?php if ($easConfig['transcriber'] === 'mock') { ?>
                    <button type="button" class="btn btn-outline-info ml-auto" id="eas-mock"><?php echo xlt('Use sample visit (mock, no audio)'); ?></button>
                <?php } ?>
            </div>
            <div class="small text-muted mt-2">
                <?php echo xlt('Transcription'); ?>: <strong><?php echo text(Settings::TRANSCRIBERS[$easConfig['transcriber']] ?? $easConfig['transcriber']); ?></strong>,
                <?php echo xlt('Note'); ?>: <strong><?php echo text(Settings::GENERATORS[$easConfig['generator']] ?? $easConfig['generator']); ?></strong>.
                <?php echo xlt('Get the patient\'s consent before recording. Nothing is saved to the chart until you click Save.'); ?>
            </div>
            <div class="eas-status mt-2" id="eas-status" role="status" aria-live="polite"></div>
        </div>
    </div>

    <form method="post" id="eas-form" action="<?php echo attr($easAction); ?>" onsubmit="return top.restoreSession()">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $easSession)); ?>">
        <input type="hidden" name="session_uuid" id="eas-session" value="">
        <div class="form-group">
            <label for="eas-transcript" class="font-weight-bold"><?php echo xlt('Transcript'); ?></label>
            <textarea class="form-control" id="eas-transcript" name="transcript" rows="6"><?php echo text($easVal('transcript')); ?></textarea>
            <button type="button" class="btn btn-primary btn-sm mt-2" id="eas-generate"<?php echo $easVal('transcript') === '' ? ' disabled' : ''; ?>><?php echo xlt('Generate draft note'); ?></button>
        </div>
        <input type="hidden" id="eas-template" name="template" value="<?php echo attr($easTemplate); ?>">
        <?php
        foreach (Templates::sections($easTemplate) as $easKey => $easHeading) { ?>
        <div class="form-group eas-section" data-templates="<?php echo attr($easTemplate); ?>">
            <label for="eas-<?php echo attr($easKey); ?>" class="font-weight-bold"><?php echo text($easHeading); ?></label>
            <textarea class="form-control eas-note" id="eas-<?php echo attr($easKey); ?>" name="<?php echo attr($easKey); ?>" rows="4"><?php echo text($easVal($easKey)); ?></textarea>
        </div>
        <?php } ?>
        <div class="alert alert-warning small" id="eas-review" <?php echo $easEdit ? 'hidden' : ''; ?>>
            <?php echo xlt('AI-generated draft. Review and edit every section; you are responsible for the note you save.'); ?>
        </div>
        <div class="btn-group mb-4">
            <button type="submit" class="btn btn-primary btn-save" id="eas-save"><?php echo xlt('Save'); ?></button>
            <button type="button" class="btn btn-secondary btn-cancel" onclick="top.restoreSession(); parent.closeTab(window.name, false);"><?php echo xlt('Cancel'); ?></button>
        </div>
    </form>
</div>
<script src="<?php echo attr($easBase); ?>/public/assets/scribe.js?v=0.4.0"></script>
</body>
</html>
