<?php

/**
 * The module's settings, parsed from storage or from the settings form.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Settings;

final readonly class Settings
{
    public const DEFAULT_MODEL = 'us.meta.llama4-maverick-17b-instruct-v1:0';
    public const DEFAULT_REGION = 'us-east-1';
    public const DEFAULT_CREDENTIALS_FILE = '/etc/openemr/ai-assistant.env';

    /**
     * @param list<int> $workingDays ISO weekdays, 1 = Monday ... 7 = Sunday
     */
    public function __construct(
        public bool $enabled = false,
        public string $modelId = self::DEFAULT_MODEL,
        public string $region = self::DEFAULT_REGION,
        public string $credentialsFile = self::DEFAULT_CREDENTIALS_FILE,
        public int $maxToolCalls = 8,
        public int $messagesPerHour = 30,
        public int $logRetentionDays = 30,
        public int $maxOutputTokens = 1000,
        public bool $useDefaultHours = true,
        public array $workingDays = [1, 2, 3, 4, 5],
        public string $practiceName = 'Carolina Sports & Spine',
    ) {
    }

    /**
     * Builds settings from stored (or submitted) values, falling back to the
     * default for anything missing or out of range.
     *
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $d = new self();
        return new self(
            enabled: self::bool($values, 'enabled', $d->enabled),
            modelId: self::text($values, 'model_id', $d->modelId, '/^[A-Za-z0-9.:\/_-]{3,200}$/'),
            region: self::text($values, 'region', $d->region, '/^[a-z]{2}(-[a-z]+)+-\d$/'),
            credentialsFile: self::text($values, 'credentials_file', $d->credentialsFile, '/^\/[^\0]{1,250}$/'),
            maxToolCalls: self::int($values, 'max_tool_calls', $d->maxToolCalls, 1, 20),
            messagesPerHour: self::int($values, 'messages_per_hour', $d->messagesPerHour, 1, 500),
            logRetentionDays: self::int($values, 'log_retention_days', $d->logRetentionDays, 1, 3650),
            maxOutputTokens: self::int($values, 'max_output_tokens', $d->maxOutputTokens, 200, 4000),
            useDefaultHours: self::bool($values, 'use_default_hours', $d->useDefaultHours),
            workingDays: self::days($values, 'working_days', $d->workingDays),
            practiceName: self::text($values, 'practice_name', $d->practiceName, '/^[^\0<>]{1,100}$/'),
        );
    }

    /**
     * @return array<string, bool|int|string|list<int>>
     */
    public function toStorage(): array
    {
        return [
            'enabled' => $this->enabled,
            'model_id' => $this->modelId,
            'region' => $this->region,
            'credentials_file' => $this->credentialsFile,
            'max_tool_calls' => $this->maxToolCalls,
            'messages_per_hour' => $this->messagesPerHour,
            'log_retention_days' => $this->logRetentionDays,
            'max_output_tokens' => $this->maxOutputTokens,
            'use_default_hours' => $this->useDefaultHours,
            'working_days' => $this->workingDays,
            'practice_name' => $this->practiceName,
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function bool(array $values, string $key, bool $default): bool
    {
        $value = $values[$key] ?? null;
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 'on' => true,
            $value === 0, $value === '0' => false,
            default => $default,
        };
    }

    /**
     * @param array<string, mixed> $values
     * @param non-empty-string $pattern
     */
    private static function text(array $values, string $key, string $default, string $pattern): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            return $default;
        }
        $value = trim($value);
        return preg_match($pattern, $value) === 1 ? $value : $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function int(array $values, string $key, int $default, int $min, int $max): int
    {
        $value = $values[$key] ?? null;
        if (is_string($value) && preg_match('/^\d{1,6}$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        return is_int($value) && $value >= $min && $value <= $max ? $value : $default;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<int> $default
     * @return list<int>
     */
    private static function days(array $values, string $key, array $default): array
    {
        $value = $values[$key] ?? null;
        if (!is_array($value)) {
            return $default;
        }
        $days = [];
        foreach ($value as $day) {
            if (is_string($day) && preg_match('/^[1-7]$/', $day) === 1) {
                $day = (int) $day;
            }
            if (is_int($day) && $day >= 1 && $day <= 7 && !in_array($day, $days, true)) {
                $days[] = $day;
            }
        }
        sort($days);
        return $days;
    }
}
