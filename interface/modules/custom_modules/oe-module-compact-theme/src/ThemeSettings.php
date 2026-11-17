<?php

/**
 * The Compact Themes settings for the current user, parsed from globals.
 *
 * Every setting is user-specific: the Admin > Config value is the practice
 * default and each user can override it under their own User Settings. The
 * stored values are plain strings in `globals` / `user_settings`, so they
 * survive OpenEMR upgrades and module updates unchanged.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Services\Globals\GlobalSetting;
use OpenEMR\Services\Globals\GlobalsService;

final readonly class ThemeSettings
{
    public const SECTION = 'Compact Themes';

    public const KEY_THEME = 'cmt_theme';
    public const KEY_DENSITY = 'cmt_density';
    public const KEY_MENU_STYLE = 'cmt_menu_style';
    public const KEY_ACCENT = 'cmt_accent';

    /** Stored theme value that turns the module's styling off for a user. */
    public const THEME_OFF = 'off';
    /** Used when the stored theme pack has been removed or is invalid. */
    public const DEFAULT_THEME_ID = 'paper';
    public const DEFAULT_DENSITY = Density::Compact;
    public const DEFAULT_MENU_STYLE = MenuStyle::IconsAndLabels;

    /**
     * @param ?ThemeManifest $theme  Null when the user has the theme set to Off
     * @param ?string        $accent Six hex digits without '#', or null for the theme's own accent
     */
    public function __construct(
        public ?ThemeManifest $theme,
        public Density $density,
        public MenuStyle $menuStyle,
        public ?string $accent
    ) {
    }

    public static function fromGlobals(OEGlobalsBag $globals, ThemeRegistry $registry): self
    {
        $themeId = $globals->getString(self::KEY_THEME, self::DEFAULT_THEME_ID);
        $theme = $themeId === self::THEME_OFF
            ? null
            : ($registry->find($themeId) ?? $registry->find(self::DEFAULT_THEME_ID));

        return new self(
            $theme,
            Density::tryFrom($globals->getString(self::KEY_DENSITY)) ?? self::DEFAULT_DENSITY,
            MenuStyle::tryFrom($globals->getString(self::KEY_MENU_STYLE)) ?? self::DEFAULT_MENU_STYLE,
            self::parseAccent($globals->getString(self::KEY_ACCENT))
        );
    }

    public static function parseAccent(string $raw): ?string
    {
        if (preg_match('/^#?([0-9a-fA-F]{6})$/', trim($raw), $matches) !== 1) {
            return null;
        }
        return strtolower($matches[1]);
    }

    public function isActive(): bool
    {
        return $this->theme !== null;
    }

    /**
     * Values handed to compact-theme.js in its query string. Every value is a
     * theme id (ThemeManifest::ID_PATTERN), an enum value or 6 hex digits.
     *
     * @return array<string, string>
     */
    public function clientParameters(): array
    {
        if ($this->theme === null) {
            return [];
        }
        $parameters = [
            't' => $this->theme->id,
            'l' => $this->theme->layout->value,
            'd' => $this->density->value,
            'm' => $this->menuStyle->value,
        ];
        if ($this->accent !== null) {
            $parameters['a'] = $this->accent;
        }
        return $parameters;
    }

    public static function registerGlobals(GlobalsService $service, ThemeRegistry $registry): void
    {
        // adds the section to Admin > Config and to every user's User Settings
        $service->addUserSpecificTab(self::SECTION);

        $themeOptions = [];
        foreach ($registry->all() as $theme) {
            $label = $theme->name;
            if ($theme->description !== '') {
                $label .= ' - ' . $theme->description;
            }
            $themeOptions[$theme->id] = $label;
        }
        $themeOptions[self::THEME_OFF] = xl('Off (stock OpenEMR look)');

        $service->appendToSection(self::SECTION, self::KEY_THEME, new GlobalSetting(
            xl('Theme'),
            $themeOptions,
            self::DEFAULT_THEME_ID,
            xl('Theme packs found in the module\'s public/themes folder, applied on top of the General Theme. Reload the page after changing.'),
            true
        ));

        $service->appendToSection(self::SECTION, self::KEY_DENSITY, new GlobalSetting(
            xl('Density'),
            [
                Density::Compact->value => xl('Compact'),
                Density::Cozy->value => xl('Cozy'),
            ],
            self::DEFAULT_DENSITY->value,
            xl('Row and card spacing for lists, tables and dashboard cards.'),
            true
        ));

        $service->appendToSection(self::SECTION, self::KEY_MENU_STYLE, new GlobalSetting(
            xl('Main Menu Style'),
            [
                MenuStyle::IconsAndLabels->value => xl('Icons and labels'),
                MenuStyle::IconsOnly->value => xl('Icons only (labels as tooltips)'),
                MenuStyle::LabelsOnly->value => xl('Labels only'),
            ],
            self::DEFAULT_MENU_STYLE->value,
            xl('How the top-level main menu items are shown. Log out and back in after changing.'),
            true
        ));

        $service->appendToSection(self::SECTION, self::KEY_ACCENT, new GlobalSetting(
            xl('Accent Color'),
            GlobalSetting::DATA_TYPE_COLOR_CODE,
            '',
            xl('Optional accent color for buttons, links and the active tab. Leave blank for the theme default.'),
            true
        ));
    }
}
