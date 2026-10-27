<?php

/**
 * Cash-Pay Invoicing bootstrap: wires the module into core through events
 * only. No core file is edited.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing;

use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_PATH = '/interface/modules/custom_modules/oe-module-cashpay-invoicing';

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItems(...));
    }

    public function addMenuItems(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();
        foreach ($menu as $menuItem) {
            if (!$menuItem instanceof stdClass || ($menuItem->menu_id ?? '') !== 'admimg') {
                continue;
            }
            $setup = ['cashpay', AclObject::Setup->value];
            $settings = ['cashpay', AclObject::Settings->value];
            $view = ['cashpay', AclObject::View->value];
            $submenu = new stdClass();
            $submenu->label = xlt('Cash-Pay Setup');
            $submenu->icon = 'fa-caret-right';
            $submenu->requirement = 0;
            $submenu->children = [
                $this->item('cpiSetup0', 'Catalog and Pricing', '/public/admin/index.php', $setup),
                $this->item('cpiSetup1', 'Procedures', '/public/admin/list.php?entity=procedure', $setup),
                $this->item('cpiSetup2', 'Products and Inventory', '/public/admin/list.php?entity=product', $setup),
                $this->item('cpiSetup3', 'Sales Packages', '/public/admin/list.php?entity=package', $setup),
                $this->item('cpiSetup4', 'Membership Plans', '/public/admin/list.php?entity=membership_plan', $setup),
                $this->item('cpiSetup5', 'Promotional Discounts', '/public/admin/list.php?entity=promo_discount', $setup),
                $this->item('cpiSetup6', 'Price List', '/public/admin/price_list.php', $view),
                $this->item('cpiSetup7', 'Import Catalog', '/public/admin/import.php', $setup),
                $this->item('cpiSetup8', 'Cash-Pay Settings', '/public/admin/settings.php', $settings),
            ];
            $menuItem->children[] = $submenu;
            break;
        }
        $event->setMenu($menu);
        return $event;
    }

    /**
     * @param array{string, string} $aclReq
     */
    private function item(string $menuId, string $label, string $path, array $aclReq): stdClass
    {
        $item = new stdClass();
        $item->requirement = 0;
        $item->target = 'adm';
        $item->menu_id = $menuId;
        $item->label = xlt($label);
        $item->url = self::MODULE_PATH . $path;
        $item->children = [];
        $item->acl_req = $aclReq;
        $item->global_req = [];
        return $item;
    }
}
