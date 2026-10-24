<?php

/**
 * The Compact Theme settings for the current user, parsed from globals.
 *
 * Every setting is user-specific: the Admin > Globals value is the practice
 * default and each user can override it under their own User Settings.
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
    public const SECTION = 'Compact Theme';

    public const KEY_THEME = 'cmt_theme';
    public const KEY_DENSITY = 'cmt_density';
    public const KEY_MENU_STYLE = 'cmt_menu_style';
    public const KEY_ACCENT = 'cmt_accent';

    public const DEFAULT_THEME = Theme::Paper;
    public const DEFAULT_DENSITY = Density::Compact;
    public const DEFAULT_MENU_STYLE = MenuStyle::IconsAndLabels;

    /**
     * @param ?string $accent Six hex digits without '#', or null for the theme's own accent
     */
    public function __construct(
        public Theme $theme,
        public Density $density,
        public MenuStyle $menuStyle,
        public ?string $accent
    ) {
    }

    public static function fromGlobals(OEGlobalsBag $globals): self
    {
        return new self(
            Theme::tryFrom($globals->getString(self::KEY_THEME)) ?? self::DEFAULT_THEME,
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
        return $this->theme !== Theme::Off;
    }

    /**
     * Values handed to compact-theme.js in its query string.
     *
     * @return array<string, string>
     */
    public function clientParameters(): array
    {
        $parameters = [
            't' => $this->theme->value,
            'd' => $this->density->value,
            'm' => $this->menuStyle->value,
        ];
        if ($this->accent !== null) {
            $parameters['a'] = $this->accent;
        }
        return $parameters;
    }

    public static function registerGlobals(GlobalsService $service): void
    {
        // adds the section to Admin > Globals and to every user's User Settings
        $service->addUserSpecificTab(self::SECTION);

        $service->appendToSection(self::SECTION, self::KEY_THEME, new GlobalSetting(
            xl('Theme'),
            [
                Theme::Slate->value => xl('Slate Night (dark, dense) - use with General Theme: Dark'),
                Theme::Paper->value => xl('Paper Compact (light, hairline)'),
                Theme::Rail->value => xl('Graphite Rail (side icon menu, light)'),
                Theme::Off->value => xl('Off (stock OpenEMR look)'),
            ],
            self::DEFAULT_THEME->value,
            xl('Compact look applied on top of the General Theme. Reload the page after changing.'),
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
            xl('How the top-level main menu items are shown. Logout and login after changing.'),
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
