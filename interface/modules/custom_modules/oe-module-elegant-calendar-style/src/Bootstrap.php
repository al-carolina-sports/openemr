<?php

/**
 * Annie's Elegant Calendar Style bootstrap: adds the stylesheet and the
 * Custom Views, week Stack/Stretch and Hide Sat/Sun scripts to the calendar
 * page through StyleFilterEvent and ScriptFilterEvent, and an admin-only
 * "Elegant Calendar Style" settings item under Admin through MenuEvent.
 * No core file is edited.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantCalendarStyle;

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_PATH = '/interface/modules/custom_modules/oe-module-elegant-calendar-style';

    /** The calendar view page (day/week/month). */
    private const CALENDAR_PAGE = 'pnuserapi.php';

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, $this->addStyles(...));
        $this->eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, $this->addScripts(...));
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
    }

    /** Admin > Elegant Calendar Style (site defaults for Hide Sat/Sun). */
    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();
        foreach ($menu as $menuItem) {
            if (($menuItem->menu_id ?? '') !== 'admimg') {
                continue;
            }
            $item = new stdClass();
            $item->requirement = 0;
            $item->target = 'adm0';
            $item->menu_id = 'elegantCalendarStyle0';
            $item->label = xlt('Elegant Calendar Style');
            $item->url = self::MODULE_PATH . '/public/settings.php';
            $item->children = [];
            $item->acl_req = ['admin', 'super'];
            $item->global_req = [];
            $menuItem->children[] = $item;
            break;
        }
        $event->setMenu($menu);
        return $event;
    }

    public function addStyles(StyleFilterEvent $event): StyleFilterEvent
    {
        // The calendar dispatches this event with the page name 'pnuserapi.php'.
        if (basename($event->getPageName()) !== self::CALENDAR_PAGE) {
            return $event;
        }
        $styles = $event->getStyles();
        $styles[] = $this->asset('/public/assets/elegant-calendar.css');
        $styles[] = $this->asset('/public/assets/custom-views.css');
        $styles[] = $this->asset('/public/assets/week-stretch.css');
        return $event->setStyles($styles);
    }

    public function addScripts(ScriptFilterEvent $event): ScriptFilterEvent
    {
        if (basename($event->getPageName()) !== self::CALENDAR_PAGE) {
            return $event;
        }
        $scripts = $event->getScripts();
        $scripts[] = $this->asset('/public/config.js.php');
        $scripts[] = $this->asset('/public/assets/custom-views.js');
        $scripts[] = $this->asset('/public/assets/week-stretch.js');
        return $event->setScripts($scripts);
    }

    private function asset(string $path): string
    {
        return OEGlobalsBag::getInstance()->getWebRoot() . self::MODULE_PATH . $path
            . '?v=' . rawurlencode(Config::VERSION);
    }
}
