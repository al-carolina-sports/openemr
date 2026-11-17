<?php

/**
 * AWS Transcribe Medical (batch): uploads audio to S3, starts StartMedicalTranscriptionJob, polls, reads the result JSON from S3.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Provider;

use OpenEMR\Modules\ElegantAiScribe\Aws\AwsClient;
use RuntimeException;

class AwsTranscribeMedicalTranscriber implements TranscriberInterface
{
    private const FORMATS = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'mp4', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac'];

    public function __construct(
        private readonly AwsClient $aws,
        private readonly string $bucket,
        private readonly string $prefix,
        private readonly string $specialty,
        private readonly string $type
    ) {
        if ($bucket === '') {
            throw new RuntimeException('S3 bucket is not configured');
        }
    }

    public function id(): string
    {
        return 'aws';
    }

    public function start(string $audioPath, string $mime, string $sessionUuid): array
    {
        $base = strtok($mime, ';') ?: $mime;
        $format = self::FORMATS[$base] ?? null;
        if ($format === null) {
            throw new RuntimeException('Unsupported audio format for Transcribe Medical');
        }
        $job = 'eas-' . $sessionUuid . '-' . time();
        $audioKey = $this->prefix . 'audio/' . $job . '.' . $format;
        $data = file_get_contents($audioPath);
        if ($data === false) {
            throw new RuntimeException('Audio file missing');
        }
        $this->aws->s3Put($this->bucket, $audioKey, $data, $base);
        $this->aws->transcribe('StartMedicalTranscriptionJob', [
            'MedicalTranscriptionJobName' => $job,
            'LanguageCode' => 'en-US',
            'MediaFormat' => $format,
            'Media' => ['MediaFileUri' => 's3://' . $this->bucket . '/' . $audioKey],
            'OutputBucketName' => $this->bucket,
            'OutputKey' => $this->prefix . 'out/' . $job . '.json',
            'Specialty' => $this->specialty,
            'Type' => $this->type,
            'Settings' => $this->type === 'CONVERSATION' ? ['ShowSpeakerLabels' => true, 'MaxSpeakerLabels' => 2] : new \stdClass(),
        ]);
        return ['status' => 'running', 'job' => $job . '|' . $format];
    }

    public function poll(string $job): array
    {
        [$name] = explode('|', $job, 2);
        $res = $this->aws->transcribe('GetMedicalTranscriptionJob', ['MedicalTranscriptionJobName' => $name]);
        $status = $res['MedicalTranscriptionJob']['TranscriptionJobStatus'] ?? '';
        if ($status === 'FAILED') {
            return ['status' => 'failed', 'error' => substr((string)($res['MedicalTranscriptionJob']['FailureReason'] ?? 'failed'), 0, 200)];
        }
        if ($status !== 'COMPLETED') {
            return ['status' => 'running'];
        }
        $json = json_decode($this->aws->s3Get($this->bucket, $this->prefix . 'out/' . $name . '.json'), true);
        return ['status' => 'done', 'transcript' => self::format(is_array($json) ? $json : [])];
    }

    public function cleanup(string $job): void
    {
        [$name, $format] = array_pad(explode('|', $job, 2), 2, '');
        try {
            if ($format !== '') {
                $this->aws->s3Delete($this->bucket, $this->prefix . 'audio/' . $name . '.' . $format);
            }
            $this->aws->s3Delete($this->bucket, $this->prefix . 'out/' . $name . '.json');
        } catch (\Throwable) {
            // best effort; an S3 lifecycle rule on the prefix is the backstop
        }
    }

    /** Speaker-labelled lines ("Speaker 1: ...") when available, else the plain transcript. */
    private static function format(array $json): string
    {
        $results = $json['results'] ?? [];
        $plain = (string)($results['transcripts'][0]['transcript'] ?? '');
        $segments = $results['speaker_labels']['segments'] ?? [];
        $items = $results['items'] ?? [];
        if ($segments === [] || $items === []) {
            return $plain;
        }
        $speakerAt = [];
        foreach ($segments as $seg) {
            foreach ($seg['items'] ?? [] as $it) {
                $speakerAt[(string)$it['start_time']] = (string)$it['speaker_label'];
            }
        }
        $lines = [];
        $current = null;
        $buf = '';
        foreach ($items as $it) {
            $word = (string)($it['alternatives'][0]['content'] ?? '');
            if (($it['type'] ?? '') === 'punctuation') {
                $buf .= $word;
                continue;
            }
            $spk = $speakerAt[(string)($it['start_time'] ?? '')] ?? $current;
            if ($spk !== $current && $buf !== '') {
                $lines[] = self::label($current) . ': ' . trim($buf);
                $buf = '';
            }
            $current = $spk;
            $buf .= ' ' . $word;
        }
        if ($buf !== '') {
            $lines[] = self::label($current) . ': ' . trim($buf);
        }
        return implode("\n", $lines);
    }

    private static function label(?string $spk): string
    {
        return $spk === null ? 'Speaker' : 'Speaker ' . ((int)preg_replace('/\D/', '', $spk) + 1);
    }
}
