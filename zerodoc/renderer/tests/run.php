<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../fixtures/ReferenceEncounter.php';
require __DIR__ . '/Harness.php';
require __DIR__ . '/UnitTest.php';
require __DIR__ . '/ReferenceNoteTest.php';

use Aem\Renderer\Tests\Harness;
use Aem\Renderer\Tests\ReferenceNoteTest;
use Aem\Renderer\Tests\UnitTest;

$t = new Harness();
UnitTest::run($t);
ReferenceNoteTest::run($t);

exit($t->summary());
