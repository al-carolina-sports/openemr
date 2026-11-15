<?php

/**
 * AI Scribe settings (Admin > AI Scribe Settings). admin/super only.
 * AWS keys: environment variables are preferred; keys typed here are stored
 * encrypted with OpenEMR's CryptoGen and never shown again.
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
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Modules\ElegantAiScribe\Aws\Credentials;
use OpenEMR\Modules\ElegantAiScribe\Settings;
use OpenEMR\Modules\ElegantAiScribe\Templates;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo xlt('Not authorized');
    exit;
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$repo = new Settings();
$saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);
    $p = static fn(string $k): string => trim((string)($_POST[$k] ?? ''));
    $repo->save([
        'transcriber' => array_key_exists($p('transcriber'), Settings::TRANSCRIBERS) ? $p('transcriber') : 'mock',
        'generator' => array_key_exists($p('generator'), Settings::GENERATORS) ? $p('generator') : 'mock',
        'aws_region' => preg_match('/^[a-z]{2}(-gov)?-[a-z]+-\d$/', $p('aws_region')) ? $p('aws_region') : 'us-east-1',
        's3_bucket' => preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $p('s3_bucket')) ? $p('s3_bucket') : '',
        's3_prefix' => preg_replace('#[^A-Za-z0-9/_.-]#', '', $p('s3_prefix')) ?: 'elegant-ai-scribe/',
        'transcribe_specialty' => in_array($p('transcribe_specialty'), Settings::SPECIALTIES, true) ? $p('transcribe_specialty') : 'PRIMARYCARE',
        'transcribe_type' => $p('transcribe_type') === 'DICTATION' ? 'DICTATION' : 'CONVERSATION',
        'bedrock_model_id' => preg_match('/^[A-Za-z0-9._:\/-]{3,200}$/', $p('bedrock_model_id')) ? $p('bedrock_model_id') : Settings::DEFAULTS['bedrock_model_id'],
        'max_tokens' => max(256, min(8000, (int)$p('max_tokens'))),
        'temperature' => max(0.0, min(1.0, round((float)$p('temperature'), 2))),
        'prompt_new_patient' => $p('prompt_new_patient') !== '' ? mb_substr((string)$_POST['prompt_new_patient'], 0, 20000) : Settings::DEFAULT_PROMPT_NEW_PATIENT,
        'prompt_soap' => $p('prompt_soap') !== '' ? mb_substr((string)$_POST['prompt_soap'], 0, 20000) : Settings::DEFAULT_PROMPT_SOAP,
        'delete_audio_after_transcription' => !empty($_POST['delete_audio_after_transcription']),
        'max_recording_minutes' => max(1, min(120, (int)$p('max_recording_minutes'))),
        'deepgram_model' => preg_match('/^[a-z0-9.-]{2,40}$/', $p('deepgram_model')) ? $p('deepgram_model') : 'nova-3-medical',
    ]);
    $repo->purgeLegacy();
    foreach (Settings::SECRET_KEYS as $k) {
        if (!empty($_POST['clear_' . $k])) {
            $repo->saveSecret($k, '');
        } elseif ($p($k) !== '') {
            $repo->saveSecret($k, $p($k));
        }
    }
    EventAuditLogger::getInstance()->newEvent('security-administration-update', (string)$session->get('authUser'), '', 1, 'AI Scribe settings updated');
    $saved = true;
}
$c = $repo->all();
$sel = static fn(string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('AI Scribe Settings'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body>
<div class="container mt-3 mb-5">
    <h2><?php echo xlt("Annie's Elegant AI Scribe"); ?> <small class="text-muted">v0.4.0</small></h2>
    <?php if ($saved) { ?><div class="alert alert-success"><?php echo xlt('Saved.'); ?></div><?php } ?>
    <form method="post">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $session)); ?>">
        <fieldset class="mb-3"><legend><?php echo xlt('Providers'); ?></legend>
            <div class="form-row">
                <div class="form-group col-md-6"><label><?php echo xlt('Transcription'); ?></label>
                    <select class="form-control" name="transcriber"><?php foreach (Settings::TRANSCRIBERS as $k => $v) { ?><option value="<?php echo attr($k); ?>"<?php echo $sel($k, (string)$c['transcriber']); ?>><?php echo text($v); ?></option><?php } ?></select></div>
                <div class="form-group col-md-6"><label><?php echo xlt('Note generation'); ?></label>
                    <select class="form-control" name="generator"><?php foreach (Settings::GENERATORS as $k => $v) { ?><option value="<?php echo attr($k); ?>"<?php echo $sel($k, (string)$c['generator']); ?>><?php echo text($v); ?></option><?php } ?></select></div>
            </div>
        </fieldset>
        <fieldset class="mb-3"><legend>Deepgram</legend>
            <div class="form-row">
                <div class="form-group col-md-4"><label><?php echo xlt('Model'); ?></label><input class="form-control" name="deepgram_model" value="<?php echo attr((string)$c['deepgram_model']); ?>">
                    <div class="small text-muted"><?php echo xlt('nova-3-medical (falls back to nova-3 if rejected)'); ?></div></div>
                <div class="form-group col-md-8"><label><?php echo xlt('API key (stored encrypted)'); ?></label><input type="password" class="form-control" name="deepgram_api_key" autocomplete="new-password" placeholder="<?php echo attr($c['deepgram_api_key'] !== '' ? xl('set - leave blank to keep') : ''); ?>">
                    <label class="small"><input type="checkbox" name="clear_deepgram_api_key" value="1"> <?php echo xlt('clear'); ?></label>
                    <span class="small ml-2"><?php echo xlt('Key in use'); ?>: <strong><?php echo text($repo->deepgramKeySource()); ?></strong> (<?php echo xlt('DEEPGRAM_API_KEY env var takes precedence'); ?>)</span></div>
            </div>
        </fieldset>
        <fieldset class="mb-3"><legend>AWS</legend>
            <div class="form-row">
                <div class="form-group col-md-3"><label><?php echo xlt('Region'); ?></label><input class="form-control" name="aws_region" value="<?php echo attr((string)$c['aws_region']); ?>"></div>
                <div class="form-group col-md-5"><label><?php echo xlt('S3 bucket'); ?></label><input class="form-control" name="s3_bucket" value="<?php echo attr((string)$c['s3_bucket']); ?>"></div>
                <div class="form-group col-md-4"><label><?php echo xlt('S3 key prefix'); ?></label><input class="form-control" name="s3_prefix" value="<?php echo attr((string)$c['s3_prefix']); ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-3"><label><?php echo xlt('Transcribe specialty'); ?></label>
                    <select class="form-control" name="transcribe_specialty"><?php foreach (Settings::SPECIALTIES as $s) { ?><option<?php echo $sel($s, (string)$c['transcribe_specialty']); ?>><?php echo text($s); ?></option><?php } ?></select></div>
                <div class="form-group col-md-3"><label><?php echo xlt('Audio type'); ?></label>
                    <select class="form-control" name="transcribe_type"><option value="CONVERSATION"<?php echo $sel('CONVERSATION', (string)$c['transcribe_type']); ?>><?php echo xlt('Conversation'); ?></option><option value="DICTATION"<?php echo $sel('DICTATION', (string)$c['transcribe_type']); ?>><?php echo xlt('Dictation'); ?></option></select></div>
                <div class="form-group col-md-6"><label><?php echo xlt('Bedrock model id / inference profile'); ?></label><input class="form-control" name="bedrock_model_id" value="<?php echo attr((string)$c['bedrock_model_id']); ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-3"><label><?php echo xlt('Temperature'); ?></label><input type="number" step="0.05" min="0" max="1" class="form-control" name="temperature" value="<?php echo attr((string)$c['temperature']); ?>"></div>
                <div class="form-group col-md-3"><label><?php echo xlt('Max tokens'); ?></label><input type="number" class="form-control" name="max_tokens" value="<?php echo attr((string)$c['max_tokens']); ?>"></div>
            </div>
            <p class="small"><?php echo xlt('Credentials in use'); ?>: <strong><?php echo text(Credentials::describe($repo)); ?></strong>.
                <?php echo xlt('AWS keys are read only from the OpenEMR container environment (AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY from a docker compose env_file); they are never stored in the database.'); ?></p>
        </fieldset>
        <fieldset class="mb-3"><legend><?php echo xlt('Note templates / prompts'); ?></legend>
            <?php foreach (Templates::ALL as $k => $t) { ?>
            <label class="font-weight-bold"><?php echo text($t['label']); ?> <?php echo xlt('prompt'); ?> <small class="text-muted">&mdash; <?php echo text($t['form_name']); ?></small> <small class="text-muted">(<?php echo text(implode(', ', $t['sections'])); ?>)</small></label>
            <textarea class="form-control mb-1" name="<?php echo attr($t['prompt_key']); ?>" rows="6"><?php echo text((string)$c[$t['prompt_key']]); ?></textarea>
            <?php } ?>
            <div class="small text-muted"><?php echo xlt('Clear a box and save to restore its default prompt.'); ?></div>
        </fieldset>
        <fieldset class="mb-3"><legend><?php echo xlt('Recording and retention'); ?></legend>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="del" name="delete_audio_after_transcription" value="1"<?php echo $c['delete_audio_after_transcription'] ? ' checked' : ''; ?>>
                <label class="form-check-label" for="del"><?php echo xlt('Delete audio (local, and S3 if used) as soon as the transcript is ready'); ?></label></div>
            <div class="form-group col-md-3 px-0 mt-2"><label><?php echo xlt('Max recording minutes'); ?></label><input type="number" class="form-control" name="max_recording_minutes" value="<?php echo attr((string)$c['max_recording_minutes']); ?>"></div>
        </fieldset>
        <button class="btn btn-primary" type="submit"><?php echo xlt('Save'); ?></button>
    </form>
</div>
</body>
</html>
