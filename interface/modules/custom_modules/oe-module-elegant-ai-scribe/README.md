# Annie's Elegant AI Scribe (v0.4.0)

OpenEMR 8.5 custom module (no core edits). Adds an **AI Scribe Note** encounter
form (Clinical category): record the visit in the browser, transcribe it, draft
a SOAP note, let the clinician edit, and save it to the encounter only when the
clinician clicks **Save**.

## How it plugs in
- `table.sql` creates `oe_module_eas_settings`, `oe_module_eas_sessions`,
  `form_elegant_ai_scribe` and a `registry` row (`directory = elegant_ai_scribe`),
  so the form shows in the encounter's form menu like core's Speech Dictation.
- `LoadEncounterFormFilterEvent` points core's form loader at
  `forms/elegant_ai_scribe/{new,view,report}.php` inside this module.
- Enable/disable in Manage Modules toggles the form; unregister drops the
  settings/session tables. Saved notes are never dropped automatically.

## Flow
`new.php` (recorder: MediaRecorder start/pause/stop, timer) -> `public/api.php`
(`start`, `upload`, `transcribe`, `status`, `generate`; CSRF header; only the
patient/encounter open in the session; encounters/notes write ACL) ->
`public/save.php` (normal form POST, writes `form_elegant_ai_scribe` + `forms`,
audit log). Every step is written to the audit log (no transcript text).

## Providers
- Transcriber: `mock` (sample visit) or `aws` (Transcribe Medical batch via S3).
- Note generator: `mock` (template) or `aws` (Bedrock Converse API, Claude).
- AWS calls use a small SigV4 client over Guzzle (already in OpenEMR's vendor);
  the AWS SDK for PHP is not bundled with OpenEMR.

## Settings
Admin > AI Scribe Settings (admin/super): providers, region, S3 bucket/prefix,
Transcribe specialty/type, Bedrock model id, prompt, retention, max minutes.
Credentials: `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_SESSION_TOKEN`
in the web server environment (preferred), or typed on the settings page and
stored encrypted with CryptoGen.

## v0.4.0: two encounter forms

Clinical menu has **AI Scribe Note - History & Physical** (`elegant_ai_scribe`, CHIEF COMPLAINT … PLAN) and
**AI Scribe Note - SOAP** (`elegant_ai_scribe_soap`). Each opens straight on its template (no dropdown); both share the code in
`forms/elegant_ai_scribe/`, each has its own table (`form_<formdir>`). Saved notes keep their stored template
(NULL = SOAP from v0.1/v0.2, `new_patient` = H&P from v0.3). Reopened notes now load the recorder script (re-record / regenerate works).

## Deepgram (v0.3.0)
Transcription provider `deepgram`: prerecorded `POST /v1/listen?model=nova-3-medical&smart_format&diarize&punctuate&utterances`, audio bytes sent directly (no S3), diarized utterances -> `Speaker N:` lines. Falls back to `nova-3` if the model is rejected. Key: `DEEPGRAM_API_KEY` env, else CryptoGen-encrypted setting. Transcription and note generation are configured independently.

## v0.3.0: Bedrock Llama 4 + note templates
- Note generation: Bedrock Runtime **Converse** (model-agnostic), default `us.meta.llama4-maverick-17b-instruct-v1:0`, temperature 0.1, max tokens 1500, region us-east-1 (all editable in settings).
- AWS credentials come **only** from the container environment (`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` via a docker compose `env_file`). They are never read from or written to the database; legacy DB rows are purged when settings are saved.
- Templates (picker on the form; default in settings; stored in `form_elegant_ai_scribe.template`):
  - **History & Physical**: CHIEF COMPLAINT, HPI, REVIEW OF SYSTEMS, PHYSICAL EXAM, IMAGING, ASSESSMENT, PLAN
  - **SOAP Note (follow-up)**: SUBJECTIVE, OBJECTIVE, ASSESSMENT, PLAN
  Each has its own editable prompt; output is parsed by heading.
