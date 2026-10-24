<?php

/**
 * One theme pack, parsed from public/themes/<id>/theme.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

final readonly class ThemeManifest
{
    /** Folder names double as URL path segments, so keep them to a safe alphabet. */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    /** Preview swatch keys shown on the theme picker; missing ones use the Paper colors. */
    public const PREVIEW_DEFAULTS = [
        'bg' => '#f4f5f7',
        'surface' => '#ffffff',
        'nav' => '#ffffff',
        'border' => '#e3e6ea',
        'text' => '#1b1f24',
        'muted' => '#5b6470',
        'accent' => '#2563eb',
        'rail' => '#111827',
    ];

    private function __construct(
        public string $id,
        public string $name,
        public string $description,
        public ColorScheme $colorScheme,
        public Layout $layout,
        public int $order,
        public string $version,
        /** @var array<string, string> Hex colors keyed like PREVIEW_DEFAULTS */
        public array $preview
    ) {
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Returns null for anything malformed, so one broken pack can't break every page.
     *
     * @param array<mixed> $data Decoded theme.json
     */
    public static function fromArray(array $data, string $folderName): ?self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        if (!is_string($id) || $id !== $folderName || !self::isValidId($id)) {
            return null;
        }
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 60) {
            return null;
        }

        $description = $data['description'] ?? '';
        $base = $data['base'] ?? ColorScheme::Light->value;
        $layout = $data['layout'] ?? Layout::Top->value;
        $order = $data['order'] ?? 100;
        $version = $data['version'] ?? '1.0.0';
        if (!is_string($description) || !is_string($base) || !is_string($layout) || !is_int($order) || !is_string($version)) {
            return null;
        }
        $colorScheme = ColorScheme::tryFrom($base);
        $layoutCase = Layout::tryFrom($layout);
        if ($colorScheme === null || $layoutCase === null || preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $version) !== 1) {
            return null;
        }

        return new self(
            $id,
            trim($name),
            mb_substr(trim($description), 0, 200),
            $colorScheme,
            $layoutCase,
            $order,
            $version,
            self::parsePreview($data['preview'] ?? [])
        );
    }

    /**
     * Keeps only known keys with #rrggbb values; anything else falls back to the defaults.
     *
     * @return array<string, string>
     */
    private static function parsePreview(mixed $preview): array
    {
        $colors = self::PREVIEW_DEFAULTS;
        if (!is_array($preview)) {
            return $colors;
        }
        foreach (array_keys($colors) as $key) {
            $value = $preview[$key] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $colors[$key] = strtolower($value);
            }
        }
        return $colors;
    }
}
