<?php

declare(strict_types=1);

namespace Aem\Renderer\Support;

use Aem\Renderer\Contracts\PatientContext;

/**
 * Pronoun agreement (spec 06 §5.3).
 *
 * Resolution order:
 *   1. PatientContext::$pronouns, if stated. Accepts "he/him", "she/her",
 *      "they/them", or a bare subject pronoun ("he", "she", "they").
 *   2. PatientContext::$sex — male/m, female/f.
 *   3. Neutral (they/them), plus a plural verb form. We never guess from a name.
 */
final class Pronouns
{
    public const SETS = [
        'he' => [
            'subject' => 'he', 'object' => 'him', 'possessive' => 'his',
            'possessive_pronoun' => 'his', 'reflexive' => 'himself', 'plural' => false,
        ],
        'she' => [
            'subject' => 'she', 'object' => 'her', 'possessive' => 'her',
            'possessive_pronoun' => 'hers', 'reflexive' => 'herself', 'plural' => false,
        ],
        'they' => [
            'subject' => 'they', 'object' => 'them', 'possessive' => 'their',
            'possessive_pronoun' => 'theirs', 'reflexive' => 'themselves', 'plural' => true,
        ],
    ];

    private array $set;

    public function __construct(PatientContext $patient)
    {
        $this->set = self::SETS[self::resolveKey($patient)];
    }

    public static function resolveKey(PatientContext $patient): string
    {
        $stated = strtolower(trim((string) $patient->pronouns));
        if ($stated !== '') {
            $first = trim(explode('/', $stated)[0]);
            if (isset(self::SETS[$first])) {
                return $first;
            }
            // "him/his" and the like: match on any token.
            foreach (self::SETS as $key => $set) {
                foreach ($set as $name => $value) {
                    if ($name !== 'plural' && str_contains($stated, (string) $value)) {
                        return $key;
                    }
                }
            }
        }

        $sex = strtolower(trim((string) $patient->sex));
        return match ($sex) {
            'm', 'male'   => 'he',
            'f', 'female' => 'she',
            default       => 'they',
        };
    }

    public function subject(): string { return $this->set['subject']; }
    public function object(): string { return $this->set['object']; }
    public function possessive(): string { return $this->set['possessive']; }
    public function reflexive(): string { return $this->set['reflexive']; }
    public function isPlural(): bool { return $this->set['plural']; }

    public function subjectCapitalised(): string
    {
        return ucfirst($this->subject());
    }

    /**
     * Agreement for a present-tense verb given in its third-person singular
     * form. "reports" stays "reports" for he/she, becomes "report" for they.
     */
    public function verb(string $thirdPersonSingular): string
    {
        if (!$this->isPlural()) {
            return $thirdPersonSingular;
        }

        return match ($thirdPersonSingular) {
            'is'  => 'are',
            'has' => 'have',
            'was' => 'were',
            'does' => 'do',
            default => preg_match('/(ches|shes|sses|xes|zes)$/', $thirdPersonSingular) === 1
                ? substr($thirdPersonSingular, 0, -2)
                : (str_ends_with($thirdPersonSingular, 'ies')
                    ? substr($thirdPersonSingular, 0, -3) . 'y'
                    : (str_ends_with($thirdPersonSingular, 's')
                        ? substr($thirdPersonSingular, 0, -1)
                        : $thirdPersonSingular)),
        };
    }
}
