<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation;

use ZeroDoc\LibraryTools\Model\Library;

final class Report
{
    /**
     * @param list<Issue>  $issues
     * @param list<string> $rulesRun
     */
    public function __construct(
        public readonly Library $library,
        public readonly array $issues,
        public readonly array $rulesRun,
    ) {
    }

    /** @return list<Issue> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $i) => $i->isError()));
    }

    /** @return list<Issue> */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $i) => !$i->isError()));
    }

    public function passed(bool $strict = false): bool
    {
        return $strict ? $this->issues === [] : $this->errors() === [];
    }

    /** @return array<string,int> */
    public function byCode(): array
    {
        $out = [];
        foreach ($this->issues as $i) {
            $out[$i->code] = ($out[$i->code] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }

    public function render(bool $colour = false, bool $warningsToo = true): string
    {
        $out = [];
        $counts = $this->library->counts();
        $out[] = 'ZeroDoc Clinical Encounter Workflow - library validation';
        $out[] = str_repeat('=', 58);
        $out[] = 'Files:      ' . (count($this->library->files) > 0
            ? implode(', ', array_map('basename', $this->library->files))
            : '(none)');
        $out[] = sprintf(
            'Contents:   %d templates, %d saved findings, %d diagnosis sets, '
            . '%d plan groups, %d order sets',
            $counts['templates'], $counts['saved_findings'], $counts['diagnosis_sets'],
            $counts['plan_groups'], $counts['order_sets']
        );
        $out[] = 'Rules run:  ' . implode(' ', $this->rulesRun);
        $out[] = '';

        $groups = [];
        foreach ($this->issues as $issue) {
            if (!$warningsToo && !$issue->isError()) {
                continue;
            }
            $groups[$issue->entry][] = $issue;
        }

        if ($groups === []) {
            $out[] = 'No issues found.';
        }
        foreach ($groups as $entry => $issues) {
            $out[] = $entry;
            $out[] = str_repeat('-', min(58, strlen((string) $entry)));
            foreach ($issues as $issue) {
                $out[] = '  ' . str_replace("\n", "\n  ", $issue->format($colour));
                $out[] = '';
            }
        }

        $out[] = str_repeat('=', 58);
        $out[] = sprintf('%d error(s), %d warning(s).', count($this->errors()), count($this->warnings()));
        if ($this->issues !== []) {
            $parts = [];
            foreach ($this->byCode() as $code => $n) {
                $parts[] = "{$code}={$n}";
            }
            $out[] = 'By rule: ' . implode('  ', $parts);
        }
        return implode("\n", $out) . "\n";
    }

    public function toJson(): string
    {
        return json_encode([
            'files'    => $this->library->files,
            'counts'   => $this->library->counts(),
            'rules'    => $this->rulesRun,
            'errors'   => count($this->errors()),
            'warnings' => count($this->warnings()),
            'issues'   => array_map(static fn (Issue $i) => $i->toArray(), $this->issues),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
