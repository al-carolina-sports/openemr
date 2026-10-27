<?php

/**
 * Parses raw form text into the value stored in a column.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;

final class FieldParser
{
    /**
     * Parses every field of an entity. Booleans missing from the input are
     * unchecked checkboxes and become 0.
     *
     * @param array<string, string> $input raw text per field name
     * @return array<string, string|int|null> column => stored value
     * @throws ValidationException listing every bad field
     */
    public function parseAll(EntityDefinition $entity, array $input): array
    {
        $values = [];
        $errors = [];
        foreach ($entity->fields as $field) {
            try {
                $values[$field->name] = $this->parse($field, $input[$field->name] ?? '');
            } catch (\InvalidArgumentException $e) {
                $errors[$field->name] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $values;
    }

    /**
     * @throws \InvalidArgumentException with a message safe to show the user
     */
    public function parse(FieldDefinition $field, string $raw): string|int|null
    {
        $text = trim($raw);
        if ($field->type === FieldType::Boolean) {
            return in_array(strtolower($text), ['1', 'on', 'yes', 'true', 'y'], true) ? 1 : 0;
        }
        if ($text === '') {
            if ($field->required) {
                throw new \InvalidArgumentException('Required');
            }
            return match ($field->type) {
                FieldType::Money, FieldType::Decimal => $field->default !== '' ? $field->default : '0',
                FieldType::Integer => $field->default !== '' ? (int) $field->default : null,
                FieldType::Text, FieldType::LongText => null,
                FieldType::Choice => array_key_first($field->choices),
                default => null,
            };
        }
        return match ($field->type) {
            FieldType::Text => $this->text($text, $field->maxLength),
            FieldType::LongText => $this->text($text, 65535),
            FieldType::Money => $this->money($text),
            FieldType::Decimal => $this->decimal($text, $field->scale),
            FieldType::Integer, FieldType::Lookup => $this->integer($text),
            FieldType::Date => $this->date($text),
            FieldType::Choice => $this->choice($text, $field->choices),
        };
    }

    private function text(string $text, int $maxLength): string
    {
        if (mb_strlen($text) > $maxLength) {
            throw new \InvalidArgumentException('Too long (maximum ' . $maxLength . ' characters)');
        }
        return $text;
    }

    private function money(string $text): string
    {
        try {
            return Money::parse($text)->toDecimalString();
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('Enter an amount like 125.00');
        }
    }

    private function decimal(string $text, int $scale): string
    {
        try {
            return Decimal::format(Decimal::parse($text, $scale), $scale);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('Enter a number with up to ' . $scale . ' decimal places');
        }
    }

    private function integer(string $text): int
    {
        if (preg_match('/^-?\d{1,10}$/', $text) !== 1) {
            throw new \InvalidArgumentException('Enter a whole number');
        }
        return (int) $text;
    }

    private function date(string $text): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new \InvalidArgumentException('Enter a date as YYYY-MM-DD');
        }
        return $text;
    }

    /**
     * @param array<string, string> $choices
     */
    private function choice(string $text, array $choices): string
    {
        if (!array_key_exists($text, $choices)) {
            throw new \InvalidArgumentException('Not an allowed value');
        }
        return $text;
    }
}
