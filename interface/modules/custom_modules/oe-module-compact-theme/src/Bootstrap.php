<?php

/**
 * Compact Theme module bootstrap.
 *
 * Adds a compact, modern look on top of OpenEMR's Light or Dark base theme
 * without touching core files:
 *  - one stylesheet + one small script added through the header filter events
 *  - SVG icons on the top-level main menu through the menu update event
 *  - per-user settings (theme, density, accent, menu style) in Admin > Globals
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
        private readonly string $webRoot
    ) {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(GlobalsInitializedEvent::EVENT_HANDLE, $this->addGlobalSettings(...));

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

    public function addGlobalSettings(GlobalsInitializedEvent $event): void
    {
        ThemeSettings::registerGlobals($event->getGlobalsService());
    }

    public function addStyles(StyleFilterEvent $event): void
    {
        if ($this->isExcludedPage($event->getPageName())) {
            return;
        }
        $styles = $event->getStyles();
        $styles[] = $this->assetUrl('css/compact-theme.css');
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
        // The script reads its settings from its own query string. Every value is
        // an enum value or a bare 6-digit hex string, so nothing here needs escaping.
        $scripts = $event->getScripts();
        $scripts[] = $this->assetUrl('js/compact-theme.js') . '?' . http_build_query($this->settings->clientParameters());
        $event->setScripts($scripts);
    }

    public function addMenuIcons(MenuEvent $event): MenuEvent
    {
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
            if ($this->settings->theme === Theme::Rail && $icon === 'misc') {
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

    private function assetUrl(string $relative): string
    {
        return $this->webRoot . self::MODULE_INSTALLATION_PATH . '/public/assets/' . $relative;
    }
}
