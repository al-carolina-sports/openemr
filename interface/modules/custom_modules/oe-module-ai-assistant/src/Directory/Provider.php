<?php

/**
 * An active provider who can have a calendar.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Directory;

final readonly class Provider
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public string $title,
        public string $specialty,
        public ?int $facilityId,
        public string $facilityName,
    ) {
    }

    public function name(): string
    {
        return trim(($this->title !== '' ? $this->title . ' ' : '') . $this->firstName . ' ' . $this->lastName);
    }

    /**
     * @return array{id: int, name: string}
     */
    public function brief(): array
    {
        return ['id' => $this->id, 'name' => $this->name()];
    }
}
