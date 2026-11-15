<?php

/**
 * Minimal AWS Signature Version 4 client over Guzzle (already in OpenEMR's vendor). The AWS SDK for PHP is not bundled with OpenEMR, and only four calls are needed: S3 PUT/GET/DELETE, Transcribe Medical (JSON 1.1) and Bedrock Converse.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Aws;

use GuzzleHttp\Client;
use RuntimeException;

class AwsClient
{
    private Client $http;

    public function __construct(private readonly Credentials $credentials, private readonly string $region, ?Client $http = null)
    {
        if (!preg_match('/^[a-z]{2}(-gov)?-[a-z]+-\d$/', $region)) {
            throw new RuntimeException('Invalid AWS region');
        }
        $this->http = $http ?? new Client(['timeout' => 120, 'connect_timeout' => 10, 'http_errors' => false]);
    }

    public function s3Put(string $bucket, string $key, string $body, string $contentType): void
    {
        $res = $this->send('PUT', 's3', $this->s3Host($bucket), '/' . self::encodePath($key), [], $body, ['content-type' => $contentType]);
        $this->expect($res, 'S3 PUT');
    }

    public function s3Get(string $bucket, string $key): string
    {
        $res = $this->send('GET', 's3', $this->s3Host($bucket), '/' . self::encodePath($key), [], '');
        return $this->expect($res, 'S3 GET');
    }

    public function s3Delete(string $bucket, string $key): void
    {
        $this->send('DELETE', 's3', $this->s3Host($bucket), '/' . self::encodePath($key), [], '');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function transcribe(string $action, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $res = $this->send('POST', 'transcribe', 'transcribe.' . $this->region . '.amazonaws.com', '/', [], $body, [
            'content-type' => 'application/x-amz-json-1.1',
            'x-amz-target' => 'Transcribe.' . $action,
        ]);
        return $this->json($this->expect($res, 'Transcribe ' . $action));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function bedrockConverse(string $modelId, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $path = '/model/' . rawurlencode($modelId) . '/converse';
        $res = $this->send('POST', 'bedrock', 'bedrock-runtime.' . $this->region . '.amazonaws.com', $path, [], $body, [
            'content-type' => 'application/json',
            'accept' => 'application/json',
        ]);
        return $this->json($this->expect($res, 'Bedrock Converse'));
    }

    private function s3Host(string $bucket): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new RuntimeException('Invalid S3 bucket name');
        }
        return $bucket . '.s3.' . $this->region . '.amazonaws.com';
    }

    /** @return array{0: int, 1: string} */
    private function send(string $method, string $service, string $host, string $path, array $query, string $body, array $headers = []): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $payloadHash = hash('sha256', $body);

        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['host'] = $host;
        $headers['x-amz-date'] = $amzDate;
        $headers['x-amz-content-sha256'] = $payloadHash;
        if ($this->credentials->sessionToken !== null) {
            $headers['x-amz-security-token'] = $this->credentials->sessionToken;
        }
        ksort($headers);

        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        // Non-S3 services sign the URI-encoded path encoded once more.
        $canonicalPath = $service === 's3' ? $path : implode('/', array_map('rawurlencode', explode('/', $path)));
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim((string)preg_replace('/\s+/', ' ', (string)$v)) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = implode("\n", [$method, $canonicalPath, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash]);

        $scope = $date . '/' . $this->region . '/' . $service . '/aws4_request';
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->credentials->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->credentials->accessKeyId . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        unset($headers['host']);

        $url = 'https://' . $host . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
        $response = $this->http->request($method, $url, ['headers' => $headers, 'body' => $body]);
        return [$response->getStatusCode(), (string)$response->getBody()];
    }

    /** @param array{0: int, 1: string} $res */
    private function expect(array $res, string $what): string
    {
        [$status, $body] = $res;
        if ($status < 200 || $status >= 300) {
            // Keep AWS error codes, never echo request data.
            $code = '';
            if (preg_match('/"__type"\s*:\s*"([^"#]*#)?([^"]+)"|<Code>([^<]+)<\/Code>|"message"\s*:\s*"([^"]{0,160})/i', $body, $m)) {
                foreach ([2, 3, 4] as $i) {
                    if (!empty($m[$i])) {
                        $code = $m[$i];
                        break;
                    }
                }
            }
            throw new RuntimeException($what . ' failed: HTTP ' . $status . ($code !== '' ? ' ' . $code : ''));
        }
        return $body;
    }

    /** @return array<string, mixed> */
    private function json(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Unexpected AWS response');
        }
        return $data;
    }

    private static function encodePath(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
