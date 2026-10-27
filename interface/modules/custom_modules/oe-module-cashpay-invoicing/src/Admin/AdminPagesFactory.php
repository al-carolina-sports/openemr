<?php

/**
 * Wires AdminPages from OpenEMR's request, session and globals. This is the
 * only place in the admin code that reaches for globals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\CashpayInvoicing\Access;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panels\PanelFactory;
use OpenEMR\Modules\CashpayInvoicing\Bootstrap;
use OpenEMR\Modules\CashpayInvoicing\Database\QueryUtilsDatabase;
use OpenEMR\Modules\CashpayInvoicing\Import\CsvCatalog;
use OpenEMR\Modules\CashpayInvoicing\Inventory\StockService;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use Twig\Loader\FilesystemLoader;

final class AdminPagesFactory
{
    public static function create(): AdminPages
    {
        $globals = OEGlobalsBag::getInstance();
        $moduleDir = dirname(__DIR__, 2);
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $userId = $session->get('authUserID');
        $db = new QueryUtilsDatabase();
        $lookups = new LookupRepository($db);
        $twig = ServiceContainer::getTwig();
        $loader = $twig->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            throw new \LogicException('Twig loader cannot take the module templates');
        }
        if (!in_array('cashpay', $loader->getNamespaces(), true)) {
            $loader->addPath($moduleDir . '/templates', 'cashpay');
        }
        return new AdminPages(
            request: CurrentRequest::get(),
            session: $session,
            twig: $twig,
            db: $db,
            access: new Access(),
            crud: new CrudRepository($db),
            lookups: $lookups,
            panels: new PanelFactory($db, $lookups, new StockService($db)),
            settings: new SettingsRepository($db),
            csv: new CsvCatalog($db),
            logger: ServiceContainer::getLogger(),
            adminUrl: $globals->getWebRoot() . Bootstrap::MODULE_PATH . '/public/admin/',
            catalogFile: $moduleDir . '/data/patientnow_catalog.json',
            userId: is_numeric($userId) ? (int) $userId : 0,
        );
    }
}
