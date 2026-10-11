<?php

/**
 * Who may see the card, and who may see DOB and contact details.
 * Pure logic over role names so it can be unit tested without a database.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ApptHoverCard;

final class AccessPolicy
{
    /**
     * @param array<string, mixed> $settings
     * @param list<string> $userRoles ACL group titles the user belongs to
     */
    public function __construct(private readonly array $settings, private readonly array $userRoles)
    {
    }

    public function canViewCard(): bool
    {
        return (bool)($this->settings['enabled'] ?? false) && $this->matches($this->settings['roles_card'] ?? []);
    }

    public function canViewSensitive(): bool
    {
        return $this->canViewCard() && $this->matches($this->settings['roles_contact'] ?? []);
    }

    /**
     * Last four digits only, e.g. "(***) ***-1234".
     */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        return '(***) ***-' . substr($digits, -4);
    }

    public static function ageOn(?string $dob, \DateTimeImmutable $today): ?int
    {
        if ($dob === null || $dob === '' || $dob === '0000-00-00') {
            return null;
        }
        try {
            $born = new \DateTimeImmutable($dob);
        } catch (\Throwable) {
            return null;
        }
        return $born > $today ? null : $born->diff($today)->y;
    }

    private function matches(mixed $allowed): bool
    {
        if (!is_array($allowed)) {
            return false;
        }
        if (in_array(Settings::ALL_ROLES, $allowed, true)) {
            return true;
        }
        return array_intersect($allowed, $this->userRoles) !== [];
    }
}
