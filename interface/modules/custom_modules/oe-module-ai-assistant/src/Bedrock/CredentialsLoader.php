<?php

/**
 * Finds the assistant's AWS access key: the web server's environment
 * (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, optional AWS_SESSION_TOKEN)
 * first, then a KEY=VALUE file readable only by the web server user.
 * Keys are never stored in the database or in Git.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

final readonly class CredentialsLoader
{
    /**
     * @param \Closure(string): (string|false) $env reads an environment variable
     */
    public function __construct(private string $file, private \Closure $env)
    {
    }

    public static function forFile(string $file): self
    {
        return new self($file, static fn(string $name): string|false => getenv($name));
    }

    public function load(): AwsCredentials
    {
        $id = ($this->env)('AWS_ACCESS_KEY_ID');
        $secret = ($this->env)('AWS_SECRET_ACCESS_KEY');
        if (is_string($id) && $id !== '' && is_string($secret) && $secret !== '') {
            $token = ($this->env)('AWS_SESSION_TOKEN');
            return new AwsCredentials($id, $secret, is_string($token) && $token !== '' ? $token : null);
        }
        return $this->fromFile();
    }

    /**
     * Where the key came from, for the settings page (never the key itself).
     */
    public function describeSource(): string
    {
        $id = ($this->env)('AWS_ACCESS_KEY_ID');
        if (is_string($id) && $id !== '') {
            return 'web server environment';
        }
        return is_readable($this->file) ? 'file ' . $this->file : 'not found';
    }

    private function fromFile(): AwsCredentials
    {
        if (!is_readable($this->file)) {
            throw new BedrockException(BedrockError::Configuration, 'No AWS credentials in the environment and the credentials file is missing or unreadable');
        }
        $lines = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new BedrockException(BedrockError::Configuration, 'The AWS credentials file could not be read');
        }
        $values = self::parse($lines);
        $id = $values['AWS_ACCESS_KEY_ID'] ?? '';
        $secret = $values['AWS_SECRET_ACCESS_KEY'] ?? '';
        if ($id === '' || $secret === '') {
            throw new BedrockException(BedrockError::Configuration, 'The AWS credentials file has no AWS_ACCESS_KEY_ID or AWS_SECRET_ACCESS_KEY');
        }
        $token = $values['AWS_SESSION_TOKEN'] ?? '';
        return new AwsCredentials($id, $secret, $token !== '' ? $token : null);
    }

    /**
     * Parses KEY=VALUE lines; ignores comments, "export " prefixes and quotes.
     *
     * @param list<string> $lines
     * @return array<string, string>
     */
    public static function parse(array $lines): array
    {
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            if (preg_match('/^([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $value = $m[2];
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$m[1]] = $value;
        }
        return $values;
    }
}
