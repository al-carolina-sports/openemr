<?php

/**
 * Controller for the patient chart's Cash-Pay pages: the Quotes and
 * Invoices lists, the shared quote/invoice editor, item search and printing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Patient;

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\CashpayInvoicing\Access;
use OpenEMR\Modules\CashpayInvoicing\AclObject;
use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupSource;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Billing\ChargeCategory;
use OpenEMR\Modules\CashpayInvoicing\Billing\DeliveryService;
use OpenEMR\Modules\CashpayInvoicing\Billing\Document;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentKind;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentRepository;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentService;
use OpenEMR\Modules\CashpayInvoicing\Billing\HeaderInput;
use OpenEMR\Modules\CashpayInvoicing\Billing\InvoiceType;
use OpenEMR\Modules\CashpayInvoicing\Billing\Line;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineDefaults;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineEdit;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineService;
use OpenEMR\Modules\CashpayInvoicing\Billing\Totals;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Pricing\CatalogLookup;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Twig\Environment;

final readonly class PatientPages
{
    private const CSRF_FIELD = 'csrf_token_form';

    public function __construct(
        private Request $request,
        private SessionInterface $session,
        private Environment $twig,
        private Database $db,
        private Access $access,
        private LookupRepository $lookups,
        private DocumentRepository $documents,
        private DocumentService $documentService,
        private LineService $lines,
        private DeliveryService $delivery,
        private CatalogLookup $catalog,
        private SettingsRepository $settings,
        private PdfRenderer $pdf,
        private QuoteMailer $mailer,
        private SignedScanUploader $uploader,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $patientUrl,
        private int $pid,
        private int $userId,
    ) {
    }

    /** Quotes and Invoices tabs. */
    public function account(): Response
    {
        if (($denied = $this->deny(AclObject::View)) !== null) {
            return $denied;
        }
        $patient = PatientInfo::load($this->db, $this->pid);
        if ($patient === null) {
            return new Response(xlt('Select a patient first.'), 400);
        }
        $tab = $this->request->query->getString('tab') === 'quotes' ? 'quotes' : 'invoices';
        $kind = $tab === 'quotes' ? DocumentKind::Quote : DocumentKind::Invoice;
        $summaries = $this->documents->summaries($kind, $this->pid);
        $balance = Money::zero();
        $unearned = Money::zero();
        $rows = [];
        foreach ($summaries as $summary) {
            $document = $summary['document'];
            $due = $summary['total']->subtract($summary['paid']);
            if ($kind === DocumentKind::Invoice && !$document->voided) {
                $balance = $balance->add($due);
                $unearned = $unearned->add($summary['unearned']);
            }
            $rows[] = [
                'id' => $document->id,
                'date' => $document->date->format('Y-m-d'),
                'name' => $document->name,
                'type' => $document->invoiceType->label(),
                'facility' => $this->name(LookupSource::Facility, $document->facilityId),
                'provider' => $this->name(LookupSource::User, $document->providerId),
                'total' => $summary['total']->format(),
                'discount' => $summary['discount']->format(),
                'tax' => $summary['tax']->format(),
                'paid' => $summary['paid']->format(),
                'due' => $due->format(),
                'unearned' => $summary['unearned']->cents > 0 ? $summary['unearned']->format() : '',
                'locked' => $document->locked,
                'active' => $document->isActive,
                'expires' => $document->expiresOn?->format('Y-m-d') ?? '',
                'converted' => $document->convertedInvoiceId,
                'lines' => $summary['lineCount'],
            ];
        }
        return $this->render('@cashpay/patient/account.html.twig', [
            'patient' => $patient,
            'tab' => $tab,
            'kind' => $kind->value,
            'rows' => $rows,
            'balance' => $balance->format(),
            'unearned' => $unearned->format(),
            'canBill' => $this->access->can(AclObject::Billing),
        ]);
    }

    /** The shared quote / invoice editor. */
    public function document(): Response
    {
        if (($denied = $this->deny(AclObject::View)) !== null) {
            return $denied;
        }
        $kind = DocumentKind::tryFrom($this->request->query->getString('kind')) ?? DocumentKind::Invoice;
        $id = $this->request->query->getInt('id');
        $document = $id > 0 ? $this->documents->find($kind, $id, $this->pid) : null;
        if ($id > 0 && $document === null) {
            return new Response(xlt('Not found'), 404);
        }
        $errors = [];
        if ($this->request->isMethod('POST')) {
            if (($denied = $this->deny(AclObject::Billing)) !== null) {
                return $denied;
            }
            if (($bad = $this->checkCsrf()) !== null) {
                return $bad;
            }
            try {
                return $this->handle($kind, $document);
            } catch (ValidationException $e) {
                $errors = $e->errors;
            } catch (\DomainException $e) {
                $errors = ['form' => 'That change is not allowed.'];
                $this->logger->warning('Cash-pay document change refused', ['exception' => $e]);
            }
        }
        $patient = PatientInfo::load($this->db, $this->pid);
        if ($patient === null) {
            return new Response(xlt('Select a patient first.'), 400);
        }
        return $this->render('@cashpay/patient/document.html.twig', $this->editorContext($kind, $document, $patient, $errors), $errors !== [] ? 422 : 200);
    }

    /** JSON for the "search products, procedures, packages" box. */
    public function search(): Response
    {
        if (!$this->access->can(AclObject::Billing)) {
            return new JsonResponse([], 403);
        }
        return new JsonResponse($this->catalog->search(mb_substr($this->request->query->getString('q'), 0, 100)));
    }

    /** Printable quote or receipt (HTML), or the same as a PDF. */
    public function print(): Response
    {
        if (($denied = $this->deny(AclObject::View)) !== null) {
            return $denied;
        }
        $kind = DocumentKind::tryFrom($this->request->query->getString('kind')) ?? DocumentKind::Invoice;
        $document = $this->documents->find($kind, $this->request->query->getInt('id'), $this->pid);
        $patient = PatientInfo::load($this->db, $this->pid);
        if ($document === null || $patient === null) {
            return new Response(xlt('Not found'), 404);
        }
        $html = $this->printHtml($document, $patient, false);
        if ($this->request->query->getString('format') === 'pdf') {
            $response = new Response($this->pdf->render($html));
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', 'inline; filename="' . $kind->value . '-' . $document->id . '.pdf"');
            return $response;
        }
        if ($kind === DocumentKind::Quote && $this->access->can(AclObject::Billing)) {
            $this->documentService->markQuote($document, 'printed');
        }
        return new Response($html);
    }

    private function handle(DocumentKind $kind, ?Document $document): Response
    {
        $form = new FormInput($this->request->request);
        $action = $form->string('action');
        $today = $this->today();
        if ($document === null) {
            if ($action !== 'save_header') {
                throw new ValidationException(['form' => 'Save the header first']);
            }
            $created = $this->documentService->create($kind, $this->pid, HeaderInput::fromForm($form, $kind), $this->userId);
            return $this->redirectTo($created, Notice::Created);
        }
        $defaults = LineDefaults::of($document);
        switch ($action) {
            case 'save_header':
                $this->documentService->saveHeader($document, HeaderInput::fromForm($form, $kind));
                return $this->redirectTo($document, Notice::Saved);
            case 'add_item':
                $type = $form->string('item_type');
                $itemId = $form->requiredId('item_id');
                if ($type === 'package') {
                    $this->lines->addPackage($document, $itemId, $defaults, $this->userId);
                } elseif ($type === 'procedure' || $type === 'product') {
                    $this->lines->addCatalogItem($document, $type, $itemId, $form->milli('qty', false) ?? 1000, $defaults, $this->userId);
                } else {
                    throw new ValidationException(['item' => 'Pick an item from the search list']);
                }
                return $this->redirectTo($document, Notice::Added, 'lines');
            case 'add_fee':
                $category = ChargeCategory::tryFrom($form->string('charge_category'));
                if ($category === null || $category === ChargeCategory::Provider || $category === ChargeCategory::Product) {
                    throw new ValidationException(['charge_category' => 'Pick a fee type']);
                }
                $this->lines->addFee($document, $category, $form->string('description'), $form->money('amount') ?? Money::zero(), $defaults);
                return $this->redirectTo($document, Notice::Added, 'lines');
            case 'add_gift_card':
                $this->lines->addGiftCard($document, $form->money('amount') ?? Money::zero(), $form->string('recipient'), $defaults);
                return $this->redirectTo($document, Notice::Added, 'lines');
            case 'update_line':
                $this->lines->update($document, $form->requiredId('line_id'), $this->lineEdit($form));
                return $this->redirectTo($document, Notice::Updated, 'lines');
            case 'remove_line':
                $this->lines->remove($document, $form->requiredId('line_id'), $this->userId);
                return $this->redirectTo($document, Notice::Removed, 'lines');
            case 'discount_all':
                $pct = Decimal::parse($form->string('pct') === '' ? '0' : $form->string('pct'), 2);
                $this->lines->discountAll($document, $pct, $form->optionalId('discount_reason_id'));
                return $this->redirectTo($document, Notice::Updated, 'lines');
            case 'receive':
                $this->delivery->receive(
                    $document,
                    $form->requiredId('line_id'),
                    $this->dateField($form, 'received_date') ?? $today,
                    $form->optionalId('provider_id'),
                    $form->optionalId('facility_id'),
                    $this->userId,
                );
                return $this->redirectTo($document, Notice::Received, 'lines');
            case 'edit_received':
                $this->delivery->editReceived(
                    $document,
                    $form->requiredId('line_id'),
                    $this->dateField($form, 'received_date') ?? $today,
                    $form->optionalId('provider_id'),
                    $this->userId,
                );
                return $this->redirectTo($document, Notice::Updated, 'lines');
            case 'clear_received':
                $this->delivery->clearReceived($document, $form->requiredId('line_id'), $this->userId);
                return $this->redirectTo($document, Notice::Updated, 'lines');
            case 'forfeit':
            case 'unforfeit':
                $this->delivery->forfeit($document, $form->requiredId('line_id'), $action === 'forfeit');
                return $this->redirectTo($document, Notice::Updated, 'lines');
            case 'lock':
                $this->documentService->setLocked($document, true, $this->userId, '');
                return $this->redirectTo($document, Notice::Locked);
            case 'unlock':
                if (!$this->access->can(AclObject::Refunds)) {
                    throw new ValidationException(['form' => 'Unlocking needs the Cash-Pay refunds and unlock permission']);
                }
                $this->documentService->setLocked($document, false, $this->userId, $form->string('reason'));
                return $this->redirectTo($document, Notice::Unlocked);
            case 'copy':
                return $this->redirectTo($this->documentService->copy($document, $today, $this->userId), Notice::Copied);
            case 'convert':
                return $this->redirectTo($this->documentService->convertQuote($document, $today, $this->userId), Notice::Converted);
            case 'delete':
                $this->documentService->delete($document, $this->userId);
                return new RedirectResponse($this->patientUrl . 'account.php?' . http_build_query([
                    'tab' => $kind === DocumentKind::Quote ? 'quotes' : 'invoices',
                    'notice' => Notice::Deleted->name,
                ]));
            case 'email_quote':
                return $this->emailQuote($document);
            case 'upload_signed':
                $file = $this->request->files->get('signed_file');
                if (!$file instanceof UploadedFile || $document->kind !== DocumentKind::Quote) {
                    throw new ValidationException(['file' => 'Choose the scanned, signed quote']);
                }
                $docId = $this->uploader->store($this->pid, $document->id, $file, $this->userId);
                $this->documentService->attachSignedScan($document, $docId);
                return $this->redirectTo($document, Notice::Uploaded);
            default:
                throw new ValidationException(['form' => 'Unknown action']);
        }
    }

    private function emailQuote(Document $quote): Response
    {
        if ($quote->kind !== DocumentKind::Quote) {
            throw new ValidationException(['form' => 'Only quotes are emailed here']);
        }
        $patient = PatientInfo::load($this->db, $this->pid) ?? throw new ValidationException(['form' => 'Patient not found']);
        try {
            $this->mailer->send($patient, $this->pdf->render($this->printHtml($quote, $patient, true)), $quote->id);
        } catch (\RuntimeException $e) {
            $this->logger->error('Quote email failed', ['quote_id' => $quote->id, 'exception' => $e]);
            throw new ValidationException(['email' => 'The email could not be sent. Check the mail settings.']);
        }
        $this->documentService->markQuote($quote, 'emailed');
        return $this->redirectTo($quote, Notice::Emailed);
    }

    private function lineEdit(FormInput $form): LineEdit
    {
        $qty = $form->milli('qty') ?? 0;
        $unitPrice = $form->money('unit_price') ?? Money::zero();
        $discount = Money::zero();
        if ($form->string('discount_pct') !== '') {
            try {
                $pct = Decimal::parse($form->string('discount_pct'), 2);
            } catch (\InvalidArgumentException) {
                throw new ValidationException(['discount_pct' => 'Enter a percent like 10']);
            }
            if ($pct < 0 || $pct > 10000) {
                throw new ValidationException(['discount_pct' => 'Enter 0 to 100 percent']);
            }
            $discount = $unitPrice->multiplyByMilli($qty)->percentOf($pct * 100);
        } else {
            $discount = $form->money('discount_amt', false) ?? Money::zero();
        }
        $description = $form->string('description');
        return new LineEdit(
            qtyMilli: $qty,
            unitPrice: $unitPrice,
            discount: $discount,
            discountReasonId: $form->optionalId('discount_reason_id'),
            providerId: $form->optionalId('provider_id'),
            sellerId: $form->optionalId('seller_id'),
            facilityId: $form->optionalId('facility_id'),
            serviceDate: $this->dateField($form, 'service_date'),
            description: $description === '' ? null : $description,
        );
    }

    /**
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function editorContext(DocumentKind $kind, ?Document $document, PatientInfo $patient, array $errors): array
    {
        $lines = $document !== null ? $this->documents->lines($document) : [];
        $totals = Totals::of(array_map(static fn (Line $l): array => [$l->category, $l->amounts()], $lines));
        $paid = $document !== null && $kind === DocumentKind::Invoice ? $this->documents->paidOn($document->id) : Money::zero();
        $unearned = Money::zero();
        foreach ($lines as $line) {
            if ($document !== null && $kind === DocumentKind::Invoice && $document->invoiceType->lineDeferred($line->packageId) && $line->isOutstanding()) {
                $unearned = $unearned->add($line->total());
            }
        }
        $submitted = $this->request->isMethod('POST') ? $this->request->request->all() : [];
        $settings = $this->settings->load();
        $today = $this->today();
        return [
            'patient' => $patient,
            'kind' => $kind->value,
            'kindLabel' => $kind->label(),
            'doc' => $document,
            'header' => $this->headerValues($kind, $document, $submitted, $settings->quoteValidDays, $today),
            'lines' => array_map(fn (Line $l): array => $this->lineView($l, $document), $lines),
            'totals' => array_map(static fn (array $r): array => [
                'label' => $r['category']->label(),
                'gross' => $r['gross']->format(),
                'discount' => $r['discount']->format(),
                'tax1' => $r['tax1']->format(),
                'tax2' => $r['tax2']->format(),
                'total' => $r['total']->format(),
            ], $totals->rows()),
            'grand' => [
                'gross' => $totals->gross()->format(),
                'discount' => $totals->discount()->format(),
                'tax1' => $totals->tax1()->format(),
                'tax2' => $totals->tax2()->format(),
                'total' => $totals->total()->format(),
                'paid' => $paid->format(),
                'due' => $totals->total()->subtract($paid)->format(),
                'unearned' => $unearned->format(),
                'deposit' => $document?->deposit->format() ?? '$0.00',
            ],
            'taxNames' => array_map(static fn ($t): string => $t->name, $this->settings->taxTypes()),
            'errors' => $errors,
            'invoiceTypes' => InvoiceType::choices(),
            'feeCategories' => array_map(static fn (ChargeCategory $c): array => ['value' => $c->value, 'label' => $c->label()], ChargeCategory::feeLines()),
            'providers' => $this->lookups->options(LookupSource::Provider, $document?->providerId),
            'users' => $this->lookups->options(LookupSource::User, $document?->sellerId),
            'facilities' => $this->lookups->options(LookupSource::Facility, $document?->facilityId),
            'reasons' => $this->lookups->options(LookupSource::DiscountReason),
            'canBill' => $this->access->can(AclObject::Billing),
            'canUnlock' => $this->access->can(AclObject::Refunds),
            'editable' => $document === null || $document->editable(),
            'defersDelivery' => $document !== null && $kind === DocumentKind::Invoice && (
                $document->invoiceType->defersDelivery()
                || array_filter($lines, static fn (Line $l): bool => $document->invoiceType->lineDeferred($l->packageId)) !== []
            ),
            'lockHistory' => $document !== null && $kind === DocumentKind::Invoice ? $this->documents->lockHistory($document->id) : [],
            'today' => $today->format('Y-m-d'),
            'searchUrl' => $this->patientUrl . 'search.php',
            'printUrl' => $document !== null ? $this->patientUrl . 'print.php?kind=' . $kind->value . '&id=' . $document->id : '',
            'patientUrl' => $this->patientUrl,
            'convertedInvoiceId' => $document?->convertedInvoiceId,
            'isGiftCardInvoice' => $document !== null && $kind === DocumentKind::Invoice && $document->invoiceType === InvoiceType::GiftCard,
        ];
    }

    /**
     * @param array<array-key, mixed> $submitted
     * @return array<string, string|bool>
     */
    private function headerValues(DocumentKind $kind, ?Document $document, array $submitted, int $validDays, \DateTimeImmutable $today): array
    {
        if ($submitted !== [] && ($submitted['action'] ?? '') === 'save_header') {
            $out = [];
            foreach (['name', 'date', 'invoice_type', 'facility_id', 'provider_id', 'seller_id', 'comments', 'deposit', 'expires_on', 'consult_credit'] as $key) {
                $value = $submitted[$key] ?? '';
                $out[$key] = is_string($value) ? $value : '';
            }
            $out['is_active'] = isset($submitted['is_active']);
            return $out;
        }
        if ($document === null) {
            return [
                'name' => '', 'date' => $today->format('Y-m-d'), 'invoice_type' => InvoiceType::Immediate->value,
                'facility_id' => '', 'provider_id' => '', 'seller_id' => (string) $this->userId, 'comments' => '',
                'deposit' => '0.00', 'expires_on' => $kind === DocumentKind::Quote ? $today->modify('+' . $validDays . ' days')->format('Y-m-d') : '',
                'consult_credit' => '0.00', 'is_active' => true,
            ];
        }
        return [
            'name' => $document->name,
            'date' => $document->date->format('Y-m-d'),
            'invoice_type' => $document->invoiceType->value,
            'facility_id' => (string) ($document->facilityId ?? ''),
            'provider_id' => (string) ($document->providerId ?? ''),
            'seller_id' => (string) ($document->sellerId ?? ''),
            'comments' => $document->comments,
            'deposit' => $document->deposit->toDecimalString(),
            'expires_on' => $document->expiresOn?->format('Y-m-d') ?? '',
            'consult_credit' => $document->consultCredit->toDecimalString(),
            'is_active' => $document->isActive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineView(Line $line, ?Document $document): array
    {
        return [
            'id' => $line->id,
            'itemType' => $line->itemType,
            'description' => $line->description,
            'code' => $line->code ?? '',
            'category' => $line->category->label(),
            'qty' => Decimal::display(Decimal::format($line->qtyMilli, 3)),
            'qtyRaw' => Decimal::format($line->qtyMilli, 3),
            'unitPrice' => $line->unitPrice->format(),
            'unitPriceRaw' => $line->unitPrice->toDecimalString(),
            'gross' => $line->gross()->format(),
            'discount' => $line->discount->cents > 0 ? $line->discount->format() : '',
            'discountRaw' => $line->discount->toDecimalString(),
            'discountReasonId' => $line->discountReasonId,
            'discountReason' => $this->name(LookupSource::DiscountReason, $line->discountReasonId),
            'promo' => $line->promoId !== null ? $this->name(LookupSource::PromoDiscount, $line->promoId) : '',
            'net' => $line->net->format(),
            'tax' => $line->tax1->add($line->tax2)->cents > 0 ? $line->tax1->add($line->tax2)->format() : '',
            'total' => $line->total()->format(),
            'providerId' => $line->providerId,
            'provider' => $this->name(LookupSource::User, $line->providerId),
            'sellerId' => $line->sellerId,
            'seller' => $this->name(LookupSource::User, $line->sellerId),
            'facilityId' => $line->facilityId,
            'facility' => $this->name(LookupSource::Facility, $line->facilityId),
            'serviceDate' => $line->serviceDate?->format('Y-m-d') ?? '',
            'receivedDate' => $line->receivedDate?->format('Y-m-d') ?? '',
            'forfeited' => $line->forfeited,
            'outstanding' => $line->isOutstanding(),
            'fromPackage' => $line->packageId !== null ? $this->name(LookupSource::Package, $line->packageId) : '',
            'canDescribe' => $line->itemType === 'other' || $this->allowsManualDescription($line),
            'deferred' => $document !== null && $document->kind === DocumentKind::Invoice && $document->invoiceType->lineDeferred($line->packageId),
        ];
    }

    private function allowsManualDescription(Line $line): bool
    {
        if ($line->itemId === null || ($line->itemType !== 'procedure' && $line->itemType !== 'product')) {
            return false;
        }
        $entry = $this->catalog->entry($line->itemType, $line->itemId);
        return $entry !== null && $entry->allowManualDescription;
    }

    private function printHtml(Document $document, PatientInfo $patient, bool $forEmail): string
    {
        $lines = $this->documents->lines($document);
        $totals = Totals::of(array_map(static fn (Line $l): array => [$l->category, $l->amounts()], $lines));
        $paid = $document->kind === DocumentKind::Invoice ? $this->documents->paidOn($document->id) : Money::zero();
        $settings = $this->settings->load();
        $printLines = [];
        foreach ($lines as $line) {
            if ($line->itemType === 'product' && $line->itemId !== null && $this->noPrint($line->itemId)) {
                continue;
            }
            $printLines[] = [
                'description' => $line->description,
                'code' => $line->code ?? '',
                'qty' => Decimal::display(Decimal::format($line->qtyMilli, 3)),
                'unit' => $line->unitPrice->format(),
                'discount' => $line->discount->cents > 0 ? $line->discount->format() : '',
                'net' => $line->net->format(),
                'received' => $line->receivedDate?->format('Y-m-d') ?? '',
            ];
        }
        $taxes = $this->settings->taxTypes();
        return $this->twig->render('@cashpay/patient/print.html.twig', [
            'doc' => $document,
            'kind' => $document->kind->value,
            'forEmail' => $forEmail,
            'patient' => $patient,
            'practice' => PatientInfo::facility($this->db, $document->facilityId),
            'lines' => $printLines,
            'gross' => $totals->gross()->format(),
            'discount' => $totals->discount()->format(),
            'tax1' => $totals->tax1()->cents > 0 ? $totals->tax1()->format() : '',
            'tax2' => $totals->tax2()->cents > 0 ? $totals->tax2()->format() : '',
            'tax1Name' => $taxes[1]->name ?? 'Tax 1',
            'tax2Name' => $taxes[2]->name ?? 'Tax 2',
            'total' => $totals->total()->format(),
            'paid' => $paid->format(),
            'due' => $totals->total()->subtract($paid)->format(),
            'consultCredit' => $document->consultCredit->cents > 0 ? $document->consultCredit->format() : '',
            'deposit' => $document->deposit->cents > 0 ? $document->deposit->format() : '',
            'footer' => $document->kind === DocumentKind::Quote ? $settings->quoteFooter : $settings->receiptFooter,
            'cardFeeNote' => $settings->cardFeeEnabled
                ? $settings->cardFeeLabel . ': ' . $settings->cardFeePercentText() . '% is added to payments by credit card (not debit or prepaid cards).'
                : '',
            'printedOn' => $this->today()->format('Y-m-d'),
        ]);
    }

    private function noPrint(int $productId): bool
    {
        $row = $this->db->fetchOne('SELECT `no_print` FROM `cpi_product` WHERE `id` = ?', [$productId]);
        return $row !== null && in_array($row['no_print'] ?? null, [1, '1'], true);
    }

    private function dateField(FormInput $form, string $key): ?\DateTimeImmutable
    {
        $text = $form->string($key);
        if ($text === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new ValidationException([$key => 'Enter a date as YYYY-MM-DD']);
        }
        return $date;
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }

    private function name(LookupSource $source, ?int $id): string
    {
        return $id === null ? '' : ($this->lookups->names($source)[$id] ?? '#' . $id);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context, int $status = 200): Response
    {
        $notice = Notice::fromName($this->request->query->getString('notice'));
        $context += [
            'csrfField' => self::CSRF_FIELD,
            'csrfToken' => CsrfUtils::collectCsrfToken(session: $this->session),
            'notice' => $notice?->text(),
            'patientUrl' => $this->patientUrl,
        ];
        return new Response($this->twig->render($template, $context), $status);
    }

    private function redirectTo(Document $document, Notice $notice, string $anchor = ''): RedirectResponse
    {
        return new RedirectResponse(
            $this->patientUrl . 'document.php?' . http_build_query([
                'kind' => $document->kind->value,
                'id' => $document->id,
                'notice' => $notice->name,
            ]) . ($anchor !== '' ? '#' . $anchor : '')
        );
    }

    private function deny(AclObject $object): ?Response
    {
        return $this->access->can($object) ? null : new Response(xlt('Access denied'), 403);
    }

    private function checkCsrf(): ?Response
    {
        $token = $this->request->request->getString(self::CSRF_FIELD);
        return CsrfUtils::verifyCsrfToken($token, session: $this->session) ? null : new Response(xlt('Authentication error'), 403);
    }
}
