<?php

/**
 * Finds the theme packs installed under public/themes/.
 *
 * A theme pack is a folder holding theme.json and theme.css. Adding, updating
 * or removing a pack is a file copy: no code change, no database change.
 * Folders that are missing either file, or whose theme.json is malformed, are
 * skipped.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

final readonly class ThemeRegistry
{
    public function __construct(private string $themesDirectory)
    {
    }

    public function find(string $id): ?ThemeManifest
    {
        if (!ThemeManifest::isValidId($id)) {
            return null;
        }
        $folder = $this->themesDirectory . DIRECTORY_SEPARATOR . $id;
        $jsonPath = $folder . DIRECTORY_SEPARATOR . 'theme.json';
        if (!is_file($jsonPath) || !is_file($folder . DIRECTORY_SEPARATOR . 'theme.css')) {
            return null;
        }
        $json = file_get_contents($jsonPath);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // a hand-edited pack with a typo is skipped rather than breaking every page
            return null;
        }
        return is_array($data) ? ThemeManifest::fromArray($data, $id) : null;
    }

    /**
     * @return list<ThemeManifest> Sorted by "order", then name
     */
    public function all(): array
    {
        $entries = is_dir($this->themesDirectory) ? scandir($this->themesDirectory) : false;
        if ($entries === false) {
            return [];
        }
        $themes = [];
        foreach ($entries as $entry) {
            $manifest = $this->find($entry);
            if ($manifest !== null) {
                $themes[] = $manifest;
            }
        }
        usort(
            $themes,
            static fn(ThemeManifest $a, ThemeManifest $b): int => [$a->order, $a->name] <=> [$b->order, $b->name]
        );
        return $themes;
    }
}
