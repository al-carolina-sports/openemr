<?php

/**
 * A patient as the assistant shows it: enough to tell two people apart.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Directory;

final readonly class Patient
{
    public function __construct(
        public int $pid,
        public string $firstName,
        public string $lastName,
        public ?\DateTimeImmutable $dob,
        public string $sex,
        public ?\DateTimeImmutable $lastVisit,
    ) {
    }

    public function name(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * @return array{pid: int, name: string, dob: string|null}
     */
    public function brief(): array
    {
        return ['pid' => $this->pid, 'name' => $this->name(), 'dob' => $this->dob?->format('Y-m-d')];
    }
}
