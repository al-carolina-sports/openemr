<?php

/**
 * Phase 0 check: can this server reach Bedrock, and does the configured
 * model answer with a tool call? Sends no patient data.
 *
 * Run on the OpenEMR server from the OpenEMR folder, as the web server user
 * (so it reads the same credentials file Apache does):
 *
 *   sudo -u www-data php interface/modules/custom_modules/oe-module-ai-assistant/bin/spike.php
 *   sudo -u www-data php .../bin/spike.php --model=us.amazon.nova-pro-v1:0
 *   sudo -u www-data php .../bin/spike.php --credentials=/etc/openemr/ai-assistant.env --region=us-east-1
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'OpenEMR\\Modules\\AiAssistant\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenEMR\Modules\AiAssistant\Bedrock\BedrockException;
use OpenEMR\Modules\AiAssistant\Bedrock\ConverseRequest;
use OpenEMR\Modules\AiAssistant\Bedrock\CredentialsLoader;
use OpenEMR\Modules\AiAssistant\Bedrock\HttpConverseClient;
use OpenEMR\Modules\AiAssistant\Bedrock\Json;
use OpenEMR\Modules\AiAssistant\Settings\Settings;
use OpenEMR\Modules\AiAssistant\Tools\FindPatientTool;
use Psr\Clock\ClockInterface;

$options = getopt('', ['model:', 'region:', 'credentials:']);
$model = is_string($options['model'] ?? null) ? $options['model'] : Settings::DEFAULT_MODEL;
$region = is_string($options['region'] ?? null) ? $options['region'] : Settings::DEFAULT_REGION;
$file = is_string($options['credentials'] ?? null) ? $options['credentials'] : Settings::DEFAULT_CREDENTIALS_FILE;

$credentials = CredentialsLoader::forFile($file);
echo "Model:       {$model}\nRegion:      {$region}\nCredentials: {$credentials->describeSource()}\n\n";

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
};
$factory = new HttpFactory();
$client = new HttpConverseClient(
    new Client(['timeout' => 60, 'connect_timeout' => 10, 'http_errors' => false]),
    $factory,
    $factory,
    $credentials,
    $clock,
    $region,
);

$request = new ConverseRequest(
    modelId: $model,
    system: 'You are a scheduling assistant. Use the tools to look up patients. Never invent data.',
    messages: [['role' => 'user', 'content' => [['text' => "When is Patricia Test's next visit?"]]]],
    tools: [FindPatientTool::spec()],
    maxTokens: 500,
);

$started = microtime(true);
try {
    $response = $client->converse($request);
} catch (BedrockException $e) {
    echo "FAILED: {$e->getMessage()}\n";
    echo "What staff would see: {$e->error->userMessage()}\n";
    exit(1);
}
$seconds = round(microtime(true) - $started, 1);

echo "Stop reason: {$response->stopReason}  ({$seconds}s, {$response->inputTokens} in / {$response->outputTokens} out tokens)\n";
echo "Message:\n" . json_encode(Json::objects($response->message), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

$call = $response->toolUses[0] ?? null;
if ($call !== null && $call->name === 'find_patient') {
    $last = $call->input['last_name'] ?? null;
    echo 'PASS: the model asked for find_patient' . (is_string($last) ? " with last_name \"{$last}\"" : '') . ".\n";
    exit(0);
}
echo "FAIL: the model did not return a find_patient tool call. Try another model with --model=...\n";
exit(2);
