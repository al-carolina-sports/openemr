<?php

/**
 * Provider lookups for the assistant.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Directory;

interface ProviderDirectory
{
    /**
     * Active providers, optionally only those whose name contains $nameContains.
     *
     * @return list<Provider>
     */
    public function active(?string $nameContains): array;

    public function find(int $id): ?Provider;
}
