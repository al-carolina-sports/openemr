<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Rules\BundleRule;
use ZeroDoc\LibraryTools\Validation\Rules\Icd10Rule;
use ZeroDoc\LibraryTools\Validation\Rules\IntegrityRule;
use ZeroDoc\LibraryTools\Validation\Rules\LateralityInNameRule;
use ZeroDoc\LibraryTools\Validation\Rules\MissingMirrorRule;
use ZeroDoc\LibraryTools\Validation\Rules\NearDuplicateNameRule;
use ZeroDoc\LibraryTools\Validation\Rules\PolarityRule;
use ZeroDoc\LibraryTools\Validation\Rules\SpellingRule;
use ZeroDoc\LibraryTools\Validation\Rules\SymmetryRule;
use ZeroDoc\LibraryTools\Validation\Rules\UniqueCodeRule;
use ZeroDoc\LibraryTools\Validation\Rules\VocabularyConformanceRule;

/**
 * Runs every rule over a library and collects the findings.
 *
 * ZeroDoc Clinical Encounter Workflow - library tooling (WP-06).
 */
final class Validator
{
    /** @var list<Rule> */
    private array $rules;

    public function __construct(private readonly string $dataDir)
    {
        $this->rules = [
            new SymmetryRule(),
            new UniqueCodeRule(),
            new VocabularyConformanceRule(),
            new SpellingRule(),
            new NearDuplicateNameRule(),
            new LateralityInNameRule(),
            new Icd10Rule(),
            new MissingMirrorRule(),
            new PolarityRule(),
            new BundleRule(),
            new IntegrityRule(),
        ];
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * @param list<string> $only  rule codes to run; empty runs all
     * @param list<string> $skip  rule codes to skip
     */
    public function run(Library $library, array $only = [], array $skip = []): Report
    {
        $context = new Context($library, $this->dataDir);
        $issues  = $library->structureIssues;
        $ran     = [];

        foreach ($this->rules as $rule) {
            if ($only !== [] && !in_array($rule->code(), $only, true)) {
                continue;
            }
            if (in_array($rule->code(), $skip, true)) {
                continue;
            }
            $ran[] = $rule->code();
            foreach ($rule->check($library, $context) as $issue) {
                $issues[] = $issue;
            }
        }

        return new Report($library, $issues, $ran);
    }
}
