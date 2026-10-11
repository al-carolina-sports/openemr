<?php

/**
 * Annie's Elegant Calendar Style bootstrap: adds one stylesheet to the
 * calendar page through StyleFilterEvent. No core file is edited.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantCalendarStyle;

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Core\StyleFilterEvent;
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
    }

    public function addStyles(StyleFilterEvent $event): StyleFilterEvent
    {
        // The calendar dispatches this event with the page name 'pnuserapi.php'.
        if (basename($event->getPageName()) !== self::CALENDAR_PAGE) {
            return $event;
        }
        $styles = $event->getStyles();
        $styles[] = OEGlobalsBag::getInstance()->getWebRoot() . self::MODULE_PATH
            . '/public/assets/elegant-calendar.css?v=' . rawurlencode(Config::VERSION);
        return $event->setStyles($styles);
    }
}
