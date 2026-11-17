<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation;

use ZeroDoc\LibraryTools\Model\Library;

interface Rule
{
    /** Stable short code, e.g. "SYMMETRY". */
    public function code(): string;

    /** One line, shown in --list and in the report header. */
    public function describes(): string;

    /** @return list<Issue> */
    public function check(Library $library, Context $context): array;
}
