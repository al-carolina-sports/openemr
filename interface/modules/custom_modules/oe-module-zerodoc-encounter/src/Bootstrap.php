<?php

/**
 * Wires ZeroDoc into OpenEMR through events only -- no core file is edited.
 *
 *   encounter.load_form_filter   points core's form loader at this module's
 *                                forms/<formdir>/{new,view,report}.php
 *   html.head.script.filter      injects ZeroDoc JS, gated per page and role
 *   html.head.style.filter       injects ZeroDoc CSS, gated per page and role
 *   menu.update                  adds Admin > ZeroDoc Workflow
 *
 * Asset gating follows oe-module-appt-hovercard: Header::setupHeader()
 * dispatches both filter events on EVERY page that calls it, so a listener that
 * does not check the page name loads ZeroDoc site-wide. The role check is done
 * here, server-side, and repeated by the endpoint.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Events\Encounter\LoadEncounterFormFilterEvent;
use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_DIR = 'oe-module-zerodoc-encounter';
    public const MODULE_PATH = '/interface/modules/custom_modules/' . self::MODULE_DIR;
    /** Asset cache-buster; keep in step with version.php. */
    public const ASSET_VERSION = '0.1.0';

    /**
     * The ONLY core pages ZeroDoc's assets are injected into. Core sends the
     * script event a basename and the style event the full script path, so both
     * are compared on basename.
     *
     * @var list<string>
     */
    private const INJECT_PAGES = [
        'forms.php',          // the encounter's forms list / tab body
        'encounter_top.php',  // the encounter frame
    ];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(LoadEncounterFormFilterEvent::EVENT_NAME, $this->locateForm(...));
        $this->eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, $this->addScripts(...));
        $this->eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, $this->addStyles(...));
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
    }

    /**
     * Serve new.php / view.php / report.php of ZeroDoc's forms from this module
     * instead of interface/forms/. setDir() takes a filesystem path and must
     * end in a separator (core concatenates the page name with no separator),
     * and core refuses any path outside interface/forms/ or interface/modules/.
     */
    public function locateForm(LoadEncounterFormFilterEvent $event): LoadEncounterFormFilterEvent
    {
        if (in_array($event->getFormName(), Forms::formdirs(), true)) {
            $event->setDir(dirname(__DIR__) . '/forms/' . $event->getFormName() . '/');
        }
        return $event;
    }

    public function addScripts(ScriptFilterEvent $event): ScriptFilterEvent
    {
        if (!$this->shouldInject($event->getPageName())) {
            return $event;
        }
        $scripts = $event->getScripts();
        $scripts[] = self::asset('/public/assets/zerodoc.js');
        return $event->setScripts($scripts);
    }

    public function addStyles(StyleFilterEvent $event): StyleFilterEvent
    {
        if (!$this->shouldInject($event->getPageName())) {
            return $event;
        }
        $styles = $event->getStyles();
        $styles[] = self::asset('/public/assets/zerodoc.css');
        return $event->setStyles($styles);
    }

    /** Admin > ZeroDoc Workflow. */
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
            $item->menu_id = 'zeroDocWorkflow0';
            $item->label = xlt('ZeroDoc Workflow');
            $item->url = self::MODULE_PATH . '/public/status.php';
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
     * Only load ZeroDoc's assets on an encounter page, and only for a
     * logged-in user whose ACL and role allow the UI. Everyone else gets the
     * stock page untouched -- disabling the module, or losing the permission,
     * restores stock behaviour with no leftovers.
     */
    private function shouldInject(string $pageName): bool
    {
        if (!in_array(basename($pageName), self::INJECT_PAGES, true)) {
            return false;
        }
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $username = $session->get('authUser');
        if (!is_string($username) || $username === '') {
            return false;
        }
        $policy = new AccessPolicy((new RoleResolver())->titlesFor($username));
        return $policy->canViewUi();
    }

    /** Browser-reachable base URL of this module. Never hardcode the web root. */
    public static function webPath(): string
    {
        return OEGlobalsBag::getInstance()->getWebRoot() . self::MODULE_PATH;
    }

    /** Web path of an asset under this module, with the cache-buster applied. */
    public static function asset(string $relativePath): string
    {
        return self::webPath() . $relativePath . '?v=' . self::ASSET_VERSION;
    }
}
