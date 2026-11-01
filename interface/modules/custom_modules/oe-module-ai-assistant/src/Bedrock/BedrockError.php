<?php

/**
 * Kinds of failure when calling Bedrock, each with a message that is safe to
 * show to staff (no AWS internals, no request content).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

enum BedrockError
{
    case Configuration;
    case AccessDenied;
    case Throttled;
    case InvalidRequest;
    case ModelUnavailable;
    case Service;
    case Network;

    public function userMessage(): string
    {
        return match ($this) {
            self::Configuration => 'The assistant is not set up yet: its AWS credentials are missing. Ask an administrator.',
            self::AccessDenied => 'The AI service refused the request (access denied). Ask an administrator to check the assistant\'s AWS access.',
            self::Throttled => 'The AI service is busy right now. Please try again in a minute.',
            self::InvalidRequest => 'The AI service could not process that request. Try rephrasing, or click Reset.',
            self::ModelUnavailable => 'The selected AI model is not available on this AWS account. Ask an administrator.',
            self::Service => 'The AI service had a problem. Please try again.',
            self::Network => 'Could not reach the AI service. Please try again.',
        };
    }

    /**
     * Maps an HTTP status and the AWS error type to a kind.
     */
    public static function fromResponse(int $status, string $awsType): self
    {
        return match (true) {
            str_contains($awsType, 'AccessDenied'), str_contains($awsType, 'UnrecognizedClient'),
            str_contains($awsType, 'InvalidSignature'), $status === 401, $status === 403 => self::AccessDenied,
            str_contains($awsType, 'Throttling'), str_contains($awsType, 'ServiceQuotaExceeded'), $status === 429 => self::Throttled,
            str_contains($awsType, 'ResourceNotFound'), str_contains($awsType, 'ModelNotReady'), $status === 404 => self::ModelUnavailable,
            $status >= 400 && $status < 500 => self::InvalidRequest,
            default => self::Service,
        };
    }
}
