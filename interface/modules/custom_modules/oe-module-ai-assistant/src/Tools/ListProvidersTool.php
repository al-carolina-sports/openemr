<?php

/**
 * list_providers: active providers, optionally filtered by name.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

use OpenEMR\Modules\AiAssistant\Bedrock\ToolSpec;
use OpenEMR\Modules\AiAssistant\Directory\Provider;
use OpenEMR\Modules\AiAssistant\Directory\ProviderDirectory;

final readonly class ListProvidersTool implements Tool
{
    public const NAME = 'list_providers';

    public function __construct(private ProviderDirectory $providers, private Permissions $permissions)
    {
    }

    public static function spec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'List active providers with their id, name, specialty and home facility. '
            . 'Use it to turn a provider name ("Dr. Antony") into a provider_id. Leave name_contains empty to list all.',
            [
                'type' => 'object',
                'properties' => [
                    'name_contains' => ['type' => 'string', 'description' => 'Part of the first or last name (optional); omit titles like "Dr."'],
                ],
            ],
        );
    }

    public function run(ToolInput $input): ToolResult
    {
        if (!$this->permissions->canReadCalendar()) {
            return ToolResult::error('Not permitted: this user cannot view the appointment calendar.');
        }
        $name = $input->optionalString('name_contains', 60);
        if ($name !== null) {
            $name = trim((string) preg_replace('/^(dr\.?|doctor)\s+/i', '', $name));
            $name = $name === '' ? null : $name;
        }
        $list = $this->providers->active($name);
        $rows = array_map(static fn(Provider $p): array => [
            'provider_id' => $p->id,
            'name' => $p->name(),
            'specialty' => $p->specialty,
            'facility_id' => $p->facilityId,
            'facility' => $p->facilityName,
        ], $list);
        $data = ['providers' => $rows];
        if ($rows === []) {
            $data['note'] = 'No active provider matches that name.';
        }
        return ToolResult::ok($data, count($rows) . ' provider(s)');
    }
}
