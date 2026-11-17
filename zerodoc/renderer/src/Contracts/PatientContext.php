<?php

declare(strict_types=1);

namespace Aem\Renderer\Contracts;

/**
 * Patient context supplied to the renderer. Pure input; nothing is loaded.
 *
 * Pronouns: if $pronouns is given (stated pronouns, e.g. "they/them"), it
 * wins over $sex. $sex is only a fallback, and an unknown or absent sex
 * yields the neutral set rather than a guess.
 */
final class PatientContext
{
    public function __construct(
        public readonly string $display_name = '',
        public readonly ?string $sex = null,          // 'male' | 'female' | 'M' | 'F' | other | null
        public readonly ?int $age = null,
        public readonly ?string $pronouns = null,     // 'he/him' | 'she/her' | 'they/them'
        public readonly ?string $record_id = null,
        public readonly ?string $dob = null,
        /** The subject noun used for the first clause of a paragraph section. */
        public readonly string $subject_noun = 'Patient',
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            display_name: (string) ($row['display_name'] ?? ''),
            sex: $row['sex'] ?? null,
            age: isset($row['age']) ? (int) $row['age'] : null,
            pronouns: $row['pronouns'] ?? null,
            record_id: isset($row['record_id']) ? (string) $row['record_id'] : null,
            dob: $row['dob'] ?? null,
            subject_noun: (string) ($row['subject_noun'] ?? 'Patient'),
        );
    }
}
