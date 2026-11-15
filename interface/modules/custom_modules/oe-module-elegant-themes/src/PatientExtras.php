<?php

/**
 * Contact card + recent patients data for Annie's Elegant Themes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantThemes;

use OpenEMR\Common\Database\QueryUtils;

class PatientExtras
{
    public const RECENT_TABLE = 'oe_module_aet_recent';
    public const RECENT_MAX = 8;

    /** @return array<string, mixed>|null */
    public function contact(int $pid): ?array
    {
        $r = QueryUtils::querySingleRow(
            'SELECT pid, pubpid, fname, mname, lname, preferred_name, DOB, phone_home, phone_cell, phone_biz,'
            . ' email, email_direct, street, street_line_2, city, state, postal_code, country_code,'
            . ' hipaa_allowsms, hipaa_voice, hipaa_mail, hipaa_allowemail, hipaa_notice, hipaa_message,'
            . ' contact_relationship, phone_contact'
            . ' FROM patient_data WHERE pid = ?',
            [$pid]
        );
        if (!is_array($r)) {
            return null;
        }
        $s = static fn($k): string => trim((string)($r[$k] ?? ''));
        $addr2 = trim(implode(', ', array_filter([$s('city'), trim($s('state') . ' ' . $s('postal_code'))])));
        return [
            'pid' => (int)$r['pid'],
            'pubpid' => $s('pubpid'),
            'name' => trim($s('fname') . ' ' . $s('lname')),
            'preferred_name' => $s('preferred_name'),
            'dob' => $s('DOB'),
            'phones' => array_values(array_filter([
                ['label' => xl('Home'), 'value' => $s('phone_home')],
                ['label' => xl('Cell'), 'value' => $s('phone_cell')],
                ['label' => xl('Work'), 'value' => $s('phone_biz')],
            ], static fn($p) => $p['value'] !== '')),
            'email' => $s('email') !== '' ? $s('email') : $s('email_direct'),
            'address' => array_values(array_filter([$s('street'), $s('street_line_2'), $addr2])),
            'hipaa' => array_values(array_filter([
                ['label' => xl('Allow SMS'), 'value' => $s('hipaa_allowsms')],
                ['label' => xl('Allow Voice'), 'value' => $s('hipaa_voice')],
                ['label' => xl('Allow Mail'), 'value' => $s('hipaa_mail')],
                ['label' => xl('Allow Email'), 'value' => $s('hipaa_allowemail')],
                ['label' => xl('Leave message with'), 'value' => $s('hipaa_message')],
            ], static fn($p) => $p['value'] !== '')),
            'emergency' => ['name' => $s('contact_relationship'), 'phone' => $s('phone_contact')],
        ];
    }

    public function touch(int $userId, int $pid): bool
    {
        if (!QueryUtils::querySingleRow('SELECT pid FROM patient_data WHERE pid = ?', [$pid])) {
            return false;
        }
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `' . self::RECENT_TABLE . '` (`user_id`, `pid`, `last_seen`) VALUES (?, ?, NOW(6))'
            . ' ON DUPLICATE KEY UPDATE `last_seen` = NOW(6)',
            [$userId, $pid]
        );
        $old = QueryUtils::fetchTableColumn(
            'SELECT `pid` FROM `' . self::RECENT_TABLE . '` WHERE `user_id` = ? ORDER BY `last_seen` DESC LIMIT 1000 OFFSET ' . self::RECENT_MAX,
            'pid',
            [$userId]
        );
        foreach ($old as $p) {
            QueryUtils::sqlStatementThrowException('DELETE FROM `' . self::RECENT_TABLE . '` WHERE `user_id` = ? AND `pid` = ?', [$userId, (int)$p]);
        }
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $userId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT r.pid, r.last_seen, p.fname, p.lname, p.DOB, p.pubpid FROM `' . self::RECENT_TABLE . '` r'
            . ' JOIN patient_data p ON p.pid = r.pid WHERE r.user_id = ? ORDER BY r.last_seen DESC LIMIT ' . self::RECENT_MAX,
            [$userId]
        );
        return array_map(static fn($r) => [
            'pid' => (int)$r['pid'],
            'name' => trim(($r['lname'] ?? '') . ', ' . ($r['fname'] ?? ''), ', '),
            'dob' => (string)($r['DOB'] ?? ''),
            'pubpid' => (string)($r['pubpid'] ?? ''),
        ], $rows);
    }
}
