<?php

/**
 * Renders every admin template with sample data, using stand-ins for
 * OpenEMR's Twig filters, to catch syntax errors and missing variables.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Admin\EntityRegistry;
use OpenEMR\Modules\CashpayInvoicing\Admin\ListQuery;
use OpenEMR\Modules\CashpayInvoicing\Billing\Document;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentKind;
use OpenEMR\Modules\CashpayInvoicing\Billing\InvoiceType;
use OpenEMR\Modules\CashpayInvoicing\Import\ImportReport;
use OpenEMR\Modules\CashpayInvoicing\Patient\PatientInfo;
use OpenEMR\Modules\CashpayInvoicing\Settings\Settings;
use OpenEMR\Modules\CashpayInvoicing\Settings\TaxType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class TemplateRenderTest extends TestCase
{
    private static function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../templates', 'cashpay');
        $twig = new Environment($loader, ['autoescape' => false, 'strict_variables' => true]);
        $esc = static fn (mixed $v): string => htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES);
        foreach (['xlt', 'xla', 'text', 'attr'] as $name) {
            $twig->addFilter(new TwigFilter($name, $esc));
        }
        $twig->addFilter(new TwigFilter('xl', static fn (mixed $v): string => is_scalar($v) ? (string) $v : ''));
        $twig->addFilter(new TwigFilter('xlj', static fn (mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR)));
        $twig->addFilter(new TwigFilter('attr_url', static fn (mixed $v): string => rawurlencode(is_scalar($v) ? (string) $v : '')));
        $twig->addFilter(new TwigFilter('money', static fn (mixed $v): string => is_numeric($v) ? number_format((float) $v, 2) : ''));
        $twig->addFunction(new TwigFunction('setupHeader', static fn (): string => ''));
        return $twig;
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return ['adminUrl' => '/a/', 'csrfField' => 'csrf_token_form', 'csrfToken' => 'tok', 'notice' => 'Saved.'];
    }

    public function testIndex(): void
    {
        $html = self::twig()->render('@cashpay/admin/index.html.twig', self::base() + [
            'groups' => ['Catalog' => [['entity' => EntityRegistry::get('procedure'), 'count' => 309]]],
            'canSettings' => true,
        ]);
        $this->assertStringContainsString('list.php?entity=procedure', $html);
        $this->assertStringContainsString('309', $html);
    }

    #[DataProvider('entities')]
    public function testListAndEditRenderForEveryEntity(string $key): void
    {
        $entity = EntityRegistry::get($key);
        $cells = [];
        $values = [];
        foreach ($entity->fields as $field) {
            $cells[$field->name] = 'v';
            $values[$field->name] = $field->default;
        }
        $html = self::twig()->render('@cashpay/admin/list.html.twig', self::base() + [
            'entity' => $entity,
            'fields' => $entity->listFields(),
            'rows' => [['id' => 3, 'active' => false, 'cells' => $cells]],
            'total' => 1,
            'query' => new ListQuery('<b>', true, 1, []),
            'pages' => 2,
            'filterOptions' => [],
            'csvEntity' => true,
        ]);
        $this->assertStringContainsString('edit.php?entity=' . $key . '&amp;id=3', $html);
        $this->assertStringNotContainsString('<b>', $html, 'search text is escaped');

        $html = self::twig()->render('@cashpay/admin/edit.html.twig', self::base() + [
            'entity' => $entity,
            'id' => 0,
            'record' => null,
            'values' => $values,
            'errors' => ['name' => 'Required'],
            'options' => [],
            'panels' => [],
            'openPanel' => '',
        ]);
        $this->assertStringContainsString('name="csrf_token_form"', $html);
        $this->assertStringContainsString('Required', $html);
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function entities(): array
    {
        $out = [];
        foreach (array_keys(EntityRegistry::all()) as $key) {
            $out[$key] = [$key];
        }
        return $out;
    }

    public function testEditWithEveryPanel(): void
    {
        $panels = [
            ['key' => 'procedure_fees', 'title' => 'Fees', 'errors' => [], 'data' => [
                'rows' => [['id' => 1, 'provider_id' => 2, 'facility_id' => null, 'price' => '10.00', 'hours' => null]],
                'providers' => [2 => 'Doe, Jane'], 'facilities' => [1 => 'Raleigh'], 'providerNames' => [2 => 'Doe, Jane'], 'facilityNames' => [],
            ]],
            ['key' => 'procedure_products', 'title' => 'Products', 'errors' => [], 'data' => [
                'rows' => [['product_id' => 4, 'qty' => '1.000', 'name' => 'Syringe']], 'products' => [4 => 'Syringe'],
            ]],
            ['key' => 'product_vendors', 'title' => 'Vendors', 'errors' => [], 'data' => [
                'rows' => [['vendor_id' => 1, 'is_default' => '1', 'name' => 'ZRT'], ['vendor_id' => 2, 'is_default' => '0', 'name' => 'B']], 'vendors' => [1 => 'ZRT'],
            ]],
            ['key' => 'product_facility_prices', 'title' => 'Prices', 'errors' => [], 'data' => [
                'rows' => [['facility_id' => 1, 'price_to_patient' => '5.00']], 'facilities' => [1 => 'Raleigh'], 'facilityNames' => [1 => 'Raleigh'],
            ]],
            ['key' => 'product_stock', 'title' => 'Stock', 'errors' => ['qty' => 'Required'], 'data' => [
                'rows' => [['facility_id' => 1, 'facility_name' => 'Raleigh', 'on_hand' => '2', 'on_order' => '0', 'low_stock_level' => '3', 'is_low' => true]],
                'reasons' => [1 => 'Damaged'],
                'history' => [['created_at' => '2026-10-26 10:00:00', 'facility_name' => 'Raleigh', 'kind' => 'receive_order', 'qty_delta' => '2.000', 'reason_name' => null, 'username' => 'admin']],
            ]],
            ['key' => 'package_items', 'title' => 'Items', 'errors' => [], 'data' => [
                'rows' => [['id' => 9, 'item_type' => 'procedure', 'item_name' => 'Laser', 'list_price' => '150.00', 'unit_price' => '125.00', 'qty' => '6.000', 'discount' => '0.00', 'sort_order' => 10, 'net_display' => '$750.00']],
                'totals' => ['gross' => '$750.00', 'discount' => '$0.00', 'net' => '$750.00'],
                'procedures' => [1 => 'Laser'], 'products' => [],
            ]],
            ['key' => 'membership_promos', 'title' => 'Promos', 'errors' => [], 'data' => [
                'promos' => [['id' => '1', 'name' => 'Tesla Chair', 'kind' => 'flat_total', 'amount' => '37.2500', 'members_only' => '1', 'active' => '1']],
                'linked' => [1],
            ]],
            ['key' => 'promo_scope', 'title' => 'Scope', 'errors' => [], 'data' => [
                'invoiceTypes' => ['membership' => 'Membership'], 'selectedTypes' => ['membership'],
                'dimensions' => [['key' => 'facility_ids', 'label' => 'Offices', 'options' => [1 => 'Raleigh'], 'selected' => [1]]],
                'noProducts' => true,
            ]],
        ];
        $entity = EntityRegistry::get('product');
        $values = [];
        foreach ($entity->fields as $field) {
            $values[$field->name] = '';
        }
        $html = self::twig()->render('@cashpay/admin/edit.html.twig', self::base() + [
            'entity' => $entity, 'id' => 5, 'record' => ['source' => 'patientnow_import'], 'values' => $values,
            'errors' => [], 'options' => [], 'panels' => $panels, 'openPanel' => 'product_stock',
        ]);
        foreach ($panels as $panel) {
            $this->assertStringContainsString('id="' . $panel['key'] . '"', $html);
        }
        $this->assertStringContainsString('Imported from PatientNow', $html);
        $this->assertStringContainsString('Make default', $html);
        $this->assertStringContainsString('$750.00', $html);
    }

    public function testSettings(): void
    {
        $html = self::twig()->render('@cashpay/admin/settings.html.twig', self::base() + [
            'settings' => new Settings(cardFeeEnabled: true, cardFeeBasisPoints: 275),
            'taxes' => [1 => new TaxType(1, 'NC Sales Tax', 67500, true), 2 => new TaxType(2, 'Tax 2', 0, false)],
            'errors' => [],
            'submitted' => [],
            'maxCardFee' => '3.00',
            'exampleFee' => '$2.75',
        ]);
        $this->assertStringContainsString('value="2.75" selected', $html);
        $this->assertStringContainsString('value="6.7500"', $html);
        $this->assertMatchesRegularExpression('/id="card_fee_enabled"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="tax2_active"[^>]*checked/', $html);
    }

    public function testPriceListAndImport(): void
    {
        $html = self::twig()->render('@cashpay/admin/price_list.html.twig', self::base() + [
            'procedures' => ['BHRT' => [['name' => 'Consult', 'cpt_code' => '99203', 'price_display' => '$300.00', 'tax1' => '0', 'tax2' => '0']]],
            'products' => ['Skin' => [['name' => 'Serum', 'style' => '1 oz', 'manufacturer' => 'X', 'price_display' => '$92.00', 'tax1' => '1', 'tax2' => '0']]],
            'packages' => [['name' => 'Laser 6', 'type' => 'Prepaid', 'total' => '$900.00']],
            'memberships' => [['name' => 'Core', 'code' => '1234', 'monthly_price' => '149.00', 'annual_price' => '1788.00']],
            'printedOn' => '2026-10-26',
            'canSetup' => true,
        ]);
        $this->assertStringContainsString('$300.00', $html);
        $this->assertStringContainsString('+tax', $html);

        $report = new ImportReport();
        $report->count('procedures', 309);
        $report->warn('Office "Wilson" was not matched');
        foreach ([false, true] as $done) {
            $html = self::twig()->render('@cashpay/admin/import.html.twig', self::base() + [
                'alreadyImported' => $done,
                'summary' => ['procedures' => 309, 'products' => 504, 'packages' => 37, 'promos' => 10, 'memberships' => 4],
                'review' => ['Check tax flags'],
                'offices' => ['Raleigh', 'Rocky Mount', 'Wilson'],
                'officeGuess' => ['Raleigh' => 1, 'Rocky Mount' => null, 'Wilson' => null],
                'facilities' => [1 => 'Raleigh Office'],
                'report' => $done ? $report : null,
                'errors' => [],
                'csvReport' => $report,
                'csvErrors' => ['row 2: price' => 'Enter an amount like 125.00'],
                'csvEntities' => ['procedure', 'product'],
            ]);
            $this->assertStringContainsString('row 2: price', $html);
            $this->assertSame(!$done, str_contains($html, 'name="office_0"'));
        }
    }

    private static function patient(): PatientInfo
    {
        return new PatientInfo(42, 'Patty Test', '1001', '1980-01-01', 'patty@example.com', '555-0100', ['1 Main St', 'Raleigh, NC 27615']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function patientBase(): array
    {
        return ['csrfField' => 'csrf_token_form', 'csrfToken' => 'tok', 'notice' => null, 'patientUrl' => '/p/'];
    }

    private static function doc(DocumentKind $kind, string $type, bool $locked = false): Document
    {
        return Document::fromRow($kind, [
            'id' => '5', 'pid' => '42', 'name' => 'B 12 <b>', 'invoice_date' => '2026-10-27', 'quote_date' => '2026-10-27',
            'invoice_type' => $type, 'income_facility_id' => '1', 'facility_id' => '1', 'provider_id' => '7', 'seller_id' => '8',
            'comments' => '', 'deposit' => '0.00', 'locked' => $locked ? '1' : '0', 'voided' => '0', 'is_active' => '1',
            'expires_on' => '2026-11-26', 'consult_credit' => '50.00', 'converted_invoice_id' => null, 'signed_scan_doc_id' => '9',
        ]);
    }

    public function testPatientAccountTabs(): void
    {
        foreach (['invoices' => 'invoice', 'quotes' => 'quote'] as $tab => $kind) {
            $html = self::twig()->render('@cashpay/patient/account.html.twig', self::patientBase() + [
                'patient' => self::patient(), 'tab' => $tab, 'kind' => $kind, 'balance' => '$80.00', 'unearned' => '$100.00', 'canBill' => true,
                'rows' => [[
                    'id' => 5, 'date' => '2026-10-27', 'name' => 'B 12', 'type' => 'Prepaid Packages', 'facility' => 'Raleigh', 'provider' => 'Antony, Alvin',
                    'total' => '$100.00', 'discount' => '$25.00', 'tax' => '$0.00', 'paid' => '$20.00', 'due' => '$80.00', 'unearned' => '$100.00',
                    'locked' => true, 'active' => false, 'expires' => '2026-11-26', 'converted' => 6, 'lines' => 5,
                ]],
            ]);
            $this->assertStringContainsString('document.php?kind=' . $kind . '&amp;id=5', $html);
            $this->assertStringContainsString('Patty Test', $html);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function editor(?Document $doc, string $kind, bool $deferred): array
    {
        $line = [
            'id' => 11, 'itemType' => 'procedure', 'description' => 'B12 Lipo Injection', 'code' => '', 'category' => 'Provider', 'qty' => '5',
            'qtyRaw' => '5.000', 'unitPrice' => '$25.00', 'unitPriceRaw' => '25.00', 'gross' => '$125.00', 'discount' => '$25.00', 'discountRaw' => '25.00',
            'discountReasonId' => 4, 'discountReason' => 'Package', 'promo' => '', 'net' => '$100.00', 'tax' => '', 'total' => '$100.00',
            'providerId' => 7, 'provider' => 'Antony, Alvin', 'sellerId' => 8, 'seller' => 'Yap, Jennifer', 'facilityId' => 1, 'facility' => 'Raleigh',
            'serviceDate' => '2026-10-27', 'receivedDate' => '', 'forfeited' => false, 'outstanding' => true, 'fromPackage' => 'B12 Package 5 for 4',
            'canDescribe' => false, 'deferred' => $deferred,
        ];
        $received = ['id' => 12, 'receivedDate' => '2026-10-27', 'outstanding' => false] + $line;
        $forfeited = ['id' => 13, 'forfeited' => true, 'outstanding' => false] + $line;
        return self::patientBase() + [
            'patient' => self::patient(), 'kind' => $kind, 'kindLabel' => $kind === 'quote' ? 'Quote' : 'Invoice', 'doc' => $doc,
            'header' => ['name' => 'B 12', 'date' => '2026-10-27', 'invoice_type' => 'prepaid', 'facility_id' => '1', 'provider_id' => '7',
                'seller_id' => '8', 'comments' => '', 'deposit' => '0.00', 'expires_on' => '2026-11-26', 'consult_credit' => '0.00', 'is_active' => true],
            'lines' => $doc === null ? [] : [$line, $received, $forfeited],
            'totals' => [['label' => 'Provider', 'gross' => '$125.00', 'discount' => '$25.00', 'tax1' => '$0.00', 'tax2' => '$0.00', 'total' => '$100.00']],
            'grand' => ['gross' => '$125.00', 'discount' => '$25.00', 'tax1' => '$0.00', 'tax2' => '$0.00', 'total' => '$100.00', 'paid' => '$0.00',
                'due' => '$100.00', 'unearned' => '$100.00', 'deposit' => '$0.00'],
            'taxNames' => [1 => 'NC Sales Tax', 2 => 'Tax 2'],
            'errors' => ['reason' => 'Say why the invoice is being unlocked'],
            'invoiceTypes' => InvoiceType::choices(),
            'feeCategories' => [['value' => 'or', 'label' => 'OR Facility']],
            'providers' => [7 => 'Antony, Alvin'], 'users' => [8 => 'Yap, Jennifer'], 'facilities' => [1 => 'Raleigh'], 'reasons' => [4 => 'Package'],
            'canBill' => true, 'canUnlock' => true, 'editable' => $doc === null || $doc->editable(), 'defersDelivery' => $deferred,
            'lockHistory' => [['created_at' => '2026-10-27 09:00:00', 'action' => 'lock', 'username' => 'admin', 'reason' => null]],
            'today' => '2026-10-27', 'searchUrl' => '/p/search.php', 'printUrl' => '/p/print.php?kind=' . $kind . '&id=5', 'convertedInvoiceId' => null,
            'isGiftCardInvoice' => $doc !== null && $doc->invoiceType === InvoiceType::GiftCard,
        ];
    }

    public function testEditorRendersNewQuoteInvoiceAndLockedInvoice(): void
    {
        $twig = self::twig();
        $html = $twig->render('@cashpay/patient/document.html.twig', self::editor(null, 'quote', false));
        $this->assertStringContainsString('name="expires_on"', $html);
        $this->assertStringNotContainsString('id="itemSearch"', $html, 'items are added after the header is saved');

        $html = $twig->render('@cashpay/patient/document.html.twig', self::editor(self::doc(DocumentKind::Invoice, 'prepaid'), 'invoice', true));
        $this->assertStringContainsString('id="itemSearch"', $html);
        $this->assertStringContainsString('Receive item', $html);
        $this->assertStringContainsString('Clear received flag', $html);
        $this->assertStringContainsString('Undo forfeit', $html);
        $this->assertStringContainsString('Package: B12 Package 5 for 4', $html);
        $this->assertStringNotContainsString('<b>', $html);

        $html = $twig->render('@cashpay/patient/document.html.twig', self::editor(self::doc(DocumentKind::Invoice, 'prepaid', true), 'invoice', true));
        $this->assertStringContainsString('id="unlockBox"', $html);
        $this->assertStringNotContainsString('id="itemSearch"', $html);
        $this->assertStringContainsString('<fieldset disabled', $html);

        $html = $twig->render('@cashpay/patient/document.html.twig', self::editor(self::doc(DocumentKind::Quote, 'immediate'), 'quote', false));
        $this->assertStringContainsString('Create invoice', $html);
        $this->assertStringContainsString('Signed copy on file', $html);
        $this->assertStringContainsString('upload_signed', $html);
    }

    public function testPrintQuoteAndReceipt(): void
    {
        $base = [
            'patient' => self::patient(),
            'practice' => ['name' => 'True Radiance', 'lines' => ['8382 Six Forks Rd'], 'phone' => '919-555-0100'],
            'lines' => [['description' => 'Serum', 'code' => 'S-1', 'qty' => '1', 'unit' => '$110.00', 'discount' => '', 'net' => '$110.00', 'received' => '']],
            'gross' => '$110.00', 'discount' => '$0.00', 'tax1' => '$7.43', 'tax2' => '', 'tax1Name' => 'NC Sales Tax', 'tax2Name' => 'Tax 2',
            'total' => '$117.43', 'paid' => '$0.00', 'due' => '$117.43', 'consultCredit' => '$50.00', 'deposit' => '',
            'footer' => "Line one\nLine two", 'cardFeeNote' => 'Credit card service charge: 3.00% is added to payments by credit card.', 'printedOn' => '2026-10-27',
        ];
        $twig = self::twig();
        $email = $twig->render('@cashpay/patient/print.html.twig', $base + ['doc' => self::doc(DocumentKind::Quote, 'immediate'), 'kind' => 'quote', 'forEmail' => true]);
        $this->assertStringContainsString('For information only', $email);
        $this->assertStringNotContainsString('Patient signature', $email);
        $paper = $twig->render('@cashpay/patient/print.html.twig', $base + ['doc' => self::doc(DocumentKind::Quote, 'immediate'), 'kind' => 'quote', 'forEmail' => false]);
        $this->assertStringContainsString('Patient signature', $paper);
        $this->assertStringContainsString('Valid until 2026-11-26', $paper);
        $receipt = $twig->render('@cashpay/patient/print.html.twig', $base + ['doc' => self::doc(DocumentKind::Invoice, 'prepaid'), 'kind' => 'invoice', 'forEmail' => false]);
        $this->assertStringContainsString('Balance due', $receipt);
        $this->assertStringContainsString('NC Sales Tax', $receipt);
        $this->assertStringContainsString('Line one<br', $receipt);
    }

    public function testPaymentsRewardsNotesPages(): void
    {
        $twig = self::twig();
        $ledgerRow = [
            'id' => 1, 'date' => '2026-10-27', 'type' => 'Payment', 'typeValue' => 'payment', 'method' => 'Card (other terminal)', 'amount' => '$150.00',
            'gratuity' => '', 'cardFee' => '$4.50', 'funding' => 'credit', 'reference' => '', 'comments' => 'x', 'status' => 'posted',
            'allocations' => [['invoice_id' => 5, 'amount' => '$100.00']], 'unapplied' => '$50.00', 'refundable' => '150.00', 'refundOf' => null,
            'user' => 'admin', 'facility' => 'Raleigh',
        ];
        $html = $twig->render('@cashpay/patient/payments.html.twig', self::patientBase() + [
            'patient' => self::patient(), 'navTab' => 'payments', 'errors' => [], 'submitted' => [],
            'totals' => ['balance' => '$20.00', 'credit' => '$50.00', 'creditRaw' => '50.00', 'lifetime' => '$150.00', 'since' => '2026-10-01'],
            'openInvoices' => [['id' => 5, 'date' => '2026-10-01', 'name' => 'Visit', 'total' => '$120.00', 'paid' => '$100.00', 'due' => '$20.00', 'dueRaw' => '20.00']],
            'focusInvoice' => 5, 'ledger' => [$ledgerRow, ['id' => 2, 'type' => 'Refund', 'typeValue' => 'refund', 'status' => 'void', 'refundable' => '', 'refundOf' => 1] + $ledgerRow],
            'methods' => [['id' => 3, 'name' => 'Cash', 'kind' => 'cash'], ['id' => 4, 'name' => 'Card', 'kind' => 'card']],
            'refundMethods' => [['id' => 8, 'name' => 'Refund Cash', 'kind' => 'cash']], 'facilities' => [1 => 'Raleigh'], 'defaultFacility' => 1,
            'today' => '2026-10-27', 'cardFee' => ['enabled' => true, 'bp' => 300, 'label' => 'Credit card service charge', 'pct' => '3.00'],
            'rewards' => ['on' => true, 'points' => '75', 'value' => '$75.00'], 'canBill' => true, 'canRefund' => true,
            'stripe' => [
                'readers' => [['id' => 'tmr_1', 'label' => 'Front desk', 'status' => 'online']],
                'hasCard' => false,
                'requests' => [['id' => 3, 'purpose' => 'payment', 'amount' => '$20.00', 'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/cs_test', 'sentTo' => 'p@example.com', 'created' => '2026-10-27 09:00', 'highlight' => true]],
                'readerUrl' => '/p/reader.php',
            ],
        ]);
        $this->assertStringContainsString('name="alloc[5]"', $html);
        $this->assertStringContainsString('Send to reader', $html);
        $this->assertStringContainsString('data-url="https://checkout.stripe.com/c/pay/cs_test"', $html);
        $this->assertStringContainsString('Record refund', $html);
        $this->assertStringContainsString('Apply credit', $html);
        $this->assertStringContainsString('data-bp="300"', $html);

        $html = $twig->render('@cashpay/patient/rewards.html.twig', self::patientBase() + [
            'patient' => self::patient(), 'navTab' => 'rewards', 'errors' => [], 'programOn' => false, 'enrolled' => true, 'points' => '75',
            'value' => '$75.00', 'rate' => '1.00', 'pointValue' => '$1.00', 'canAdmin' => true,
            'ledger' => [['date' => '2026-10-27', 'description' => 'Earned', 'points' => '75', 'dollars' => '$75.00', 'source' => 'payment', 'reason' => '', 'user' => 'admin']],
            'giftCards' => [['code' => 'ABCD-EFGH-JKLM', 'initial' => '$100.00', 'balance' => '$80.00', 'issued' => '2026-10-27', 'recipient' => 'Jane', 'purchaser' => 42]],
        ]);
        $this->assertStringContainsString('program is turned off', $html);
        $this->assertStringContainsString('Remove from award points', $html);
        $this->assertStringContainsString('ABCD-EFGH-JKLM', $html);

        $html = $twig->render('@cashpay/patient/notes.html.twig', self::patientBase() + [
            'patient' => self::patient(), 'navTab' => 'notes', 'canBill' => true,
            'note' => ['html' => '<p>Paid half</p>', 'updatedAt' => '2026-10-27 10:00:00', 'updatedBy' => 'admin'],
        ]);
        $this->assertStringContainsString('&lt;p&gt;Paid half&lt;/p&gt;</textarea>', $html);
    }

    public function testFrontDeskPages(): void
    {
        $twig = self::twig();
        $html = $twig->render('@cashpay/frontdesk/gift_cards.html.twig', self::base() + [
            'code' => 'ABCD', 'searched' => true, 'errors' => [], 'liability' => '$785.00',
            'card' => ['code' => 'ABCD-EFGH-JKLM', 'initial' => '$100.00', 'balance' => '$80.00', 'issued' => '2026-10-27', 'active' => true,
                'purchaser' => 'Patty Test', 'recipientName' => 'Jane', 'recipientPid' => null, 'recipientPatient' => ''],
            'history' => [['date' => '2026-10-27', 'action' => 'issue', 'amount' => '$100.00', 'invoice' => 9, 'user' => 'admin']],
            'outstanding' => [['code' => 'ABCD-EFGH-JKLM', 'balance' => '$80.00', 'initial' => '$100.00', 'issued' => '2026-10-27', 'recipient' => 'Jane', 'purchaser' => 'Patty']],
        ]);
        $this->assertStringContainsString('$785.00', $html);
        $this->assertStringContainsString('Save recipient', $html);

        foreach ([[false, false], [true, false], [true, true]] as [$open, $closed]) {
            $html = $twig->render('@cashpay/frontdesk/cash_drawer.html.twig', self::base() + [
                'facilities' => [1 => 'Raleigh'], 'facilityId' => 1, 'date' => '2026-10-27', 'errors' => [], 'canReopen' => true,
                'day' => ['open' => $open, 'closed' => $closed, 'opening' => '$200.00', 'cashIn' => '$235.00', 'cashOut' => '$25.00', 'adjustment' => '$0.00',
                    'expected' => '$410.00', 'closing' => '$400.00', 'variance' => '-$10.00', 'varianceCents' => -1000, 'openedBy' => 'a', 'closedBy' => 'b',
                    'openedAt' => 'x', 'closedAt' => 'y', 'entries' => [['time' => '10:00', 'patient' => 'Patty', 'type' => 'payment', 'amount' => '$160.00', 'status' => 'posted', 'user' => 'a']]],
            ]);
            $this->assertStringContainsString(!$open ? 'Open drawer' : ($closed ? 'Reopen this day' : 'Close drawer'), $html);
        }
    }

    public function testMembershipStripeAndWorklistPages(): void
    {
        $twig = self::twig();
        $html = $twig->render('@cashpay/patient/membership.html.twig', self::patientBase() + [
            'patient' => self::patient(), 'navTab' => 'membership', 'errors' => [], 'today' => '2026-10-27', 'facilities' => [1 => 'Raleigh'],
            'defaultFacility' => 1, 'hasCard' => false, 'stripeOn' => true, 'canBill' => true,
            'plans' => [['id' => 4, 'name' => 'Core', 'code' => '1234', 'monthly' => '$149.00', 'annual' => '$1,788.00']],
            'memberships' => [
                ['id' => 1, 'plan' => 'Core', 'code' => '1234', 'cycle' => 'Monthly', 'price' => '$149.00 / month', 'start' => '2026-10-27', 'end' => '',
                    'status' => 'active', 'autopay' => true, 'next' => '2026-11-27', 'autoInvoice' => false, 'facility' => 'Raleigh', 'autopayError' => 'Card declined'],
                ['id' => 2, 'plan' => 'Wellness', 'code' => 'Wellness', 'cycle' => 'Annual', 'price' => '$1,428.00 / year', 'start' => '2025-01-01', 'end' => '',
                    'status' => 'frozen', 'autopay' => false, 'next' => '2026-01-01', 'autoInvoice' => true, 'facility' => '', 'autopayError' => ''],
            ],
        ]);
        $this->assertStringContainsString('Bill next period', $html);
        $this->assertStringContainsString('Unfreeze', $html);
        $this->assertStringContainsString('Card declined', $html);
        $this->assertStringContainsString('needs_card_on', $html);

        $html = $twig->render('@cashpay/frontdesk/stripe.html.twig', self::base() + [
            'facilities' => [1 => 'Raleigh', 2 => 'Wilson'], 'errors' => [], 'tested' => ['key' => 'trm', 'ok' => true],
            'webhookUrl' => 'https://emr.example.com/x/webhook.php?site=default', 'events' => ['checkout.session.completed'],
            'accounts' => [['key' => 'trm', 'label' => 'True Radiance', 'live' => false, 'publishable' => 'pk_test_1', 'hasSecret' => true,
                'secretHint' => 'sk_test_...abcd', 'hasWebhook' => true, 'location' => 'tml_1', 'facilityIds' => [1], 'default' => true, 'modeWarning' => true]],
        ]);
        $this->assertStringContainsString('accounts[1][key]', $html, 'blank row for a new account');
        $this->assertStringContainsString('Test the key for True Radiance', $html);
        $this->assertStringContainsString('other mode', $html);
        $this->assertStringNotContainsString('sk_test_1', $html);

        $html = $twig->render('@cashpay/frontdesk/worklist.html.twig', self::base() + [
            'due' => [['pid' => 42, 'patient' => 'Patty', 'plan' => 'Core', 'next' => '2026-10-27', 'auto' => false, 'autopay' => true, 'error' => '']],
            'cards' => [['pid' => 42, 'patient' => 'Patty', 'email' => 'p@example.com', 'phone' => '555', 'flagged' => true, 'plans' => '1234', 'lastLink' => '']],
            'stripeOn' => false, 'failures' => [['patient' => 'Patty', 'plan_name' => 'Core', 'last_autopay_error' => 'Card declined']],
        ]);
        $this->assertStringContainsString('Bill by hand', $html);
        $this->assertStringContainsString('Stripe is not set up yet', $html);
    }
}
