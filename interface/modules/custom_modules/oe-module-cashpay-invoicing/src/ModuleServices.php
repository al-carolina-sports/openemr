<?php

/**
 * Builds the module's services once per request, so every entry point
 * (pages, webhook, background job) wires them the same way. Configuration
 * only: business classes never see this.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\CashpayInvoicing\Billing\DeliveryService;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentRepository;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentService;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineService;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\QueryUtilsDatabase;
use OpenEMR\Modules\CashpayInvoicing\Inventory\StockService;
use OpenEMR\Modules\CashpayInvoicing\Membership\MembershipJob;
use OpenEMR\Modules\CashpayInvoicing\Membership\MembershipService;
use OpenEMR\Modules\CashpayInvoicing\Patient\PatientInfo;
use OpenEMR\Modules\CashpayInvoicing\Patient\PatientMailer;
use OpenEMR\Modules\CashpayInvoicing\Payments\PaymentService;
use OpenEMR\Modules\CashpayInvoicing\Pricing\CatalogLookup;
use OpenEMR\Modules\CashpayInvoicing\Pricing\PromoRepository;
use OpenEMR\Modules\CashpayInvoicing\Rewards\GiftCardService;
use OpenEMR\Modules\CashpayInvoicing\Rewards\RewardsService;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripeAccountRepository;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripePayments;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripeSdkGateway;
use OpenEMR\Modules\CashpayInvoicing\Stripe\WebhookHandler;

final class ModuleServices
{
    private ?PaymentService $payments = null;
    private ?StripePayments $stripe = null;
    private ?DocumentService $documentService = null;
    private ?LineService $lines = null;
    private ?DeliveryService $delivery = null;

    public readonly Database $db;
    public readonly SettingsRepository $settings;
    public readonly DocumentRepository $documents;
    public readonly RewardsService $rewards;
    public readonly GiftCardService $giftCards;
    public readonly StripeAccountRepository $stripeAccounts;
    public readonly CatalogLookup $catalog;

    public function __construct()
    {
        $this->db = new QueryUtilsDatabase();
        $this->settings = new SettingsRepository($this->db);
        $this->documents = new DocumentRepository($this->db);
        $this->rewards = new RewardsService($this->db);
        $this->giftCards = GiftCardService::withSystemRandom($this->db);
        $this->stripeAccounts = new StripeAccountRepository($this->db, ServiceContainer::getCrypto());
        $this->catalog = new CatalogLookup($this->db);
    }

    public static function webRoot(): string
    {
        return OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_PATH;
    }

    public function delivery(): DeliveryService
    {
        return $this->delivery ??= new DeliveryService($this->db, $this->documents, new StockService($this->db));
    }

    public function lines(): LineService
    {
        return $this->lines ??= new LineService($this->db, $this->documents, $this->catalog, new PromoRepository($this->db), $this->settings, $this->delivery());
    }

    public function documentService(): DocumentService
    {
        return $this->documentService ??= new DocumentService($this->db, $this->documents, $this->lines(), $this->delivery(), $this->settings);
    }

    public function stripe(): StripePayments
    {
        $this->buildPayments();
        return $this->stripe ?? throw new \LogicException('Stripe payments not built');
    }

    public function payments(): PaymentService
    {
        $this->buildPayments();
        return $this->payments ?? throw new \LogicException('Payment service not built');
    }

    /**
     * PaymentService refunds through Stripe, and Stripe posts through
     * PaymentService, so the two are built together.
     */
    private function buildPayments(): void
    {
        if ($this->payments !== null) {
            return;
        }
        $this->stripe = new StripePayments(
            $this->db,
            $this->stripeAccounts,
            new StripeSdkGateway(),
            $this->settings,
            ServiceContainer::getClock(),
            self::siteUrl() . '/public/stripe/done.php',
        );
        $this->payments = new PaymentService($this->db, $this->settings, $this->rewards, $this->giftCards, $this->stripe);
        $this->stripe->setPaymentService($this->payments);
    }

    public function memberships(): MembershipService
    {
        return new MembershipService($this->db, $this->documentService(), $this->lines(), $this->rewards, $this->settings);
    }

    public function membershipJob(): MembershipJob
    {
        return new MembershipJob($this->memberships(), $this->stripe(), ServiceContainer::getLogger());
    }

    public function webhook(): WebhookHandler
    {
        return new WebhookHandler($this->db, $this->stripeAccounts, new StripeSdkGateway(), $this->stripe(), ServiceContainer::getLogger());
    }

    public function patientMailer(): PatientMailer
    {
        return new PatientMailer(
            OEGlobalsBag::getInstance()->getString('patient_reminder_sender_email'),
            PatientInfo::facility($this->db, null)['name'],
        );
    }

    /**
     * Absolute URL of the module (Stripe redirects need scheme and host).
     */
    public static function siteUrl(): string
    {
        $globals = OEGlobalsBag::getInstance();
        $base = rtrim($globals->getString('site_addr_oath'), '/');
        return $base . self::webRoot();
    }
}
