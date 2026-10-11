<?php

/**
 * Module settings: defaults and input normalisation. Pure PHP, no database,
 * so it can be unit tested in isolation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ApptHoverCard;

final class Settings
{
    /** Wildcard role entry meaning "every role". */
    public const ALL_ROLES = '*';

    public const VIEWS = ['day', 'week', 'month'];
    public const PHOTO_SIZES = ['small' => 64, 'medium' => 100, 'large' => 140];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $fields = [];
        $order = 0;
        foreach (FieldCatalog::all() as $key => $def) {
            $fields[$key] = ['show' => $def['show'], 'order' => $order++, 'label' => $def['label']];
        }

        return [
            'enabled' => true,
            'views' => ['day', 'week'],
            'hover_delay_ms' => 400,
            'hide_delay_ms' => 200,
            'click_mode' => false,
            'card_width' => 360,
            'fields' => $fields,
            'source_preferred_contact' => '',
            'source_best_time' => '',
            'photo_show' => true,
            'photo_category' => 'Patient Photograph',
            'photo_size' => 'medium',
            'photo_missing' => 'initials',
            'roles_card' => [self::ALL_ROLES],
            'roles_contact' => [self::ALL_ROLES],
            'mask_phone' => false,
            'audit_views' => true,
        ];
    }

    /**
     * Turn untrusted form input into a complete, valid settings array.
     * Anything missing or out of range falls back to the default.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $out = self::defaults();

        $out['enabled'] = self::bool($input, 'enabled', true);
        $out['click_mode'] = self::bool($input, 'click_mode', false);
        $out['mask_phone'] = self::bool($input, 'mask_phone', false);
        $out['audit_views'] = self::bool($input, 'audit_views', true);
        $out['photo_show'] = self::bool($input, 'photo_show', true);

        $out['hover_delay_ms'] = self::int($input, 'hover_delay_ms', 400, 0, 5000);
        $out['hide_delay_ms'] = self::int($input, 'hide_delay_ms', 200, 0, 5000);
        $out['card_width'] = self::int($input, 'card_width', 360, 240, 640);

        $views = $input['views'] ?? [];
        $out['views'] = is_array($views)
            ? array_values(array_intersect(self::VIEWS, array_filter($views, 'is_string')))
            : ['day', 'week'];

        $size = $input['photo_size'] ?? 'medium';
        $out['photo_size'] = is_string($size) && isset(self::PHOTO_SIZES[$size]) ? $size : 'medium';

        $missing = $input['photo_missing'] ?? 'initials';
        $out['photo_missing'] = $missing === 'hide' ? 'hide' : 'initials';

        $category = $input['photo_category'] ?? '';
        $out['photo_category'] = is_string($category) && trim($category) !== ''
            ? mb_substr(trim($category), 0, 255)
            : 'Patient Photograph';

        foreach (['source_preferred_contact', 'source_best_time'] as $key) {
            $value = $input[$key] ?? '';
            // A column name only: it is checked against patient_data's real columns before use.
            $out[$key] = is_string($value) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) === 1 ? $value : '';
        }

        $out['roles_card'] = self::roles($input['roles_card'] ?? null);
        $out['roles_contact'] = self::roles($input['roles_contact'] ?? null);

        $rawFields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $defaults = self::defaults();
        /** @var array<string, array{show: bool, order: int, label: string}> $fields */
        $fields = $defaults['fields'];
        foreach ($fields as $key => $default) {
            $row = $rawFields[$key] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $label = $row['label'] ?? $default['label'];
            $label = is_string($label) ? trim(mb_substr($label, 0, 60)) : $default['label'];
            $fields[$key] = [
                'show' => self::bool($row, 'show', $default['show']),
                'order' => self::int($row, 'order', $default['order'], 0, 999),
                'label' => $label !== '' ? $label : $default['label'],
            ];
        }
        $out['fields'] = $fields;

        return $out;
    }

    /**
     * @param array<array-key, mixed> $input
     */
    private static function bool(array $input, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $input)) {
            // An unchecked HTML checkbox is simply absent from the POST.
            return false;
        }
        $value = $input[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) || is_int($value)) {
            return in_array((string)$value, ['1', 'true', 'on', 'yes'], true);
        }
        return $default;
    }

    /**
     * @param array<array-key, mixed> $input
     */
    private static function int(array $input, string $key, int $default, int $min, int $max): int
    {
        $value = $input[$key] ?? null;
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return max($min, min($max, (int)$value));
        }
        return $default;
    }

    /**
     * @return list<string>
     */
    private static function roles(mixed $value): array
    {
        if (!is_array($value)) {
            return [self::ALL_ROLES];
        }
        $roles = [];
        foreach ($value as $role) {
            if (is_string($role) && $role !== '') {
                $roles[] = mb_substr($role, 0, 100);
            }
        }
        $roles = array_values(array_unique($roles));
        return in_array(self::ALL_ROLES, $roles, true) ? [self::ALL_ROLES] : $roles;
    }
}
