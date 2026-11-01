<?php

/**
 * An AWS access key. The secret never appears in var_dump, print_r or logs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

final readonly class AwsCredentials
{
    public function __construct(
        public string $accessKeyId,
        #[\SensitiveParameter]
        public string $secretAccessKey,
        #[\SensitiveParameter]
        public ?string $sessionToken = null,
    ) {
        if (preg_match('/^[A-Z0-9]{16,128}$/', $accessKeyId) !== 1) {
            throw new BedrockException(BedrockError::Configuration, 'AWS access key id is not in the expected format');
        }
        if (strlen($secretAccessKey) < 16) {
            throw new BedrockException(BedrockError::Configuration, 'AWS secret access key is too short');
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['accessKeyId' => $this->accessKeyId, 'secretAccessKey' => '***', 'sessionToken' => $this->sessionToken === null ? 'none' : '***'];
    }
}
