<?php

/**
 * Annie's Compact Themes bootstrap.
 *
 * Adds a compact, modern look on top of OpenEMR's Light or Dark General Theme
 * without touching core files:
 *  - the shared base stylesheet, then the selected theme pack's theme.css,
 *    and one small settings script, all through the header filter events
 *  - a Miscellaneous > Themes menu item (the theme picker page) and SVG icons
 *    on the top-level main menu, through the menu update event
 *  - per-user settings (theme, density, menu style, accent) in Admin > Config
 *    and in each user's own User Settings
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-compact-theme';

    /**
     * Pages that keep the stock look (patient portal, login, setup and upgrade screens).
     */
    private const EXCLUDED_PATH_FRAGMENTS = [
        '/portal/',
        '/interface/login/',
        '/setup.php',
        '/sql_upgrade.php',
    ];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ThemeSettings $settings,
        private readonly ThemeRegistry $registry,
        private readonly string $webRoot
    ) {
    }

    /** menu_id of the stock top-level Miscellaneous menu. */
    private const MISC_MENU_ID = 'misimg';
    private const PICKER_MENU_ID = 'cmtthemes0';

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(GlobalsInitializedEvent::EVENT_HANDLE, $this->addGlobalSettings(...));
        // always added, so a user who switched the theme off can switch it back on
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addPickerMenuItem(...));

        if (!$this->settings->isActive()) {
            return;
        }

        $this->eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, $this->addStyles(...));
        $this->eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, $this->addScripts(...));
        if ($this->settings->menuStyle !== MenuStyle::LabelsOnly) {
            // low priority so items added by other modules' listeners get icons too
            $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuIcons(...), -100);
        }
    }

    /**
     * Adds Miscellaneous > Themes, the theme picker page (open to every user).
     */
    public function addPickerMenuItem(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();
        foreach ($menu as $item) {
            if (!$item instanceof \stdClass || ($item->menu_id ?? null) !== self::MISC_MENU_ID) {
                continue;
            }
            $children = is_array($item->children ?? null) ? $item->children : [];
            $picker = new \stdClass();
            $picker->label = xl('Themes');
            $picker->menu_id = self::PICKER_MENU_ID;
            $picker->target = 'msc';
            $picker->url = self::MODULE_INSTALLATION_PATH . '/public/theme_picker.php';
            $picker->children = [];
            $picker->requirement = 0;
            $picker->acl_req = [];
            $picker->global_req = [];
            $children[] = $picker;
            $item->children = $children;
            break;
        }
        $event->setMenu($menu);
        return $event;
    }

    public function addGlobalSettings(GlobalsInitializedEvent $event): void
    {
        ThemeSettings::registerGlobals($event->getGlobalsService(), $this->registry);
    }

    public function addStyles(StyleFilterEvent $event): void
    {
        $theme = $this->settings->theme;
        if ($theme === null || $this->isExcludedPage($event->getPageName())) {
            return;
        }
        $styles = $event->getStyles();
        // base first, then the theme pack, so the pack's tokens and rules win
        $styles[] = $this->moduleUrl('/public/assets/css/compact-base.css') . '?mv=' . rawurlencode(Config::VERSION);
        $styles[] = $this->moduleUrl('/public/themes/' . $theme->id . '/theme.css')
            . '?mv=' . rawurlencode(Config::VERSION . '-' . $theme->version);
        $event->setStyles($styles);
    }

    public function addScripts(ScriptFilterEvent $event): void
    {
        $scriptName = $event->getContextArgument(ScriptFilterEvent::CONTEXT_ARGUMENT_SCRIPT_NAME);
        if (!is_string($scriptName)) {
            $scriptName = $event->getPageName();
        }
        if ($this->isExcludedPage($scriptName)) {
            return;
        }
        // The script reads its settings from its own query string. Every value is a
        // theme id, an enum value or 6 hex digits, so nothing here needs escaping.
        $query = http_build_query($this->settings->clientParameters() + ['mv' => Config::VERSION]);
        $scripts = $event->getScripts();
        $scripts[] = $this->moduleUrl('/public/assets/js/compact-theme.js') . '?' . $query;
        $event->setScripts($scripts);
    }

    public function addMenuIcons(MenuEvent $event): MenuEvent
    {
        $isRail = $this->settings->theme?->layout === Layout::Rail;
        $menu = $event->getMenu();
        foreach ($menu as $item) {
            if (!$item instanceof \stdClass) {
                continue;
            }
            $icon = MenuIconMap::iconFor($item);
            if ($icon !== null) {
                $item->icon = 'cmt-ico cmt-ico-' . $icon;
            }
            // the rail tile is 64px wide; the one stock label that cannot fit gets its short form
            if ($isRail && $icon === 'misc') {
                $item->label = xl('Misc');
            }
        }
        $event->setMenu($menu);
        return $event;
    }

    private function isExcludedPage(string $scriptName): bool
    {
        foreach (self::EXCLUDED_PATH_FRAGMENTS as $fragment) {
            if (str_contains($scriptName, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function moduleUrl(string $relative): string
    {
        return $this->webRoot . self::MODULE_INSTALLATION_PATH . $relative;
    }
}
