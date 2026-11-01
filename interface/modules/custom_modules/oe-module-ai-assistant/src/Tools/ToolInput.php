<?php

/**
 * Typed reads of the arguments a model passed to a tool. Models send
 * numbers as 5, 5.0 or "5"; all are accepted when the value is whole.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

final readonly class ToolInput
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    public function string(string $key, int $maxLength = 100): string
    {
        return $this->optionalString($key, $maxLength) ?? throw new InvalidToolInput($key . ' is required');
    }

    public function optionalString(string $key, int $maxLength = 100): ?string
    {
        $value = $this->values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidToolInput($key . ' must be text');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidToolInput($key . ' is too long');
        }
        return $value;
    }

    public function int(string $key, int $min, int $max): int
    {
        return $this->optionalInt($key, $min, $max) ?? throw new InvalidToolInput($key . ' is required');
    }

    public function optionalInt(string $key, int $min, int $max): ?int
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $int = match (true) {
            is_int($value) => $value,
            is_float($value) && floor($value) === $value && abs($value) < 1e12 => (int) $value,
            is_string($value) && preg_match('/^-?\d{1,12}$/', trim($value)) === 1 => (int) trim($value),
            default => throw new InvalidToolInput($key . ' must be a whole number'),
        };
        if ($int < $min || $int > $max) {
            throw new InvalidToolInput($key . ' must be between ' . $min . ' and ' . $max);
        }
        return $int;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->values[$key] ?? null;
        return match (true) {
            $value === null => $default,
            is_bool($value) => $value,
            $value === 'true', $value === 1, $value === '1' => true,
            $value === 'false', $value === 0, $value === '0' => false,
            default => throw new InvalidToolInput($key . ' must be true or false'),
        };
    }

    public function date(string $key): \DateTimeImmutable
    {
        return $this->optionalDate($key) ?? throw new InvalidToolInput($key . ' is required (YYYY-MM-DD)');
    }

    public function optionalDate(string $key): ?\DateTimeImmutable
    {
        $text = $this->optionalString($key, 10);
        if ($text === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new InvalidToolInput($key . ' must be a date in YYYY-MM-DD format');
        }
        return $date;
    }
}
