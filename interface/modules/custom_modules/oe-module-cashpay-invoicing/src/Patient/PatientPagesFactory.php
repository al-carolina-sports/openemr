<?php

/**
 * Wires PatientPages from OpenEMR's request, session and globals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Patient;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\CashpayInvoicing\Access;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Billing\DeliveryService;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentRepository;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentService;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineService;
use OpenEMR\Modules\CashpayInvoicing\Bootstrap;
use OpenEMR\Modules\CashpayInvoicing\Database\QueryUtilsDatabase;
use OpenEMR\Modules\CashpayInvoicing\Inventory\StockService;
use OpenEMR\Modules\CashpayInvoicing\ModuleTwig;
use OpenEMR\Modules\CashpayInvoicing\Payments\PaymentService;
use OpenEMR\Modules\CashpayInvoicing\Pricing\CatalogLookup;
use OpenEMR\Modules\CashpayInvoicing\Pricing\PromoRepository;
use OpenEMR\Modules\CashpayInvoicing\Rewards\GiftCardService;
use OpenEMR\Modules\CashpayInvoicing\Rewards\RewardsService;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use OpenEMR\Modules\CashpayInvoicing\Web\PageKit;

final class PatientPagesFactory
{
    public static function create(): PatientPages
    {
        $globals = OEGlobalsBag::getInstance();
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $userId = $session->get('authUserID');
        $pid = $session->get('pid');
        $db = new QueryUtilsDatabase();
        $documents = new DocumentRepository($db);
        $settings = new SettingsRepository($db);
        $catalog = new CatalogLookup($db);
        $delivery = new DeliveryService($db, $documents, new StockService($db));
        $lines = new LineService($db, $documents, $catalog, new PromoRepository($db), $settings, $delivery);
        $practice = PatientInfo::facility($db, null);
        return new PatientPages(
            request: CurrentRequest::get(),
            session: $session,
            twig: ModuleTwig::environment(),
            db: $db,
            access: new Access(),
            lookups: new LookupRepository($db),
            documents: $documents,
            documentService: new DocumentService($db, $documents, $lines, $delivery, $settings),
            lines: $lines,
            delivery: $delivery,
            catalog: $catalog,
            settings: $settings,
            pdf: new PdfRenderer(),
            mailer: new QuoteMailer($globals->getString('patient_reminder_sender_email'), $practice['name']),
            uploader: new SignedScanUploader($db),
            clock: ServiceContainer::getClock(),
            logger: ServiceContainer::getLogger(),
            patientUrl: $globals->getWebRoot() . Bootstrap::MODULE_PATH . '/public/patient/',
            pid: is_numeric($pid) ? (int) $pid : 0,
            userId: is_numeric($userId) ? (int) $userId : 0,
        );
    }

    public static function accountPages(): AccountPages
    {
        $globals = OEGlobalsBag::getInstance();
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $userId = $session->get('authUserID');
        $pid = $session->get('pid');
        $db = new QueryUtilsDatabase();
        $settings = new SettingsRepository($db);
        $rewards = new RewardsService($db);
        $giftCards = GiftCardService::withSystemRandom($db);
        $patientUrl = $globals->getWebRoot() . Bootstrap::MODULE_PATH . '/public/patient/';
        return new AccountPages(
            kit: new PageKit(CurrentRequest::get(), $session, ModuleTwig::environment(), new Access(), ['patientUrl' => $patientUrl]),
            db: $db,
            lookups: new LookupRepository($db),
            payments: new PaymentService($db, $settings, $rewards, $giftCards),
            rewards: $rewards,
            giftCards: $giftCards,
            notes: new FinancialNotes($db),
            settings: $settings,
            clock: ServiceContainer::getClock(),
            patientUrl: $patientUrl,
            pid: is_numeric($pid) ? (int) $pid : 0,
            userId: is_numeric($userId) ? (int) $userId : 0,
        );
    }
}
