<?php

/**
 * Deepgram prerecorded speech-to-text (Nova-3 Medical). Sends the local audio bytes
 * directly to Deepgram (no S3); returns speaker-labelled lines from diarized utterances.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Provider;

use RuntimeException;

class DeepgramTranscriber implements TranscriberInterface
{
    private const ENDPOINT = 'https://api.deepgram.com/v1/listen';

    public function __construct(private readonly string $apiKey, private readonly string $model = 'nova-3-medical')
    {
    }

    public function id(): string
    {
        return 'deepgram';
    }

    public function start(string $audioPath, string $mime, string $sessionUuid): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Deepgram API key not set (settings page or DEEPGRAM_API_KEY env)');
        }
        $models = array_unique([$this->model, 'nova-3']);
        $last = '';
        foreach ($models as $model) {
            [$code, $body] = $this->post($audioPath, $mime, $model);
            if ($code === 200) {
                $json = json_decode($body, true);
                if (!is_array($json)) {
                    throw new RuntimeException('Deepgram returned invalid JSON');
                }
                return ['status' => 'done', 'transcript' => self::format($json)];
            }
            $err = json_decode($body, true);
            $last = 'Deepgram HTTP ' . $code . ': ' . substr((string)($err['err_code'] ?? '') . ' ' . (string)($err['err_msg'] ?? $err['message'] ?? ''), 0, 180);
            // Only fall back to nova-3 when the model itself is rejected.
            if ($code !== 400 || !preg_match('/model/i', $body)) {
                break;
            }
        }
        throw new RuntimeException(trim($last));
    }

    public function poll(string $job): array
    {
        return ['status' => 'failed', 'error' => 'Deepgram transcribes synchronously; nothing to poll'];
    }

    public function cleanup(string $job): void
    {
        // Nothing stored provider-side by this module; local audio is deleted by api.php.
    }

    /** @return array{0:int,1:string} */
    private function post(string $audioPath, string $mime, string $model): array
    {
        $query = http_build_query([
            'model' => $model,
            'smart_format' => 'true',
            'diarize' => 'true',
            'punctuate' => 'true',
            'utterances' => 'true',
        ]);
        $fh = fopen($audioPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot read audio');
        }
        $ch = curl_init(self::ENDPOINT . '?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_UPLOAD => false,
            CURLOPT_POSTFIELDS => (string)stream_get_contents($fh),
            CURLOPT_HTTPHEADER => ['Authorization: Token ' . $this->apiKey, 'Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
        ]);
        fclose($fh);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Deepgram request failed: ' . substr($err, 0, 150));
        }
        return [$code, (string)$body];
    }

    /** "Speaker 1: ..." lines from utterances, else from diarized words, else plain transcript. */
    public static function format(array $json): string
    {
        $lines = [];
        foreach ($json['results']['utterances'] ?? [] as $u) {
            $text = trim((string)($u['transcript'] ?? ''));
            if ($text === '') {
                continue;
            }
            $label = 'Speaker ' . ((int)($u['speaker'] ?? 0) + 1);
            $n = count($lines);
            if ($n > 0 && str_starts_with($lines[$n - 1], $label . ': ')) {
                $lines[$n - 1] .= ' ' . $text;
            } else {
                $lines[] = $label . ': ' . $text;
            }
        }
        if ($lines !== []) {
            return implode("\n", $lines);
        }
        $alt = $json['results']['channels'][0]['alternatives'][0] ?? [];
        $current = null;
        $buf = '';
        foreach ($alt['words'] ?? [] as $w) {
            $spk = isset($w['speaker']) ? (int)$w['speaker'] : null;
            if ($spk !== $current && $buf !== '') {
                $lines[] = ($current === null ? 'Speaker' : 'Speaker ' . ($current + 1)) . ': ' . trim($buf);
                $buf = '';
            }
            $current = $spk;
            $buf .= ' ' . (string)($w['punctuated_word'] ?? $w['word'] ?? '');
        }
        if ($buf !== '') {
            $lines[] = ($current === null ? 'Speaker' : 'Speaker ' . ($current + 1)) . ': ' . trim($buf);
        }
        return $lines !== [] ? implode("\n", $lines) : (string)($alt['transcript'] ?? '');
    }
}
