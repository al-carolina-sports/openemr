<?php

/**
 * Who may see ZeroDoc's UI, decided server-side.
 *
 * Pure logic over role names and ACL answers so it can be unit tested without
 * a database, and so the same object answers both the asset-injection gate and
 * the endpoint gate (oe-module-appt-hovercard/src/AccessPolicy.php pattern).
 *
 * The allowed-role list is a constant here for the scaffold. When ZeroDoc gains
 * a settings table it moves there, read through a SettingsRepository, so an
 * admin can change it without a deploy -- but the check must stay SERVER-SIDE.
 * Never gate on a role in JavaScript, and never send a field a role may not see
 * and hide it in the browser.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

final class AccessPolicy
{
    /**
     * ACL group titles allowed to load ZeroDoc's encounter UI.
     * An empty list means "no role restriction beyond the ACL check".
     *
     * @var list<string>
     */
    public const ROLES_UI = ['Administrators', 'Physicians', 'Clinicians'];

    /** @var list<string> */
    public const ROLES_DISPATCH = ['Administrators', 'Physicians', 'Clinicians', 'Front Office'];

    /**
     * @param list<string> $userRoles ACL group titles the user belongs to
     */
    public function __construct(
        private readonly array $userRoles,
        private readonly Access $access = new Access(),
    ) {
    }

    /** May this user load ZeroDoc's encounter UI at all? */
    public function canViewUi(): bool
    {
        return $this->access->can(AclObject::View) && $this->matches(self::ROLES_UI);
    }

    /** May this user write documentation into an encounter? */
    public function canDocument(): bool
    {
        return $this->canViewUi()
            && $this->access->can(AclObject::Document)
            && $this->access->canWriteEncounterNotes();
    }

    /** May this user attest and sign? */
    public function canSign(): bool
    {
        return $this->canDocument() && $this->access->can(AclObject::Sign);
    }

    /** May this user work the order queue? */
    public function canDispatch(): bool
    {
        return $this->access->can(AclObject::Dispatch) && $this->matches(self::ROLES_DISPATCH);
    }

    /**
     * @param list<string> $allowed
     */
    private function matches(array $allowed): bool
    {
        if ($allowed === []) {
            return true;
        }
        foreach ($this->userRoles as $role) {
            if (in_array($role, $allowed, true)) {
                return true;
            }
        }
        return false;
    }
}
