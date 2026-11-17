<?php

declare(strict_types=1);

namespace Aem\Renderer\Tests;

/**
 * A dependency-free test harness. The module must build inside OpenEMR with
 * no Composer install, so the suite does not pull PHPUnit. Run it with
 *   php tests/run.php
 */
final class Harness
{
    private int $passed = 0;
    /** @var list<string> */
    private array $failures = [];
    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo "\n" . $name . "\n" . str_repeat('-', strlen($name)) . "\n";
    }

    public function assertSame(string $what, mixed $expected, mixed $actual): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "  ok    {$what}\n";
            return;
        }

        $this->failures[] = $this->group . ' / ' . $what;
        echo "  FAIL  {$what}\n";
        echo $this->diff((string) $expected, (string) $actual);
    }

    public function assertTrue(string $what, bool $condition): void
    {
        $this->assertSame($what, true, $condition);
    }

    public function assertThrows(string $what, string $exceptionClass, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertTrue($what . ' (' . $exceptionClass . ')', $e instanceof $exceptionClass);
            return;
        }
        $this->assertTrue($what . ' (expected ' . $exceptionClass . ')', false);
    }

    private function diff(string $expected, string $actual): string
    {
        $e = explode("\n", $expected);
        $a = explode("\n", $actual);
        $out = '';
        for ($i = 0; $i < max(count($e), count($a)); $i++) {
            $left = $e[$i] ?? '<missing>';
            $right = $a[$i] ?? '<missing>';
            if ($left !== $right) {
                $out .= "        line " . ($i + 1) . "\n";
                $out .= "          expected: " . $left . "\n";
                $out .= "          actual:   " . $right . "\n";
            }
        }

        return $out === '' ? "        (differ only in trailing content)\n" : $out;
    }

    public function summary(): int
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo sprintf("%d passed, %d failed\n", $this->passed, count($this->failures));
        foreach ($this->failures as $failure) {
            echo "  FAILED: {$failure}\n";
        }

        return $this->failures === [] ? 0 : 1;
    }
}
