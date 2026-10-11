<?php

/**
 * Appointment Hover Card module bootstrap: wires the module into core through
 * events only. No core file is edited.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ApptHoverCard;

use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_PATH = '/interface/modules/custom_modules/oe-module-appt-hovercard';

    /** Calendar pages that render the appointment blocks (same pages the bundled telehealth module hooks). */
    private const CALENDAR_PAGES = ['pnuserapi.php', 'pnadmin.php'];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, $this->addScripts(...));
        $this->eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, $this->addStyles(...));
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
    }

    public function addScripts(ScriptFilterEvent $event): ScriptFilterEvent
    {
        if (!$this->shouldInject($event->getPageName())) {
            return $event;
        }
        $base = $this->webPath() . '/public/';
        $scripts = $event->getScripts();
        $scripts[] = $base . 'config.js.php';
        $scripts[] = $base . 'assets/hovercard.js';
        return $event->setScripts($scripts);
    }

    public function addStyles(StyleFilterEvent $event): StyleFilterEvent
    {
        $page = basename((string)$event->getContextArgument(StyleFilterEvent::CONTEXT_ARGUMENT_SCRIPT_NAME));
        if (!$this->shouldInject($page)) {
            return $event;
        }
        $styles = $event->getStyles();
        $styles[] = $this->webPath() . '/public/assets/hovercard.css';
        return $event->setStyles($styles);
    }

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
            $item->menu_id = 'apptHoverCard0';
            $item->label = xlt('Appointment Hover Card');
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

    /**
     * Only load the card on the calendar, and only for users who may see it.
     * Users outside the allowed roles never get the script, so they keep the
     * native tooltip. The endpoint enforces the same rule again.
     */
    private function shouldInject(string $pageName): bool
    {
        if (!in_array($pageName, self::CALENDAR_PAGES, true)) {
            return false;
        }
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $username = $session->get('authUser');
        if (!is_string($username) || $username === '') {
            return false;
        }
        $settings = (new SettingsRepository())->all();
        $access = new AccessPolicy($settings, (new RoleResolver())->titlesFor($username));
        return $access->canViewCard();
    }

    private function webPath(): string
    {
        return OEGlobalsBag::getInstance()->getWebRoot() . self::MODULE_PATH;
    }
}
