<?php

/**
 * AI Scribe JSON endpoint. CSRF token in the X-CSRF-Token header. Works only
 * for the patient and encounter open in the caller's session, for users with
 * encounters/notes write access. Nothing here writes to the chart; the note is
 * saved only by save.php when the clinician clicks Save.
 *
 *   POST ?action=start                       -> {session}
 *   POST ?action=upload&session=..  (multipart "audio", optional "seconds")
 *   POST ?action=transcribe&session=..       -> {status, transcript?}
 *   GET  ?action=status&session=..           -> {status, transcript?}
 *   POST ?action=generate&session=..  {transcript, template} -> {note, template}
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ElegantAiScribe\Context;
use OpenEMR\Modules\ElegantAiScribe\Provider\ProviderFactory;
use OpenEMR\Modules\ElegantAiScribe\SessionRepository;
use OpenEMR\Modules\ElegantAiScribe\Settings;
use OpenEMR\Modules\ElegantAiScribe\Templates;

function easRespond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($token) || !CsrfUtils::verifyCsrfToken($token, session: $session)) {
    easRespond(403, ['error' => 'forbidden']);
}
$ctx = Context::current();
if ($ctx === null) {
    easRespond(403, ['error' => 'no_access_or_no_open_encounter']);
}

$settingsRepo = new Settings();
$config = $settingsRepo->all();
$factory = new ProviderFactory($settingsRepo, $config);
$repo = new SessionRepository($ctx->userId, $ctx->pid, $ctx->encounter);
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$audit = static function (string $what, bool $ok = true) use ($ctx): void {
    EventAuditLogger::getInstance()->newEvent('other', $ctx->username, '', $ok ? 1 : 0, 'AI Scribe: ' . $what . ' (encounter ' . $ctx->encounter . ')', $ctx->pid);
};

if ($action !== 'status' && $method !== 'POST') {
    easRespond(405, ['error' => 'method_not_allowed']);
}

if ($action === 'start') {
    easRespond(200, ['session' => $repo->create(), 'transcriber' => $config['transcriber'], 'generator' => $config['generator']]);
}

$uuid = (string)($_GET['session'] ?? '');
$row = $repo->find($uuid);
if ($row === null) {
    easRespond(404, ['error' => 'session_not_found']);
}

try {
    switch ($action) {
        case 'upload':
            $file = $_FILES['audio'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                easRespond(400, ['error' => 'no_audio']);
            }
            $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $ext = ['audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'mp4', 'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav'][$mime] ?? null;
            if ($ext === null) {
                easRespond(415, ['error' => 'unsupported_audio']);
            }
            $mime = str_replace('video/', 'audio/', $mime);
            SessionRepository::deleteAudio($row['audio_path'] ?? null);
            $path = SessionRepository::audioDir() . '/' . $uuid . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $path)) {
                easRespond(500, ['error' => 'store_failed']);
            }
            chmod($path, 0660);
            $seconds = filter_var($_POST['seconds'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 86400]]);
            $repo->update($uuid, ['status' => 'uploaded', 'audio_path' => $path, 'audio_mime' => $mime, 'audio_seconds' => is_int($seconds) ? $seconds : null, 'error' => null]);
            $audit('audio uploaded (' . (int)filesize($path) . ' bytes)');
            easRespond(200, ['ok' => true]);

        case 'transcribe':
            $transcriber = $factory->transcriber();
            $audio = (string)($row['audio_path'] ?? '');
            if ($transcriber->id() !== 'mock' && ($audio === '' || !is_file($audio))) {
                easRespond(400, ['error' => 'no_audio']);
            }
            $res = $transcriber->start($audio, (string)($row['audio_mime'] ?? ''), $uuid);
            $repo->update($uuid, ['transcriber' => $transcriber->id(), 'job_name' => $res['job'] ?? null, 'status' => $res['status'] === 'done' ? 'transcribed' : 'transcribing', 'transcript' => $res['transcript'] ?? null]);
            $audit('transcription started with ' . $transcriber->id());
            if ($res['status'] === 'done') {
                finishTranscription($repo, $uuid, $row, $transcriber, $config);
            }
            easRespond(200, ['status' => $res['status'], 'transcript' => $res['transcript'] ?? null]);

        case 'status':
            if (($row['status'] ?? '') !== 'transcribing') {
                easRespond(200, ['status' => in_array($row['status'], ['transcribed', 'drafted', 'saved'], true) ? 'done' : (string)$row['status'], 'transcript' => $row['transcript'], 'error' => $row['error']]);
            }
            $transcriber = $factory->transcriber((string)$row['transcriber']);
            $res = $transcriber->poll((string)$row['job_name']);
            if ($res['status'] === 'done') {
                $repo->update($uuid, ['status' => 'transcribed', 'transcript' => $res['transcript'] ?? '']);
                finishTranscription($repo, $uuid, $row, $transcriber, $config);
                $audit('transcription completed');
            } elseif ($res['status'] === 'failed') {
                $repo->update($uuid, ['status' => 'failed', 'error' => $res['error'] ?? 'failed']);
                $audit('transcription failed', false);
            }
            easRespond(200, ['status' => $res['status'], 'transcript' => $res['transcript'] ?? null, 'error' => $res['error'] ?? null]);

        case 'generate':
            $input = json_decode((string)file_get_contents('php://input'), true);
            $transcript = is_array($input) ? trim((string)($input['transcript'] ?? '')) : '';
            if ($transcript === '' || strlen($transcript) > 200000) {
                easRespond(400, ['error' => 'transcript_required']);
            }
            $template = Templates::id(is_array($input) ? (string)($input['template'] ?? '') : '', 'hp');
            $prompt = (string)($config[Templates::ALL[$template]['prompt_key']] ?? '');
            $generator = $factory->generator();
            $t0 = microtime(true);
            $note = $generator->generate($transcript, $prompt, Templates::sections($template));
            $ms = (int)round((microtime(true) - $t0) * 1000);
            $usage = $generator->lastUsage();
            $repo->update($uuid, ['generator' => $generator->id(), 'transcript' => $transcript, 'note_json' => json_encode(['template' => $template] + $note), 'status' => 'drafted']);
            $audit('draft ' . $template . ' note generated with ' . $generator->id() . ($usage ? ' (' . $usage['input_tokens'] . ' in / ' . $usage['output_tokens'] . ' out tokens, ' . $ms . ' ms)' : ''));
            easRespond(200, ['note' => $note, 'template' => $template, 'usage' => $usage, 'ms' => $ms, 'providers' => ($row['transcriber'] ?: $config['transcriber']) . '/' . $generator->id()]);

        default:
            easRespond(400, ['error' => 'unknown_action']);
    }
} catch (Throwable $e) {
    error_log('oe-module-elegant-ai-scribe: ' . $e::class . ' in ' . $action . ': ' . $e->getMessage());
    $repo->update($uuid, ['error' => substr($e->getMessage(), 0, 250)]);
    $audit($action . ' failed', false);
    // Messages are ours or AWS error codes (no patient data); show them so admins can fix setup.
    easRespond(502, ['error' => 'provider_error', 'message' => substr($e->getMessage(), 0, 250)]);
}

/** Retention: drop local audio and provider-side copies once the transcript exists. */
function finishTranscription(SessionRepository $repo, string $uuid, array $row, $transcriber, array $config): void
{
    if (!empty($config['delete_audio_after_transcription'])) {
        SessionRepository::deleteAudio($row['audio_path'] ?? null);
        if (!empty($row['job_name'])) {
            $transcriber->cleanup((string)$row['job_name']);
        }
        $repo->update($uuid, ['audio_path' => null]);
    }
}
