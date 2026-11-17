<?php

/**
 * Wires the module into core through events only: the encounter form loader and the admin menu.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe;

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Encounter\LoadEncounterFormFilterEvent;
use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_DIR = 'oe-module-elegant-ai-scribe';
    public const MODULE_PATH = '/interface/modules/custom_modules/' . self::MODULE_DIR;
    /** Encounter form directories (registry.directory / forms.formdir) are listed in Templates::ALL. */

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(LoadEncounterFormFilterEvent::EVENT_NAME, $this->locateForm(...));
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
    }

    /** Serve new.php / view.php / report.php of our form from the module folder. */
    public function locateForm(LoadEncounterFormFilterEvent $event): LoadEncounterFormFilterEvent
    {
        if (in_array($event->getFormName(), Templates::formdirs(), true)) {
            $event->setDir(dirname(__DIR__) . '/forms/' . $event->getFormName() . '/');
        }
        return $event;
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
            $item->menu_id = 'elegantAiScribe0';
            $item->label = xlt('AI Scribe Settings');
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

    public static function webPath(): string
    {
        return OEGlobalsBag::getInstance()->getWebRoot() . self::MODULE_PATH;
    }
}
