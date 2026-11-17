<?php

/**
 * Note generator: transcript + prompt -> sections of the chosen template.
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Provider;

interface NoteGeneratorInterface
{
    public function id(): string;

    /**
     * @param array<string, string> $sections key => HEADING
     * @return array<string, string>
     */
    public function generate(string $transcript, string $prompt, array $sections): array;

    /** Token usage / latency of the last call, if known. @return array<string, int>|null */
    public function lastUsage(): ?array;
}
