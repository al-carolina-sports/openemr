<?php

/**
 * A failed Bedrock call. The message is for the log; show staff
 * $error->userMessage() instead.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

final class BedrockException extends \RuntimeException
{
    public function __construct(
        public readonly BedrockError $error,
        string $message,
        public readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
