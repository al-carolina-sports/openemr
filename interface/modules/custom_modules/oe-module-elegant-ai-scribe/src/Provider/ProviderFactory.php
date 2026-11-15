<?php

/**
 * Builds the configured providers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Provider;

use OpenEMR\Modules\ElegantAiScribe\Aws\AwsClient;
use OpenEMR\Modules\ElegantAiScribe\Aws\Credentials;
use OpenEMR\Modules\ElegantAiScribe\Settings;
use RuntimeException;

final class ProviderFactory
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly Settings $settings, private readonly array $config)
    {
    }

    public function transcriber(?string $id = null): TranscriberInterface
    {
        $id ??= (string)$this->config['transcriber'];
        if ($id === 'deepgram') {
            $model = (string)($this->config['deepgram_model'] ?? 'nova-3-medical');
            return new DeepgramTranscriber($this->settings->deepgramKey(), preg_match('/^[a-z0-9.-]{2,40}$/', $model) ? $model : 'nova-3-medical');
        }
        if ($id !== 'aws') {
            return new MockTranscriber();
        }
        return new AwsTranscribeMedicalTranscriber(
            $this->aws(),
            (string)$this->config['s3_bucket'],
            self::prefix((string)$this->config['s3_prefix']),
            in_array($this->config['transcribe_specialty'], Settings::SPECIALTIES, true) ? (string)$this->config['transcribe_specialty'] : 'PRIMARYCARE',
            $this->config['transcribe_type'] === 'DICTATION' ? 'DICTATION' : 'CONVERSATION'
        );
    }

    public function generator(?string $id = null): NoteGeneratorInterface
    {
        $id ??= (string)$this->config['generator'];
        if ($id !== 'aws') {
            return new MockNoteGenerator();
        }
        return new BedrockNoteGenerator($this->aws(), (string)$this->config['bedrock_model_id'], (int)$this->config['max_tokens'], (float)($this->config['temperature'] ?? 0.1));
    }

    private function aws(): AwsClient
    {
        $credentials = Credentials::resolve($this->settings);
        if ($credentials === null) {
            throw new RuntimeException('AWS credentials not found in the environment (AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY via the container env_file)');
        }
        return new AwsClient($credentials, (string)$this->config['aws_region']);
    }

    private static function prefix(string $p): string
    {
        $p = trim(preg_replace('#[^A-Za-z0-9/_.-]#', '', $p) ?? '', '/');
        return $p === '' ? '' : $p . '/';
    }
}
