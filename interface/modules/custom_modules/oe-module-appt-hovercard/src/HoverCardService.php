<?php

/**
 * Builds the JSON payload for one appointment. Read-only; every query uses
 * bound parameters. Fields the user's role may not see are never added to the
 * payload, so hiding is enforced here, not in JavaScript.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ApptHoverCard;

use OpenEMR\Common\Database\QueryUtils;

class HoverCardService
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly array $settings,
        private readonly AccessPolicy $access,
        private readonly \DateTimeImmutable $today,
        private readonly string $webRoot,
        private readonly PhotoService $photos = new PhotoService()
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the appointment does not exist
     */
    public function build(int $eid, bool $canSeeDemographics = true): ?array
    {
        $event = QueryUtils::querySingleRow(
            'SELECT e.`pc_eid`, e.`pc_pid`, e.`pc_hometext`, e.`pc_eventDate`, e.`pc_startTime`, '
            . 'e.`pc_duration`, e.`pc_apptstatus`, c.`pc_catname`, '
            . 'u.`fname` AS provider_fname, u.`lname` AS provider_lname, f.`name` AS facility_name '
            . 'FROM `openemr_postcalendar_events` e '
            . 'LEFT JOIN `openemr_postcalendar_categories` c ON c.`pc_catid` = e.`pc_catid` '
            . 'LEFT JOIN `users` u ON u.`id` = e.`pc_aid` '
            . 'LEFT JOIN `facility` f ON f.`id` = e.`pc_facility` '
            . 'WHERE e.`pc_eid` = ?',
            [$eid],
            false
        );
        if (!is_array($event)) {
            return null;
        }

        $pid = (int)($event['pc_pid'] ?? 0);
        $patient = $pid > 0 ? $this->patientRow($pid) : null;
        // DOB and contact data need the role rule AND core's patients/demo ACL.
        $sensitive = $canSeeDemographics && $this->access->canViewSensitive();
        $values = $this->appointmentValues($event);

        if ($patient !== null) {
            $values += $this->patientValues($patient, $sensitive);
            if ($sensitive) {
                $values += $this->contactValues($patient);
                $values += $this->extraValues($pid);
            }
        }

        $catalog = FieldCatalog::all();
        /** @var array<string, array{show: bool, order: int, label: string}> $fieldSettings */
        $fieldSettings = $this->settings['fields'];
        $blocks = [
            FieldCatalog::BLOCK_APPOINTMENT => [],
            FieldCatalog::BLOCK_PATIENT => [],
            FieldCatalog::BLOCK_CONTACT => [],
        ];
        foreach ($fieldSettings as $key => $field) {
            if (!$field['show'] || !isset($catalog[$key])) {
                continue;
            }
            if ($catalog[$key]['sensitive'] && !$sensitive) {
                continue;
            }
            $value = $values[$key] ?? '';
            if ($value === '') {
                continue; // empty fields are hidden, not shown as blank lines
            }
            $blocks[$catalog[$key]['block']][] = [
                'order' => $field['order'],
                'key' => $key,
                'label' => $field['label'],
                'value' => $value,
            ];
        }
        foreach ($blocks as $block => $rows) {
            usort($rows, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            $blocks[$block] = array_map(
                static fn (array $r): array => ['key' => $r['key'], 'label' => $r['label'], 'value' => $r['value']],
                $rows
            );
        }

        return [
            'eid' => $eid,
            'pid' => $pid,
            'blocks' => $blocks,
            'photo' => $patient !== null && $canSeeDemographics ? $this->photo($pid, $patient) : null,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, string>
     */
    private function appointmentValues(array $event): array
    {
        $start = is_string($event['pc_startTime'] ?? null) ? $event['pc_startTime'] : '';
        $seconds = (int)($event['pc_duration'] ?? 0);
        $range = '';
        if ($start !== '') {
            $from = \DateTimeImmutable::createFromFormat('H:i:s', $start);
            if ($from !== false) {
                $range = $from->format('g:i');
                if ($seconds > 0) {
                    $range .= '–' . $from->modify('+' . $seconds . ' seconds')->format('g:i A');
                } else {
                    $range = $from->format('g:i A');
                }
            }
        }

        return [
            'time_range' => $range,
            'provider' => trim(self::str($event, 'provider_fname') . ' ' . self::str($event, 'provider_lname')),
            'facility' => self::str($event, 'facility_name'),
            'reason' => self::str($event, 'pc_catname'),
            'comments' => self::str($event, 'pc_hometext'),
            'status' => self::str($event, 'pc_apptstatus') === '-' ? '' : self::str($event, 'pc_apptstatus'),
        ];
    }

    /**
     * @param array<string, mixed> $patient
     * @return array<string, string>
     */
    private function patientValues(array $patient, bool $sensitive): array
    {
        $name = trim(self::str($patient, 'fname') . ' ' . self::str($patient, 'lname'));
        $nickname = self::str($patient, 'preferred_name');
        if ($nickname !== '') {
            $name .= ' (' . $nickname . ')';
        }

        $values = ['name' => $name];
        if ($sensitive) {
            $dob = self::str($patient, 'DOB');
            $age = AccessPolicy::ageOn($dob, $this->today);
            if ($age !== null) {
                $values['dob_age'] = $dob . ' (' . $age . ')';
            }
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $patient
     * @return array<string, string>
     */
    private function contactValues(array $patient): array
    {
        $mask = (bool)($this->settings['mask_phone'] ?? false);
        $phone = static fn (string $p): string => $mask ? AccessPolicy::maskPhone($p) : $p;
        $other = array_filter([
            $phone(self::str($patient, 'phone_home')),
            $phone(self::str($patient, 'phone_biz')),
        ], static fn (string $p): bool => $p !== '');

        $values = [
            'email' => self::str($patient, 'email'),
            'cell' => $phone(self::str($patient, 'phone_cell')),
            'other_phones' => implode(' / ', $other),
        ];

        foreach (['preferred_contact' => 'source_preferred_contact', 'best_time' => 'source_best_time'] as $field => $setting) {
            $column = $this->settings[$setting] ?? '';
            if (is_string($column) && $column !== '' && array_key_exists($column, $patient)) {
                $values[$field] = self::str($patient, $column);
            }
        }
        return $values;
    }

    /**
     * Fields that need an extra query. Only run when the field is switched on.
     *
     * @return array<string, string>
     */
    private function extraValues(int $pid): array
    {
        /** @var array<string, array{show: bool}> $fields */
        $fields = $this->settings['fields'];
        $values = [];

        if ($fields['insurance']['show']) {
            $row = QueryUtils::querySingleRow(
                'SELECT ic.`name` FROM `insurance_data` i JOIN `insurance_companies` ic ON ic.`id` = i.`provider` '
                . 'WHERE i.`pid` = ? AND i.`type` = ? AND i.`provider` <> \'\' '
                . 'ORDER BY i.`date` DESC, i.`id` DESC LIMIT 1',
                [$pid, 'primary'],
                false
            );
            $values['insurance'] = is_array($row) ? self::str($row, 'name') : '';
        }

        if ($fields['last_visit']['show']) {
            $row = QueryUtils::querySingleRow(
                'SELECT MAX(`date`) AS last_visit FROM `form_encounter` WHERE `pid` = ?',
                [$pid],
                false
            );
            $values['last_visit'] = is_array($row) ? substr(self::str($row, 'last_visit'), 0, 10) : '';
        }

        // TODO: 'balance' needs OpenEMR's patient-balance calculation; not wired up in this scaffold.
        return $values;
    }

    /**
     * @param array<string, mixed> $patient
     * @return array{url: string|null, initials: string, size: int}|null
     */
    private function photo(int $pid, array $patient): ?array
    {
        if (!(bool)($this->settings['photo_show'] ?? false)) {
            return null;
        }

        $size = Settings::PHOTO_SIZES[(string)($this->settings['photo_size'] ?? 'medium')] ?? 100;
        $category = (string)($this->settings['photo_category'] ?? 'Patient Photograph');
        $url = null;
        if ($this->photos->findDocumentId($pid, $category) !== null) {
            // Module endpoint, not core's document controller (8.5 only lets that one serve the open patient).
            $url = $this->webRoot . Bootstrap::MODULE_PATH . '/public/ajax.php?action=photo&pid=' . $pid;
        }
        if ($url === null && ($this->settings['photo_missing'] ?? 'initials') === 'hide') {
            return null;
        }

        $initials = mb_strtoupper(mb_substr(self::str($patient, 'fname'), 0, 1) . mb_substr(self::str($patient, 'lname'), 0, 1));
        return ['url' => $url, 'initials' => $initials, 'size' => $size];
    }

    /**
     * Patient columns the card needs, plus the (validated) custom field columns.
     *
     * @return array<string, mixed>|null
     */
    private function patientRow(int $pid): ?array
    {
        $columns = ['fname', 'lname', 'preferred_name', 'DOB', 'email', 'phone_cell', 'phone_home', 'phone_biz'];
        $existing = self::patientColumns();
        foreach (['source_preferred_contact', 'source_best_time'] as $setting) {
            $column = $this->settings[$setting] ?? '';
            // Column names cannot be bound, so only names that really exist in patient_data are allowed.
            if (is_string($column) && $column !== '' && in_array($column, $existing, true) && !in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        $select = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
        $row = QueryUtils::querySingleRow('SELECT ' . $select . ' FROM `patient_data` WHERE `pid` = ?', [$pid], false);
        return is_array($row) ? $row : null;
    }

    /** @var list<string>|null */
    private static ?array $patientColumns = null;

    /**
     * patient_data column names, looked up once per request.
     *
     * @return list<string>
     */
    private static function patientColumns(): array
    {
        if (self::$patientColumns === null) {
            $columns = [];
            $rows = QueryUtils::fetchTableColumn(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                'COLUMN_NAME',
                ['patient_data']
            );
            foreach ($rows as $name) {
                if (is_string($name)) {
                    $columns[] = $name;
                }
            }
            self::$patientColumns = $columns;
        }
        return self::$patientColumns;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
