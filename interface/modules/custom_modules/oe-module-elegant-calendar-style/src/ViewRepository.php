<?php

/**
 * Custom Views storage and access rules.
 *
 * Everyone logged in sees shared views (owner NULL) and their own views.
 * Users create, edit and delete their own views; only admins (admin/super)
 * create or change shared views. All SQL is parameterized.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantCalendarStyle;

use OpenEMR\Common\Database\QueryUtils;

class ViewRepository
{
    public const MAX_NAME = 100;
    public const LAST_VIEW_SETTING = 'oe_module_ecs_last_view';

    public function __construct(private readonly int $userId, private readonly bool $isAdmin)
    {
    }

    /**
     * Active users that appear on the calendar, for the member checklist.
     *
     * @return list<array{username: string, name: string}>
     */
    public function calendarUsers(): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT `username`, `fname`, `lname` FROM `users` "
            . "WHERE `active` = 1 AND `calendar` = 1 AND `username` IS NOT NULL AND `username` <> '' "
            . "ORDER BY `lname`, `fname`"
        );
        $users = [];
        foreach ($rows as $row) {
            $users[] = [
                'username' => (string)$row['username'],
                'name' => trim((string)$row['fname'] . ' ' . (string)$row['lname']),
            ];
        }
        return $users;
    }

    /** @return list<array<string, mixed>> */
    public function visibleViews(): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT `id`, `name`, `owner_user_id` FROM `oe_module_ecs_views` '
            . 'WHERE `owner_user_id` IS NULL OR `owner_user_id` = ? '
            . 'ORDER BY (`owner_user_id` IS NOT NULL), `sort_order`, `name`, `id`',
            [$this->userId]
        );
        $members = [];
        if ($rows !== []) {
            $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach (QueryUtils::fetchRecords("SELECT `view_id`, `username` FROM `oe_module_ecs_view_members` WHERE `view_id` IN ($placeholders) ORDER BY `username`", $ids) as $m) {
                $members[(int)$m['view_id']][] = (string)$m['username'];
            }
        }
        $views = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $shared = $row['owner_user_id'] === null;
            $views[] = [
                'id' => $id,
                'name' => (string)$row['name'],
                'shared' => $shared,
                'members' => $members[$id] ?? [],
                'canEdit' => $shared ? $this->isAdmin : true,
            ];
        }
        return $views;
    }

    /** @param list<string> $members */
    public function create(string $name, bool $shared, array $members): int
    {
        $this->assertMayWriteShared($shared);
        $owner = $shared ? null : $this->userId;
        $order = (int)(QueryUtils::fetchSingleValue('SELECT COALESCE(MAX(`sort_order`), 0) + 10 AS n FROM `oe_module_ecs_views`', 'n') ?? 10);
        return (int)QueryUtils::inTransaction(function () use ($name, $owner, $order, $members): int {
            $id = (int)QueryUtils::sqlInsert(
                'INSERT INTO `oe_module_ecs_views` (`name`, `owner_user_id`, `sort_order`) VALUES (?, ?, ?)',
                [$name, $owner, $order]
            );
            $this->replaceMembers($id, $members);
            return $id;
        });
    }

    /** @param list<string> $members */
    public function update(int $id, string $name, bool $shared, array $members): void
    {
        $this->assertMayEdit($id);
        $this->assertMayWriteShared($shared);
        $owner = $shared ? null : $this->userId;
        QueryUtils::inTransaction(function () use ($id, $name, $owner, $members): void {
            QueryUtils::sqlStatementThrowException(
                'UPDATE `oe_module_ecs_views` SET `name` = ?, `owner_user_id` = ? WHERE `id` = ?',
                [$name, $owner, $id]
            );
            $this->replaceMembers($id, $members);
        });
    }

    public function delete(int $id): void
    {
        $this->assertMayEdit($id);
        QueryUtils::inTransaction(function () use ($id): void {
            QueryUtils::sqlStatementThrowException('DELETE FROM `oe_module_ecs_view_members` WHERE `view_id` = ?', [$id]);
            QueryUtils::sqlStatementThrowException('DELETE FROM `oe_module_ecs_views` WHERE `id` = ?', [$id]);
        });
    }

    public function lastViewId(): ?int
    {
        $value = QueryUtils::fetchSingleValue(
            'SELECT `setting_value` FROM `user_settings` WHERE `setting_user` = ? AND `setting_label` = ?',
            'setting_value',
            [$this->userId, self::LAST_VIEW_SETTING]
        );
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    public function rememberView(?int $id): void
    {
        if ($id !== null) {
            $this->assertVisible($id);
        }
        QueryUtils::sqlStatementThrowException(
            'REPLACE INTO `user_settings` (`setting_user`, `setting_label`, `setting_value`) VALUES (?, ?, ?)',
            [$this->userId, self::LAST_VIEW_SETTING, $id === null ? '' : (string)$id]
        );
    }

    /**
     * Keep only usernames of active calendar users, de-duplicated.
     *
     * @param mixed $members
     * @return list<string>
     */
    public function cleanMembers(mixed $members): array
    {
        if (!is_array($members)) {
            throw new \InvalidArgumentException('members');
        }
        $allowed = array_column($this->calendarUsers(), 'username');
        $clean = [];
        foreach ($members as $m) {
            if (is_string($m) && in_array($m, $allowed, true) && !in_array($m, $clean, true)) {
                $clean[] = $m;
            }
        }
        if ($clean === []) {
            throw new \InvalidArgumentException('members');
        }
        return $clean;
    }

    public static function cleanName(mixed $name): string
    {
        $name = is_string($name) ? trim(preg_replace('/\s+/u', ' ', $name) ?? '') : '';
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException('name');
        }
        return $name;
    }

    /** @param list<string> $members */
    private function replaceMembers(int $id, array $members): void
    {
        QueryUtils::sqlStatementThrowException('DELETE FROM `oe_module_ecs_view_members` WHERE `view_id` = ?', [$id]);
        foreach ($members as $username) {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `oe_module_ecs_view_members` (`view_id`, `username`) VALUES (?, ?)',
                [$id, $username]
            );
        }
    }

    /** @return array{id: int, owner_user_id: ?int} */
    private function assertVisible(int $id): array
    {
        $row = QueryUtils::querySingleRow(
            'SELECT `id`, `owner_user_id` FROM `oe_module_ecs_views` WHERE `id` = ? AND (`owner_user_id` IS NULL OR `owner_user_id` = ?)',
            [$id, $this->userId]
        );
        if (!is_array($row)) {
            throw new NotFoundException();
        }
        return ['id' => (int)$row['id'], 'owner_user_id' => $row['owner_user_id'] === null ? null : (int)$row['owner_user_id']];
    }

    private function assertMayEdit(int $id): void
    {
        $row = $this->assertVisible($id);
        if ($row['owner_user_id'] === null && !$this->isAdmin) {
            throw new ForbiddenException();
        }
    }

    private function assertMayWriteShared(bool $shared): void
    {
        if ($shared && !$this->isAdmin) {
            throw new ForbiddenException();
        }
    }
}
