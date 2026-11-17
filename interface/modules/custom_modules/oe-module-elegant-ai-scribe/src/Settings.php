<?php

/**
 * Module settings with defaults. AWS credentials come only from the environment; the Deepgram key may be stored as CryptoGen ciphertext.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Database\QueryUtils;

class Settings
{
    public const TRANSCRIBERS = ['mock' => 'Mock (sample transcript)', 'aws' => 'AWS Transcribe Medical', 'deepgram' => 'Deepgram (Nova-3 Medical)'];
    public const GENERATORS = ['mock' => 'Mock (template note)', 'aws' => 'AWS Bedrock (Converse API)'];
    /** Old DB-stored AWS keys (v0.1/v0.2) are ignored and purged on settings save. */
    public const LEGACY_KEYS = ['aws_access_key_id', 'aws_secret_access_key', 'prompt'];
    public const SPECIALTIES = ['PRIMARYCARE', 'CARDIOLOGY', 'NEUROLOGY', 'ONCOLOGY', 'RADIOLOGY', 'UROLOGY'];
    public const SECRET_KEYS = ['deepgram_api_key'];

    public const DEFAULT_PROMPT_NEW_PATIENT = <<<'TXT'
You are a medical scribe for a Physical Medicine and Rehabilitation physician. Write a clinical note in this exact format with these headings: CHIEF COMPLAINT, HPI, REVIEW OF SYSTEMS, PHYSICAL EXAM, IMAGING, ASSESSMENT, PLAN. Use ONLY facts stated in the transcript. Never add exam findings, vitals, history, or test results that were not said. Preserve pertinent negatives exactly. If a section has no information, write ‘Not discussed.’
TXT;

    public const DEFAULT_PROMPT_SOAP = <<<'TXT'
You are a medical scribe for a Physical Medicine and Rehabilitation physician. Write a follow-up clinical note in this exact format with these headings: SUBJECTIVE, OBJECTIVE, ASSESSMENT, PLAN. Use ONLY facts stated in the transcript. Never add exam findings, vitals, history, or test results that were not said. Preserve pertinent negatives exactly. If a section has no information, write ‘Not discussed.’
TXT;

    public const DEFAULTS = [
        'transcriber' => 'mock',
        'generator' => 'mock',
        'aws_region' => 'us-east-1',
        's3_bucket' => '',
        's3_prefix' => 'elegant-ai-scribe/',
        'transcribe_specialty' => 'PRIMARYCARE',
        'transcribe_type' => 'CONVERSATION',
        'bedrock_model_id' => 'us.meta.llama4-maverick-17b-instruct-v1:0',
        'max_tokens' => 1500,
        'temperature' => 0.1,
        'prompt_new_patient' => self::DEFAULT_PROMPT_NEW_PATIENT,
        'prompt_soap' => self::DEFAULT_PROMPT_SOAP,
        'delete_audio_after_transcription' => true,
        'max_recording_minutes' => 30,
        'deepgram_model' => 'nova-3-medical',
        'deepgram_api_key' => '',
    ];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $values = self::DEFAULTS;
        foreach (QueryUtils::fetchRecords('SELECT `setting_key`, `setting_value` FROM `oe_module_eas_settings`') as $row) {
            $key = (string)$row['setting_key'];
            if (array_key_exists($key, $values)) {
                $decoded = json_decode((string)$row['setting_value'], true);
                if ($decoded !== null || $row['setting_value'] === 'null') {
                    $values[$key] = $decoded;
                }
            }
        }
        return $values;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            QueryUtils::sqlStatementThrowException(
                'REPLACE INTO `oe_module_eas_settings` (`setting_key`, `setting_value`) VALUES (?, ?)',
                [$key, json_encode($value, JSON_THROW_ON_ERROR)]
            );
        }
    }

    public function purgeLegacy(): void
    {
        foreach (self::LEGACY_KEYS as $k) {
            QueryUtils::sqlStatementThrowException('DELETE FROM `oe_module_eas_settings` WHERE `setting_key` = ?', [$k]);
        }
    }

    public function saveSecret(string $key, string $plain): void
    {
        if (!in_array($key, self::SECRET_KEYS, true)) {
            return;
        }
        $this->save([$key => $plain === '' ? '' : (new CryptoGen())->encryptStandard($plain)]);
    }

    public function secret(string $key): string
    {
        $cipher = (string)($this->all()[$key] ?? '');
        if ($cipher === '') {
            return '';
        }
        $plain = (new CryptoGen())->decryptStandard($cipher);
        return is_string($plain) ? $plain : '';
    }

    /** Deepgram key: DEEPGRAM_API_KEY env first, else encrypted setting. */
    public function deepgramKey(): string
    {
        $v = getenv('DEEPGRAM_API_KEY');
        if (!is_string($v) || $v === '') {
            $v = $_SERVER['DEEPGRAM_API_KEY'] ?? $_ENV['DEEPGRAM_API_KEY'] ?? '';
        }
        $v = is_string($v) ? trim($v) : '';
        return $v !== '' ? $v : $this->secret('deepgram_api_key');
    }

    public function deepgramKeySource(): string
    {
        $v = getenv('DEEPGRAM_API_KEY');
        if (is_string($v) && trim($v) !== '') {
            return 'environment';
        }
        return $this->secret('deepgram_api_key') !== '' ? 'encrypted settings' : 'none found';
    }
}
