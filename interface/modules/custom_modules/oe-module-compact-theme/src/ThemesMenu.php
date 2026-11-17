<?php

/**
 * Places the theme picker inside Miscellaneous > Annie's Elegant Themes.
 *
 * The folder is found by its label (any Miscellaneous entry whose label
 * contains both "Annie" and "Theme", so "Annie's Elegant Themes" and
 * "Annie's Themes" both match). If it is a plain menu item rather than a
 * folder, it becomes a folder whose first entry is the original item, so
 * whatever it opened before still opens. If no such folder exists, one is
 * created at the end of Miscellaneous.
 *
 * Inside the folder: "Choose a Theme" (the picker), then one entry per
 * installed theme pack that opens the picker with that theme selected.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

final readonly class ThemesMenu
{
    /** menu_id of the stock top-level Miscellaneous menu. */
    public const MISC_MENU_ID = 'misimg';
    public const FOLDER_MENU_ID = 'cmtannie0';
    public const PICKER_MENU_ID = 'cmtthemes0';
    public const ITEM_MENU_ID_PREFIX = 'cmtthm-';
    public const PICKER_PATH = '/public/theme_picker.php';

    /**
     * @param list<ThemeManifest> $themes installed theme packs, in display order
     * @param string $pickerUrl web-root-relative URL of the picker page
     */
    public function __construct(
        private array $themes,
        private string $pickerUrl,
        private string $folderLabel,
        private string $pickerLabel
    ) {
    }

    /**
     * @param array<mixed> $menu the main menu (top-level entries)
     * @return array<mixed>
     */
    public function attach(array $menu): array
    {
        $misc = $this->findMisc($menu);
        if ($misc === null) {
            return $menu;
        }
        $children = $this->childrenOf($misc);
        $folder = $this->findFolder($children);
        if ($folder === null) {
            $folder = $this->entry($this->folderLabel, self::FOLDER_MENU_ID, null);
            $children[] = $folder;
            $misc->children = $children;
        } elseif (isset($folder->url)) {
            // a plain item: keep what it opened as the folder's first entry
            $original = clone $folder;
            $original->children = [];
            unset($folder->url, $folder->target);
            $folder->children = [$original];
        }

        $folderChildren = $this->childrenOf($folder);
        foreach ($this->themeEntries() as $entry) {
            $folderChildren[] = $entry;
        }
        $folder->children = $folderChildren;
        return $menu;
    }

    /**
     * True for a label like "Annie's Elegant Themes", "Annie’s Themes" or "annies themes".
     */
    public static function isAnnieThemesLabel(string $label): bool
    {
        $normalized = strtolower((string)preg_replace('/[^a-z]/i', '', $label));
        return str_contains($normalized, 'annie') && str_contains($normalized, 'theme');
    }

    /**
     * @return list<\stdClass>
     */
    private function themeEntries(): array
    {
        $entries = [$this->entry($this->pickerLabel, self::PICKER_MENU_ID, $this->pickerUrl)];
        foreach ($this->themes as $theme) {
            $entries[] = $this->entry(
                $theme->name,
                self::ITEM_MENU_ID_PREFIX . $theme->id,
                $this->pickerUrl . '?theme=' . rawurlencode($theme->id)
            );
        }
        return $entries;
    }

    /**
     * @param array<mixed> $menu
     */
    private function findMisc(array $menu): ?\stdClass
    {
        foreach ($menu as $item) {
            if ($item instanceof \stdClass && ($item->menu_id ?? null) === self::MISC_MENU_ID) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Looks through Miscellaneous and one level of its folders.
     *
     * @param list<mixed> $children
     */
    private function findFolder(array $children): ?\stdClass
    {
        foreach ($children as $child) {
            if ($child instanceof \stdClass && is_string($child->label ?? null) && self::isAnnieThemesLabel($child->label)) {
                return $child;
            }
        }
        foreach ($children as $child) {
            if (!$child instanceof \stdClass) {
                continue;
            }
            foreach ($this->childrenOf($child) as $grandchild) {
                if ($grandchild instanceof \stdClass && is_string($grandchild->label ?? null) && self::isAnnieThemesLabel($grandchild->label)) {
                    return $grandchild;
                }
            }
        }
        return null;
    }

    /**
     * @return list<mixed>
     */
    private function childrenOf(\stdClass $item): array
    {
        $children = $item->children ?? null;
        return is_array($children) ? array_values($children) : [];
    }

    private function entry(string $label, string $menuId, ?string $url): \stdClass
    {
        $entry = new \stdClass();
        $entry->label = $label;
        $entry->menu_id = $menuId;
        if ($url !== null) {
            $entry->target = 'msc';
            $entry->url = $url;
        }
        $entry->children = [];
        $entry->requirement = 0;
        $entry->acl_req = [];
        $entry->global_req = [];
        return $entry;
    }
}
