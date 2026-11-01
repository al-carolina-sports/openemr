<?php

/**
 * AWS Signature Version 4 for a single request, so the module can call
 * Bedrock without adding the AWS SDK to OpenEMR.
 * Spec: https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

final readonly class SigV4Signer
{
    public function __construct(private string $region, private string $service)
    {
    }

    /**
     * Returns the headers to send: the given ones plus x-amz-date,
     * x-amz-security-token (temporary keys only) and Authorization.
     *
     * @param string $canonicalPath the path exactly as SigV4 wants it
     *        (for every service except S3, each segment URI-encoded twice)
     * @param array<string, string> $headers must include "host"
     * @return array<string, string>
     */
    public function sign(
        AwsCredentials $credentials,
        string $method,
        string $canonicalPath,
        string $canonicalQuery,
        array $headers,
        string $payload,
        \DateTimeImmutable $now,
    ): array {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $utc->format('Ymd\THis\Z');
        $date = $utc->format('Ymd');

        $headers['x-amz-date'] = $amzDate;
        if ($credentials->sessionToken !== null) {
            $headers['x-amz-security-token'] = $credentials->sessionToken;
        }

        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
        ksort($canonical, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($canonical as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signedHeaders = implode(';', array_keys($canonical));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $canonicalPath,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $payload),
        ]);

        $scope = $date . '/' . $this->region . '/' . $this->service . '/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $key = hash_hmac('sha256', $date, 'AWS4' . $credentials->secretAccessKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $credentials->accessKeyId . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        return $headers;
    }

    /**
     * The request path and the canonical path for raw (unencoded) segments.
     *
     * @param list<string> $segments
     * @return array{string, string} [path to send, path to sign]
     */
    public static function paths(array $segments): array
    {
        $once = array_map(rawurlencode(...), $segments);
        $twice = array_map(rawurlencode(...), $once);
        return ['/' . implode('/', $once), '/' . implode('/', $twice)];
    }
}
