<?php

/**
 * Annie's Elegant Themes bootstrap.
 *
 * Everything goes through module events; no core file is edited:
 *  - at bootstrap (after globals.php has resolved the user's css_header) the
 *    runtime css_header / compact_header / theme_tabs_layout values are pointed
 *    at a stock base theme (light or solar) for this request only;
 *  - StyleFilterEvent adds the theme CSS to every page that uses Header::setupHeader();
 *  - ScriptFilterEvent adds the menu-icon script and the v1.3.0 contact card /
 *    recent patients script (public/ajax.php endpoint);
 *  - MenuEvent adds "Elegant Themes" under Miscellaneous (everyone) and Admin.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantThemes;

use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_DIR = 'oe-module-elegant-themes';
    public const MODULE_PATH = '/interface/modules/custom_modules/' . self::MODULE_DIR;
    private const MAIN_PAGE = 'main.php';

    private string $theme = Themes::OFF;

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItems(...));

        $userId = $this->currentUserId();
        if ($userId <= 0 || !empty($GLOBALS['ignoreAuth_onsite_portal'])) {
            return;
        }
        try {
            $this->theme = (new ThemeRepository())->resolve($userId);
        } catch (\Throwable $e) {
            error_log('oe-module-elegant-themes: ' . $e::class . ' resolving theme');
            return;
        }
        if ($this->theme === Themes::OFF) {
            return;
        }
        $this->applyBaseTheme(Themes::ALL[$this->theme]);
        $this->eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, $this->addStyles(...));
        $this->eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, $this->addScripts(...));
    }

    public function addStyles(StyleFilterEvent $event): StyleFilterEvent
    {
        $styles = $event->getStyles();
        $styles[] = $this->asset('/public/assets/elegant-base.css');
        $styles[] = $this->asset('/public/assets/themes/' . $this->theme . '.css');
        $styles[] = $this->asset('/public/assets/aet-features.css');
        $styles[] = $this->asset('/public/assets/aet-footer.css');
        return $event->setStyles($styles);
    }

    public function addScripts(ScriptFilterEvent $event): ScriptFilterEvent
    {
        $scripts = $event->getScripts();
        $scripts[] = $this->asset('/public/assets/elegant-themes.js');
        $scripts[] = $this->asset('/public/assets/aet-features.js');
        $scripts[] = $this->asset('/public/assets/aet-footer.js');
        $scripts[] = $this->asset('/public/assets/aet-modern.js');
        return $event->setScripts($scripts);
    }

    public function addMenuItems(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();
        foreach ($menu as $menuItem) {
            $id = $menuItem->menu_id ?? '';
            if ($id !== 'misimg' && $id !== 'admimg') {
                continue;
            }
            $item = new stdClass();
            $item->requirement = 0;
            $item->target = $id === 'admimg' ? 'adm0' : 'msc0';
            $item->menu_id = 'elegantThemes' . ($id === 'admimg' ? 'Adm' : 'Msc');
            $item->label = xlt('Elegant Themes');
            $item->url = self::MODULE_PATH . '/public/settings.php';
            $item->children = [];
            $item->acl_req = $id === 'admimg' ? ['admin', 'super'] : [];
            $item->global_req = [];
            $menuItem->children[] = $item;
        }
        $event->setMenu($menu);
        return $event;
    }

    public function currentTheme(): string
    {
        return $this->theme;
    }

    /** Point this request's stock theme stylesheets at the theme's base (runtime only). */
    private function applyBaseTheme(array $def): void
    {
        $bag = OEGlobalsBag::getInstance();
        $base = $def['base'];
        foreach (['css_header', 'compact_header'] as $key) {
            $v = $GLOBALS[$key] ?? $bag->get($key);
            if (!is_string($v) || $v === '') {
                continue;
            }
            $new = preg_replace('#style_[a-z_]+\.css#', 'style_' . $base . '.css', $v) ?? $v;
            $GLOBALS[$key] = $new;
            $bag->set($key, $new);
        }
        if (!empty($def['compact'])) {
            $tabs = $GLOBALS['theme_tabs_layout'] ?? $bag->get('theme_tabs_layout');
            if (is_string($tabs) && $tabs !== '') {
                $new = str_replace('tabs_style_full', 'tabs_style_compact', $tabs);
                $GLOBALS['theme_tabs_layout'] = $new;
                $bag->set('theme_tabs_layout', $new);
            }
        }
    }

    private function currentUserId(): int
    {
        try {
            $session = SessionWrapperFactory::getInstance()->getActiveSession();
            return (int)($session->get('authUserID') ?? 0);
        } catch (\Throwable) {
            return (int)($_SESSION['authUserID'] ?? 0);
        }
    }

    private function asset(string $path): string
    {
        return OEGlobalsBag::getInstance()->getWebRoot() . self::MODULE_PATH . $path
            . '?v=' . rawurlencode(Themes::VERSION);
    }

    public static function webPath(): string
    {
        return OEGlobalsBag::getInstance()->getWebRoot() . self::MODULE_PATH;
    }
}
