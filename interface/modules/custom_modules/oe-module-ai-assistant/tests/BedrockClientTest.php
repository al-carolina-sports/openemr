<?php

/**
 * SigV4 signing (checked against vectors produced by the official AWS SDK
 * for PHP), request encoding, response parsing and error mapping.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\AiAssistant\Bedrock\AwsCredentials;
use OpenEMR\Modules\AiAssistant\Bedrock\BedrockError;
use OpenEMR\Modules\AiAssistant\Bedrock\BedrockException;
use OpenEMR\Modules\AiAssistant\Bedrock\ConverseRequest;
use OpenEMR\Modules\AiAssistant\Bedrock\ConverseResponse;
use OpenEMR\Modules\AiAssistant\Bedrock\CredentialsLoader;
use OpenEMR\Modules\AiAssistant\Bedrock\HttpConverseClient;
use OpenEMR\Modules\AiAssistant\Bedrock\Json;
use OpenEMR\Modules\AiAssistant\Bedrock\SigV4Signer;
use OpenEMR\Modules\AiAssistant\Tests\Support\Dig;
use OpenEMR\Modules\AiAssistant\Tests\Support\FixedClock;
use OpenEMR\Modules\AiAssistant\Tools\FindPatientTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BedrockClientTest extends TestCase
{
    private const KEY_ID = 'AKIAIOSFODNN7EXAMPLE';
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    private const TOKEN = 'FwoGZXIvYXdzEJr//////////wEaDExampleToken==';
    private const PAYLOAD = '{"messages":[{"role":"user","content":[{"text":"Reply with the word OK."}]}]}';

    /**
     * Expected signatures were produced by Aws\Signature\SignatureV4 (aws-sdk-php 3.x)
     * for the same request at 2026-11-01 18:38:41 UTC.
     *
     * @return array<string, array{?string, string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function sdkVectors(): array
    {
        return [
            'long-term key' => [null, 'content-type;host;x-amz-date', 'b5ec37995fdc3bdcb5e1f8eedab39c3e7b878c3505c9ae37528dbd2d51e4eefe'],
            'temporary key' => [self::TOKEN, 'content-type;host;x-amz-date;x-amz-security-token', '23cd9c8458c9a1fb06ea5bd1fc51ffa9329a920e6cff2430e283245015a18637'],
        ];
    }

    #[DataProvider('sdkVectors')]
    public function testSignatureMatchesTheAwsSdk(?string $token, string $signedHeaders, string $signature): void
    {
        [, $canonical] = SigV4Signer::paths(['model', 'us.meta.llama4-maverick-17b-instruct-v1:0', 'converse']);
        $headers = (new SigV4Signer('us-east-1', 'bedrock'))->sign(
            new AwsCredentials(self::KEY_ID, self::SECRET, $token),
            'POST',
            $canonical,
            '',
            ['host' => 'bedrock-runtime.us-east-1.amazonaws.com', 'content-type' => 'application/json'],
            self::PAYLOAD,
            new \DateTimeImmutable('2026-11-01 13:38:41', new \DateTimeZone('America/New_York')),
        );
        self::assertSame('20261101T183841Z', $headers['x-amz-date']);
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=' . self::KEY_ID . '/20261101/us-east-1/bedrock/aws4_request, SignedHeaders='
            . $signedHeaders . ', Signature=' . $signature,
            $headers['Authorization']
        );
    }

    public function testModelIdIsEncodedOnceToSendAndTwiceToSign(): void
    {
        [$path, $canonical] = SigV4Signer::paths(['model', 'us.meta.llama4-maverick-17b-instruct-v1:0', 'converse']);
        self::assertSame('/model/us.meta.llama4-maverick-17b-instruct-v1%3A0/converse', $path);
        self::assertSame('/model/us.meta.llama4-maverick-17b-instruct-v1%253A0/converse', $canonical);
    }

    public function testSecretIsHiddenFromDumps(): void
    {
        $dump = print_r(new AwsCredentials(self::KEY_ID, self::SECRET), true);
        self::assertStringNotContainsString(self::SECRET, $dump);
    }

    public function testEmptySchemaPropertiesAndToolInputBecomeObjects(): void
    {
        self::assertSame('{"properties":{},"input":{},"list":[]}', Json::encode(['properties' => [], 'input' => [], 'list' => []]));
    }

    public function testCredentialsComeFromTheEnvironmentFirst(): void
    {
        $loader = new CredentialsLoader('/nonexistent', static fn(string $n): string|false => match ($n) {
            'AWS_ACCESS_KEY_ID' => self::KEY_ID,
            'AWS_SECRET_ACCESS_KEY' => self::SECRET,
            default => false,
        });
        self::assertSame(self::KEY_ID, $loader->load()->accessKeyId);
        self::assertSame('web server environment', $loader->describeSource());
    }

    public function testCredentialsFileIsParsed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aia');
        self::assertIsString($file);
        file_put_contents($file, "# AI assistant\nexport AWS_ACCESS_KEY_ID=" . self::KEY_ID . "\nAWS_SECRET_ACCESS_KEY=\"" . self::SECRET . "\"\n");
        try {
            $credentials = (new CredentialsLoader($file, static fn(string $n): false => false))->load();
            self::assertSame(self::KEY_ID, $credentials->accessKeyId);
            self::assertSame(self::SECRET, $credentials->secretAccessKey);
            self::assertNull($credentials->sessionToken);
        } finally {
            unlink($file);
        }
    }

    public function testMissingCredentialsIsAConfigurationError(): void
    {
        try {
            (new CredentialsLoader('/nonexistent/file', static fn(string $n): false => false))->load();
            self::fail('Expected an exception');
        } catch (BedrockException $e) {
            self::assertSame(BedrockError::Configuration, $e->error);
        }
    }

    public function testRequestIsSignedAndResponseParsed(): void
    {
        $http = $this->http(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['text' => 'Let me look that up.'],
                ['toolUse' => ['toolUseId' => 'tu-1', 'name' => 'find_patient', 'input' => ['last_name' => 'Test']]],
            ]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 410, 'outputTokens' => 31],
        ])));
        $response = $this->client($http)->converse($this->request());

        $sent = $http->sent;
        self::assertNotNull($sent);
        self::assertSame('https://bedrock-runtime.us-east-1.amazonaws.com/model/us.meta.llama4-maverick-17b-instruct-v1%3A0/converse', (string) $sent->getUri());
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=' . self::KEY_ID . '/', $sent->getHeaderLine('Authorization'));
        $body = Json::decodeObject((string) $sent->getBody());
        self::assertEquals(0, Dig::at($body, 'inferenceConfig', 'temperature'));
        self::assertSame('find_patient', Dig::at($body, 'toolConfig', 'tools', 0, 'toolSpec', 'name'));

        self::assertTrue($response->wantsTools());
        self::assertSame('find_patient', $response->toolUses[0]->name);
        self::assertSame(['last_name' => 'Test'], $response->toolUses[0]->input);
        self::assertSame('Let me look that up.', $response->text);
        self::assertSame(410, $response->inputTokens);
    }

    /**
     * @return array<string, array{int, string, BedrockError}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function errors(): array
    {
        return [
            'access denied' => [403, 'AccessDeniedException', BedrockError::AccessDenied],
            'bad signature' => [403, 'InvalidSignatureException', BedrockError::AccessDenied],
            'throttled' => [429, 'ThrottlingException', BedrockError::Throttled],
            'unknown model' => [404, 'ResourceNotFoundException', BedrockError::ModelUnavailable],
            'validation' => [400, 'ValidationException', BedrockError::InvalidRequest],
            'service' => [500, 'InternalServerException', BedrockError::Service],
        ];
    }

    #[DataProvider('errors')]
    public function testErrorsAreClassified(int $status, string $type, BedrockError $expected): void
    {
        $http = $this->http(new Response($status, ['x-amzn-ErrorType' => $type . ':http://internal.amazon.com/'], '{"message":"nope"}'));
        try {
            $this->client($http)->converse($this->request());
            self::fail('Expected an exception');
        } catch (BedrockException $e) {
            self::assertSame($expected, $e->error);
            self::assertSame($status, $e->httpStatus);
        }
    }

    public function testEmptyToolInputIsReplayedAsAnObject(): void
    {
        $response = ConverseResponse::fromBody([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['toolUse' => ['toolUseId' => 'tu-2', 'name' => 'list_providers', 'input' => []]],
            ]]],
            'stopReason' => 'tool_use',
        ]);
        self::assertStringContainsString('"input":{}', Json::encode([$response->message]));
    }

    private function request(): ConverseRequest
    {
        return new ConverseRequest(
            'us.meta.llama4-maverick-17b-instruct-v1:0',
            'system',
            [['role' => 'user', 'content' => [['text' => 'hi']]]],
            [FindPatientTool::spec()],
            500,
        );
    }

    private function client(ClientInterface $http): HttpConverseClient
    {
        $factory = new HttpFactory();
        return new HttpConverseClient(
            $http,
            $factory,
            $factory,
            new CredentialsLoader('/nonexistent', static fn(string $n): string|false => match ($n) {
                'AWS_ACCESS_KEY_ID' => self::KEY_ID,
                'AWS_SECRET_ACCESS_KEY' => self::SECRET,
                default => false,
            }),
            FixedClock::at('2026-11-01 13:38:41'),
            'us-east-1',
        );
    }

    /**
     * @return ClientInterface&object{sent: ?RequestInterface}
     */
    private function http(ResponseInterface $response): ClientInterface
    {
        return new class ($response) implements ClientInterface {
            public ?RequestInterface $sent = null;

            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent = $request;
                return $this->response;
            }
        };
    }
}
