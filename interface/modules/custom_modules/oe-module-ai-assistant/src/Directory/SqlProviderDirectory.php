<?php

/**
 * ProviderDirectory over users: authorized (can be a provider), active,
 * real login accounts only (address book entries have no username).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Directory;

use OpenEMR\Modules\AiAssistant\Database\Database;
use OpenEMR\Modules\AiAssistant\Database\Row;

final readonly class SqlProviderDirectory implements ProviderDirectory
{
    private const SELECT = 'SELECT u.`id`, u.`fname`, u.`lname`, u.`title`, u.`specialty`, u.`facility_id`, f.`name` AS `facility_name`'
        . ' FROM `users` u LEFT JOIN `facility` f ON f.`id` = u.`facility_id`'
        . " WHERE u.`authorized` = 1 AND u.`active` = 1 AND u.`username` <> ''";

    public function __construct(private Database $db)
    {
    }

    public function active(?string $nameContains): array
    {
        $sql = self::SELECT;
        $binds = [];
        if ($nameContains !== null) {
            $sql .= " AND CONCAT_WS(' ', u.`fname`, u.`lname`) LIKE ?";
            $binds[] = '%' . addcslashes($nameContains, '%_\\') . '%';
        }
        $rows = $this->db->fetchAll($sql . ' ORDER BY u.`lname`, u.`fname` LIMIT 100', $binds);
        return array_map(self::provider(...), $rows);
    }

    public function find(int $id): ?Provider
    {
        $row = $this->db->fetchOne(self::SELECT . ' AND u.`id` = ?', [$id]);
        return $row === null ? null : self::provider($row);
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private static function provider(array $row): Provider
    {
        $facility = Row::intOrNull($row, 'facility_id');
        return new Provider(
            id: Row::int($row, 'id'),
            firstName: Row::str($row, 'fname'),
            lastName: Row::str($row, 'lname'),
            title: Row::str($row, 'title'),
            specialty: Row::str($row, 'specialty'),
            facilityId: $facility !== null && $facility > 0 ? $facility : null,
            facilityName: Row::str($row, 'facility_name'),
        );
    }
}
