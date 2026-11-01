<?php

/**
 * find_patient: look a patient up by last name, optionally first name and DOB.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

use OpenEMR\Modules\AiAssistant\Bedrock\ToolSpec;
use OpenEMR\Modules\AiAssistant\Directory\Patient;
use OpenEMR\Modules\AiAssistant\Directory\PatientDirectory;

final readonly class FindPatientTool implements Tool
{
    public const NAME = 'find_patient';
    private const LIMIT = 10;

    public function __construct(private PatientDirectory $patients, private Permissions $permissions)
    {
    }

    public static function spec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'Find patients by name. Returns up to 10 matches with pid, full name, date of birth and last visit date. '
            . 'Names match from the start ("Tes" finds "Test"). If more than one patient matches, ask the user which one '
            . '(show each date of birth) before doing anything else. Never guess a patient.',
            [
                'type' => 'object',
                'properties' => [
                    'last_name' => ['type' => 'string', 'description' => 'Last name, or its beginning'],
                    'first_name' => ['type' => 'string', 'description' => 'First name, or its beginning (optional)'],
                    'dob' => ['type' => 'string', 'description' => 'Date of birth as YYYY-MM-DD (optional)'],
                ],
                'required' => ['last_name'],
            ],
        );
    }

    public function run(ToolInput $input): ToolResult
    {
        if (!$this->permissions->canReadPatients()) {
            return ToolResult::error('Not permitted: this user cannot look up patient demographics.');
        }
        $last = $input->string('last_name', 60);
        $first = $input->optionalString('first_name', 60);
        $dob = $input->optionalDate('dob');

        $found = $this->patients->search($last, $first, $dob, self::LIMIT + 1);
        $more = count($found) > self::LIMIT;
        $found = array_slice($found, 0, self::LIMIT);

        $matches = array_map(static fn(Patient $p): array => [
            'pid' => $p->pid,
            'name' => $p->name(),
            'dob' => $p->dob?->format('Y-m-d'),
            'sex' => $p->sex,
            'last_visit' => $p->lastVisit?->format('Y-m-d'),
        ], $found);

        $data = ['matches' => $matches, 'count' => count($matches)];
        if ($more) {
            $data['note'] = 'More than 10 patients match. Ask for the first name or date of birth.';
        } elseif ($matches === []) {
            $data['note'] = 'No patient matches. Do not guess; tell the user and ask them to check the spelling.';
        } elseif (count($matches) > 1) {
            $data['note'] = 'Several patients match. Ask the user which one, showing each date of birth.';
        }
        return ToolResult::ok(
            $data,
            count($matches) . ' match(es)' . ($more ? ', more not shown' : ''),
            array_map(static fn(Patient $p): int => $p->pid, $found),
        );
    }
}
