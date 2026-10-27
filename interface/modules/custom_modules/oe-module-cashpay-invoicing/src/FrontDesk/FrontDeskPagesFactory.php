<?php

/**
 * Wires FrontDeskPages from OpenEMR's request, session and globals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\FrontDesk;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\CashpayInvoicing\Access;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Bootstrap;
use OpenEMR\Modules\CashpayInvoicing\Database\QueryUtilsDatabase;
use OpenEMR\Modules\CashpayInvoicing\ModuleTwig;
use OpenEMR\Modules\CashpayInvoicing\Payments\CashDrawerService;
use OpenEMR\Modules\CashpayInvoicing\Rewards\GiftCardService;
use OpenEMR\Modules\CashpayInvoicing\Web\PageKit;

final class FrontDeskPagesFactory
{
    public static function create(): FrontDeskPages
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $userId = $session->get('authUserID');
        $db = new QueryUtilsDatabase();
        $adminUrl = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_PATH . '/public/admin/';
        return new FrontDeskPages(
            kit: new PageKit(CurrentRequest::get(), $session, ModuleTwig::environment(), new Access(), ['adminUrl' => $adminUrl]),
            db: $db,
            lookups: new LookupRepository($db),
            giftCards: GiftCardService::withSystemRandom($db),
            drawer: new CashDrawerService($db),
            clock: ServiceContainer::getClock(),
            adminUrl: $adminUrl,
            userId: is_numeric($userId) ? (int) $userId : 0,
        );
    }
}
