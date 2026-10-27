<?php

/**
 * Controller for the Cash-Pay Setup pages. Each public/admin/*.php entry
 * point calls one method and sends the returned response.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\CashpayInvoicing\Access;
use OpenEMR\Modules\CashpayInvoicing\AclObject;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panels\PackageItemsPanel;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panels\PanelFactory;
use OpenEMR\Modules\CashpayInvoicing\Catalog\PackageTotals;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Import\CsvCatalog;
use OpenEMR\Modules\CashpayInvoicing\Import\PatientNowImporter;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Settings\CardFunding;
use OpenEMR\Modules\CashpayInvoicing\Settings\Settings;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use OpenEMR\Modules\CashpayInvoicing\Settings\TaxType;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Environment;

final readonly class AdminPages
{
    private const CSRF_FIELD = 'csrf_token_form';

    public function __construct(
        private Request $request,
        private SessionInterface $session,
        private Environment $twig,
        private Database $db,
        private Access $access,
        private CrudRepository $crud,
        private LookupRepository $lookups,
        private PanelFactory $panels,
        private SettingsRepository $settings,
        private CsvCatalog $csv,
        private LoggerInterface $logger,
        private string $adminUrl,
        private string $catalogFile,
        private int $userId,
    ) {
    }

    public function index(): Response
    {
        if (($denied = $this->deny(AclObject::Setup)) !== null) {
            return $denied;
        }
        $groups = [];
        foreach (EntityRegistry::all() as $entity) {
            $row = $this->db->fetchOne(
                "SELECT COUNT(*) AS `n` FROM `{$entity->table}`" . ($entity->hasActive() ? ' WHERE `active` = 1' : '')
            );
            $groups[$entity->group][] = [
                'entity' => $entity,
                'count' => is_numeric($row['n'] ?? null) ? (int) $row['n'] : 0,
            ];
        }
        return $this->render('admin/index.html.twig', [
            'groups' => $groups,
            'canSettings' => $this->access->can(AclObject::Settings),
        ]);
    }

    public function list(): Response
    {
        if (($denied = $this->deny(AclObject::Setup)) !== null) {
            return $denied;
        }
        $entity = $this->entityFromRequest();
        if ($entity === null) {
            return $this->notFound();
        }
        if ($this->request->isMethod('POST')) {
            if (($bad = $this->checkCsrf()) !== null) {
                return $bad;
            }
            $form = new FormInput($this->request->request);
            try {
                $id = $form->requiredId('id');
            } catch (ValidationException) {
                return $this->notFound();
            }
            $active = $form->bool('active');
            $this->crud->setActive($entity, $id, $active);
            return $this->redirect('list.php', ['entity' => $entity->key] + $this->listState(), $active ? Notice::Activated : Notice::Deactivated);
        }

        $query = new ListQuery(
            search: mb_substr($this->request->query->getString('q'), 0, 100),
            includeInactive: $this->request->query->getBoolean('inactive'),
            page: max(1, $this->request->query->getInt('page', 1)),
            filters: $this->listFilters($entity),
        );
        $result = $this->crud->search($entity, $query);
        $lookupNames = [];
        $filterOptions = [];
        foreach ($entity->listFields() as $field) {
            if ($field->lookup !== null) {
                $lookupNames[$field->name] = $this->lookups->names($field->lookup);
                $filterOptions[$field->name] = $lookupNames[$field->name];
            }
        }
        return $this->render('admin/list.html.twig', [
            'entity' => $entity,
            'fields' => $entity->listFields(),
            'rows' => array_map(fn (array $row): array => $this->displayRow($entity, $row, $lookupNames), $result['rows']),
            'total' => $result['total'],
            'query' => $query,
            'pages' => max(1, (int) ceil($result['total'] / $entity->pageSize)),
            'filterOptions' => $filterOptions,
            'csvEntity' => in_array($entity->key, CsvCatalog::supported(), true),
        ]);
    }

    public function edit(): Response
    {
        if (($denied = $this->deny(AclObject::Setup)) !== null) {
            return $denied;
        }
        $entity = $this->entityFromRequest();
        if ($entity === null) {
            return $this->notFound();
        }
        $id = $this->request->query->getInt('id');
        $record = $id > 0 ? $this->crud->find($entity, $id) : null;
        if ($id > 0 && $record === null) {
            return $this->notFound();
        }
        $errors = [];
        $submitted = null;
        $panelErrors = [];
        $openPanel = '';

        if ($this->request->isMethod('POST')) {
            if (($bad = $this->checkCsrf()) !== null) {
                return $bad;
            }
            $form = new FormInput($this->request->request);
            if ($form->string('action') === 'panel' && $id > 0) {
                $panel = Panel::fromKey($form->string('panel'));
                if ($panel === null || !in_array($panel, $entity->panels, true)) {
                    return $this->notFound();
                }
                $openPanel = $panel->key();
                try {
                    $notice = $this->panels->create($panel)->handle($id, $form->string('op'), $form, $this->userId);
                    return $this->redirect('edit.php', ['entity' => $entity->key, 'id' => $id], $notice, $panel->key());
                } catch (ValidationException $e) {
                    $panelErrors[$panel->key()] = $e->errors;
                } catch (\DomainException $e) {
                    $panelErrors[$panel->key()] = ['form' => 'That change is not allowed.'];
                    $this->logger->warning('Cash-pay panel change refused', ['panel' => $panel->key(), 'exception' => $e]);
                }
            } else {
                $input = [];
                foreach ($entity->fields as $field) {
                    $input[$field->name] = $form->string($field->name);
                }
                try {
                    $values = (new FieldParser())->parseAll($entity, $input);
                    $savedId = $this->crud->save($entity, $id > 0 ? $id : null, $values);
                    return $this->redirect('edit.php', ['entity' => $entity->key, 'id' => $savedId], $id > 0 ? Notice::Saved : Notice::Added);
                } catch (ValidationException $e) {
                    $errors = $e->errors;
                    $submitted = $input;
                }
            }
        }

        $values = $submitted ?? $this->formValues($entity, $record);
        $options = [];
        foreach ($entity->fields as $field) {
            if ($field->lookup !== null) {
                $current = $values[$field->name] ?? '';
                $options[$field->name] = $this->lookups->options(
                    $field->lookup,
                    preg_match('/^\d+$/', $current) === 1 ? (int) $current : null
                );
            }
        }
        $panelViews = [];
        if ($record !== null) {
            foreach ($entity->panels as $panel) {
                $handler = $this->panels->create($panel);
                $panelViews[] = [
                    'key' => $panel->key(),
                    'title' => $handler->title(),
                    'data' => $handler->load($id),
                    'errors' => $panelErrors[$panel->key()] ?? [],
                ];
            }
        }
        return $this->render('admin/edit.html.twig', [
            'entity' => $entity,
            'id' => $id,
            'record' => $record,
            'values' => $values,
            'errors' => $errors,
            'options' => $options,
            'panels' => $panelViews,
            'openPanel' => $openPanel,
        ], $errors !== [] || $panelErrors !== [] ? 422 : 200);
    }

    public function settings(): Response
    {
        if (($denied = $this->deny(AclObject::Settings)) !== null) {
            return $denied;
        }
        $errors = [];
        $current = $this->settings->load();
        $taxes = $this->settings->taxTypes();
        $submitted = [];
        if ($this->request->isMethod('POST')) {
            if (($bad = $this->checkCsrf()) !== null) {
                return $bad;
            }
            $form = new FormInput($this->request->request);
            $submitted = $form->all();
            try {
                $new = Settings::fromInput($form);
                $newTaxes = $this->taxesFromInput($form);
                $this->db->transaction(function () use ($new, $newTaxes): void {
                    $this->settings->save($new, $this->userId);
                    foreach ($newTaxes as $tax) {
                        $this->settings->saveTaxType($tax);
                    }
                });
                return $this->redirect('settings.php', [], Notice::SettingsSaved);
            } catch (ValidationException $e) {
                $errors = $e->errors;
            }
        }
        $example = Money::parse('100.00');
        return $this->render('admin/settings.html.twig', [
            'settings' => $current,
            'taxes' => $taxes,
            'errors' => $errors,
            'submitted' => $submitted,
            'maxCardFee' => Decimal::format(Settings::MAX_CARD_FEE_BP, 2),
            'exampleFee' => $current->cardFeeOn($example, CardFunding::Credit)->format(),
        ], $errors !== [] ? 422 : 200);
    }

    public function priceList(): Response
    {
        if (($denied = $this->deny(AclObject::View)) !== null) {
            return $denied;
        }
        $format = $this->request->query->getString('format');
        if ($format === 'csv') {
            if (($denied = $this->deny(AclObject::Setup)) !== null) {
                return $denied;
            }
            $entityKey = $this->request->query->getString('entity');
            if (!in_array($entityKey, CsvCatalog::supported(), true)) {
                return $this->notFound();
            }
            $response = new StreamedResponse(function () use ($entityKey): void {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    return;
                }
                fwrite($out, "\xEF\xBB\xBF");
                $this->csv->export($entityKey, $out);
                fclose($out);
            });
            $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $response->headers->set(
                'Content-Disposition',
                'attachment; filename="cashpay-' . $entityKey . 's-' . date('Y-m-d') . '.csv"'
            );
            return $response;
        }
        $procedures = $this->db->fetchAll(
            'SELECT p.`name`, p.`price`, p.`cpt_code`, p.`tax1`, p.`tax2`, c.`name` AS `category` FROM `cpi_procedure` p'
            . ' LEFT JOIN `cpi_procedure_category` c ON c.`id` = p.`category_id` WHERE p.`active` = 1 ORDER BY c.`name`, p.`name`'
        );
        $products = $this->db->fetchAll(
            'SELECT p.`name`, p.`style`, p.`price_to_patient` AS `price`, p.`tax1`, p.`tax2`, c.`name` AS `category`, m.`name` AS `manufacturer`'
            . ' FROM `cpi_product` p LEFT JOIN `cpi_product_category` c ON c.`id` = p.`category_id`'
            . ' LEFT JOIN `cpi_manufacturer` m ON m.`id` = p.`manufacturer_id` WHERE p.`active` = 1 ORDER BY c.`name`, p.`name`'
        );
        $packages = $this->db->fetchAll(
            'SELECT k.`id`, k.`name`, k.`package_type`, i.`qty`, i.`unit_price`, i.`discount` FROM `cpi_package` k'
            . ' LEFT JOIN `cpi_package_item` i ON i.`package_id` = k.`id` WHERE k.`active` = 1 ORDER BY k.`name`, i.`sort_order`'
        );
        return $this->render('admin/price_list.html.twig', [
            'procedures' => $this->groupBy($procedures, 'category'),
            'products' => $this->groupBy($products, 'category'),
            'packages' => $this->packageTotals($packages),
            'memberships' => $this->db->fetchAll(
                'SELECT `name`, `code`, `monthly_price`, `annual_price` FROM `cpi_membership_plan` WHERE `active` = 1 ORDER BY `name`'
            ),
            'printedOn' => date('Y-m-d'),
            'canSetup' => $this->access->can(AclObject::Setup),
        ]);
    }

    public function import(): Response
    {
        if (($denied = $this->deny(AclObject::Setup)) !== null) {
            return $denied;
        }
        $importer = PatientNowImporter::fromFile($this->db, $this->catalogFile);
        $facilities = $this->lookups->options(LookupSource::Facility);
        $report = null;
        $csvReport = null;
        $errors = [];
        $csvErrors = [];
        if ($this->request->isMethod('POST')) {
            if (($bad = $this->checkCsrf()) !== null) {
                return $bad;
            }
            $form = new FormInput($this->request->request);
            if ($form->string('action') === 'patientnow') {
                $map = [];
                foreach (PatientNowImporter::OFFICES as $index => $office) {
                    $facilityId = $form->optionalId('office_' . $index);
                    $map[$office] = $facilityId !== null && isset($facilities[$facilityId]) ? $facilityId : null;
                }
                try {
                    $report = $importer->run($map);
                    $this->logger->info('PatientNow catalog imported', ['counts' => $report->counts, 'user_id' => $this->userId]);
                } catch (\DomainException) {
                    $errors['import'] = 'The PatientNow catalog has already been imported.';
                } catch (\Throwable $e) {
                    $this->logger->error('PatientNow catalog import failed', ['exception' => $e]);
                    $errors['import'] = 'The import failed and nothing was changed. Details are in the PHP error log.';
                }
            } elseif ($form->string('action') === 'csv') {
                $entityKey = $form->string('entity');
                $file = $this->request->files->get('csv_file');
                if (!in_array($entityKey, CsvCatalog::supported(), true)) {
                    $csvErrors['entity'] = 'Pick procedures or products';
                } elseif (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > 5_000_000) {
                    $csvErrors['file'] = 'Choose a CSV file under 5 MB';
                } else {
                    $text = file_get_contents($file->getPathname());
                    try {
                        $csvReport = $this->csv->import($entityKey, $text === false ? '' : $text, !$form->bool('apply'));
                        $csvReport->warn($form->bool('apply') ? 'Changes saved.' : 'Checked only. Nothing was changed; tick "Save changes" to load the file.');
                    } catch (ValidationException $e) {
                        $csvErrors = $e->errors;
                    } catch (\Throwable $e) {
                        $this->logger->error('Cash-pay CSV import failed', ['entity' => $entityKey, 'exception' => $e]);
                        $csvErrors['file'] = 'The file could not be loaded and nothing was changed. Details are in the PHP error log.';
                    }
                }
            }
        }
        return $this->render('admin/import.html.twig', [
            'alreadyImported' => $importer->alreadyImported(),
            'summary' => $importer->summary(),
            'review' => $importer->reviewNotes(),
            'offices' => PatientNowImporter::OFFICES,
            'officeGuess' => PatientNowImporter::guessOffices($facilities),
            'facilities' => $facilities,
            'report' => $report,
            'errors' => $errors,
            'csvReport' => $csvReport,
            'csvErrors' => $csvErrors,
            'csvEntities' => CsvCatalog::supported(),
        ], $errors !== [] || $csvErrors !== [] ? 422 : 200);
    }

    /**
     * @return array<int, TaxType>
     * @throws ValidationException
     */
    private function taxesFromInput(FormInput $form): array
    {
        $taxes = [];
        $errors = [];
        foreach ([1, 2] as $slot) {
            $name = $form->string('tax' . $slot . '_name');
            if ($name === '' || mb_strlen($name) > 100) {
                $errors['tax' . $slot . '_name'] = 'Enter a name (up to 100 characters)';
                continue;
            }
            try {
                $rate = Decimal::parse($form->string('tax' . $slot . '_rate') === '' ? '0' : $form->string('tax' . $slot . '_rate'), 4);
                $taxes[$slot] = new TaxType($slot, $name, $rate, $form->bool('tax' . $slot . '_active'));
            } catch (\InvalidArgumentException | \DomainException) {
                $errors['tax' . $slot . '_rate'] = 'Enter a rate from 0 to 25 percent, like 6.75';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $taxes;
    }

    private function entityFromRequest(): ?EntityDefinition
    {
        try {
            return EntityRegistry::get($this->request->query->getString('entity'));
        } catch (\OutOfBoundsException) {
            return null;
        }
    }

    /**
     * @return array<string, int>
     */
    private function listFilters(EntityDefinition $entity): array
    {
        $filters = [];
        foreach ($entity->listFields() as $field) {
            if ($field->lookup === null) {
                continue;
            }
            $value = $this->request->query->getInt('f_' . $field->name);
            if ($value > 0) {
                $filters[$field->name] = $value;
            }
        }
        return $filters;
    }

    /**
     * Query string to return to the same list view after toggling a row.
     *
     * @return array<string, string>
     */
    private function listState(): array
    {
        $state = [];
        foreach ($this->request->query->all() as $key => $value) {
            if (is_string($value) && ($key === 'q' || $key === 'inactive' || $key === 'page' || str_starts_with($key, 'f_'))) {
                $state[$key] = $value;
            }
        }
        return $state;
    }

    /**
     * @param array<string, scalar|null> $row
     * @param array<string, array<int, string>> $lookupNames
     * @return array<string, mixed>
     */
    private function displayRow(EntityDefinition $entity, array $row, array $lookupNames): array
    {
        $cells = [];
        foreach ($entity->listFields() as $field) {
            $value = $row[$field->name] ?? null;
            $cells[$field->name] = match ($field->type) {
                FieldType::Lookup => is_numeric($value) ? ($lookupNames[$field->name][(int) $value] ?? '') : '',
                FieldType::Boolean => in_array($value, [1, '1'], true) ? 'Yes' : 'No',
                FieldType::Money => is_string($value) ? Money::parse($value)->format() : '',
                FieldType::Choice => is_string($value) ? ($field->choices[$value] ?? $value) : '',
                default => is_scalar($value) ? (string) $value : '',
            };
        }
        return [
            'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'active' => !$entity->hasActive() || in_array($row['active'] ?? null, [1, '1'], true),
            'cells' => $cells,
        ];
    }

    /**
     * Stored values as form text, or defaults for a new record.
     *
     * @param array<string, scalar|null>|null $record
     * @return array<string, string>
     */
    private function formValues(EntityDefinition $entity, ?array $record): array
    {
        $values = [];
        foreach ($entity->fields as $field) {
            if ($record === null) {
                $values[$field->name] = $field->default;
                continue;
            }
            $value = $record[$field->name] ?? null;
            $values[$field->name] = is_scalar($value) ? (string) $value : '';
        }
        return $values;
    }

    /**
     * @param list<array<string, scalar|null>> $rows
     * @return array<string, list<array<string, scalar|null>>>
     */
    private function groupBy(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $group = $row[$key] ?? null;
            $row['price_display'] = is_string($row['price'] ?? null) ? Money::parse($row['price'])->format() : '';
            $groups[is_string($group) && $group !== '' ? $group : 'Uncategorized'][] = $row;
        }
        return $groups;
    }

    /**
     * @param list<array<string, scalar|null>> $rows one row per package item
     * @return list<array{name: string, type: string, total: string}>
     */
    private function packageTotals(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $byId[$id] ??= [
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'type' => ($row['package_type'] ?? '') === 'immediate' ? 'Immediate sale' : 'Prepaid',
                'lines' => [],
            ];
            if (is_string($row['qty'] ?? null)) {
                $byId[$id]['lines'][] = PackageItemsPanel::lineOf($row);
            }
        }
        return array_values(array_map(static fn (array $p): array => [
            'name' => $p['name'],
            'type' => $p['type'],
            'total' => PackageTotals::of($p['lines'])->net->format(),
        ], $byId));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context, int $status = 200): Response
    {
        $notice = Notice::fromName($this->request->query->getString('notice'));
        $context += [
            'adminUrl' => $this->adminUrl,
            'csrfField' => self::CSRF_FIELD,
            'csrfToken' => CsrfUtils::collectCsrfToken(session: $this->session),
            'notice' => $notice?->text(),
        ];
        return new Response($this->twig->render($template, $context), $status);
    }

    /**
     * @param array<string, string|int> $params
     */
    private function redirect(string $page, array $params, Notice $notice, string $anchor = ''): RedirectResponse
    {
        $params['notice'] = $notice->name;
        return new RedirectResponse(
            $this->adminUrl . $page . '?' . http_build_query($params) . ($anchor !== '' ? '#' . rawurlencode($anchor) : '')
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

    private function notFound(): Response
    {
        return new Response(xlt('Not found'), 404);
    }
}
