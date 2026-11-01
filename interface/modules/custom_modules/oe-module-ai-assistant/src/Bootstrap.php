<?php

/**
 * AI Assistant bootstrap: wires the module into core through events only.
 * No core file is edited.
 *
 * - Admin > AI Assistant (settings)
 * - A button in the main top bar and the chat panel (main tabs page only),
 *   shown when the assistant is enabled and the user has the "use" right.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Events\Main\Tabs\RenderEvent;
use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_PATH = '/interface/modules/custom_modules/oe-module-ai-assistant';
    public const VERSION = '0.1.0';

    private ?bool $panelAllowed = null;

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItems(...));
        $this->eventDispatcher->addListener(RenderEvent::EVENT_BODY_RENDER_NAV, $this->renderNavButton(...));
        $this->eventDispatcher->addListener(RenderEvent::EVENT_BODY_RENDER_POST, $this->renderPanel(...));
    }

    public function addMenuItems(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();
        foreach ($menu as $menuItem) {
            if (!$menuItem instanceof stdClass || ($menuItem->menu_id ?? '') !== 'admimg') {
                continue;
            }
            $item = new stdClass();
            $item->requirement = 0;
            $item->target = 'adm';
            $item->menu_id = 'aiaSettings';
            $item->label = xlt('AI Assistant');
            $item->url = self::MODULE_PATH . '/public/admin/settings.php';
            $item->children = [];
            $item->acl_req = [AclObject::SECTION, AclObject::Settings->value];
            $item->global_req = [];
            $children = is_array($menuItem->children ?? null) ? $menuItem->children : [];
            $children[] = $item;
            $menuItem->children = $children;
            break;
        }
        $event->setMenu($menu);
        return $event;
    }

    public function renderNavButton(RenderEvent $event): void
    {
        if (!$this->panelAllowed()) {
            return;
        }
        echo '<button type="button" id="aia-toggle" class="btn btn-sm btn-outline-primary ml-1" aria-controls="aia-panel" aria-expanded="false" title="'
            . xla('AI Assistant') . '"><i class="fa fa-comment-dots" aria-hidden="true"></i><span class="sr-only">'
            . xlt('AI Assistant') . '</span></button>';
    }

    public function renderPanel(RenderEvent $event): void
    {
        if (!$this->panelAllowed()) {
            return;
        }
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $base = ModuleServices::webRoot();
        $version = rawurlencode(self::VERSION);
        $strings = [
            'title' => xl('AI Assistant'),
            'placeholder' => xl('Ask about the schedule, e.g. "When is Patricia Test\'s next visit?"'),
            'send' => xl('Send'),
            'reset' => xl('New chat'),
            'close' => xl('Close'),
            'thinking' => xl('Thinking...'),
            'intro' => xl('I can look up patients, provider schedules, open times and upcoming visits. I can\'t change the calendar yet.'),
            'network' => xl('Could not reach the assistant. Check your connection and try again.'),
            'notice' => xl('Read-only preview. Check anything important on the calendar.'),
        ];
        echo '<link rel="stylesheet" href="' . attr($base . '/public/assets/assistant.css?v=' . $version) . '">';
        echo '<script src="' . attr($base . '/public/assets/assistant.js?v=' . $version) . '" defer'
            . ' data-aia-endpoint="' . attr($base . '/public/chat.php') . '"'
            . ' data-aia-csrf="' . attr(CsrfUtils::collectCsrfToken($session)) . '"'
            . ' data-aia-strings="' . attr(json_encode($strings, JSON_THROW_ON_ERROR)) . '"></script>';
    }

    /**
     * Checked once per page: assistant switched on and the user may use it.
     */
    private function panelAllowed(): bool
    {
        if ($this->panelAllowed !== null) {
            return $this->panelAllowed;
        }
        try {
            $allowed = (new Access())->can(AclObject::Use) && (new ModuleServices())->settings()->enabled;
        } catch (\Throwable $e) {
            // Tables missing (registered but not installed): stay out of the way.
            ServiceContainer::getLogger()->warning('AI Assistant panel not shown', ['exception' => $e]);
            $allowed = false;
        }
        return $this->panelAllowed = $allowed;
    }
}
