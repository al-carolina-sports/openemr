<?php

/**
 * Converse over HTTPS to bedrock-runtime, signed with SigV4.
 * POST https://bedrock-runtime.{region}.amazonaws.com/model/{modelId}/converse
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class HttpConverseClient implements ConverseClient
{
    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private CredentialsLoader $credentials,
        private ClockInterface $clock,
        private string $region,
    ) {
    }

    public function converse(ConverseRequest $request): ConverseResponse
    {
        $host = 'bedrock-runtime.' . $this->region . '.amazonaws.com';
        [$path, $canonicalPath] = SigV4Signer::paths(['model', $request->modelId, 'converse']);
        $payload = Json::encode($request->body());

        $headers = (new SigV4Signer($this->region, 'bedrock'))->sign(
            $this->credentials->load(),
            'POST',
            $canonicalPath,
            '',
            ['host' => $host, 'content-type' => 'application/json'],
            $payload,
            $this->clock->now(),
        );

        $httpRequest = $this->requests->createRequest('POST', 'https://' . $host . $path)
            ->withBody($this->streams->createStream($payload))
            ->withHeader('Accept', 'application/json');
        foreach ($headers as $name => $value) {
            $httpRequest = $httpRequest->withHeader($name, $value);
        }

        try {
            $response = $this->http->sendRequest($httpRequest);
        } catch (ClientExceptionInterface $e) {
            throw new BedrockException(BedrockError::Network, 'Bedrock request failed to send', 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status !== 200) {
            $type = $response->getHeaderLine('x-amzn-ErrorType');
            $awsMessage = '';
            try {
                $body = Json::decodeObject($raw);
                $awsMessage = is_string($body['message'] ?? null) ? $body['message'] : (is_string($body['Message'] ?? null) ? $body['Message'] : '');
            } catch (\JsonException | \UnexpectedValueException) {
                // not JSON; keep the status and type only
            }
            throw new BedrockException(
                BedrockError::fromResponse($status, $type),
                'Bedrock returned HTTP ' . $status . ' ' . $type . ': ' . mb_substr($awsMessage, 0, 300),
                $status,
            );
        }

        try {
            return ConverseResponse::fromBody(Json::decodeObject($raw));
        } catch (\JsonException | \UnexpectedValueException $e) {
            throw new BedrockException(BedrockError::Service, 'Bedrock response was not valid JSON', $status, $e);
        }
    }
}
