<?php

/**
 * Front desk tools that are not tied to one chart: gift card lookup and the
 * daily cash drawer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\FrontDesk;

use OpenEMR\Modules\CashpayInvoicing\AclObject;
use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupSource;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Payments\CashDrawerService;
use OpenEMR\Modules\CashpayInvoicing\Rewards\GiftCardService;
use OpenEMR\Modules\CashpayInvoicing\Web\PageKit;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class FrontDeskPages
{
    public function __construct(
        private PageKit $kit,
        private Database $db,
        private LookupRepository $lookups,
        private GiftCardService $giftCards,
        private CashDrawerService $drawer,
        private ClockInterface $clock,
        private string $adminUrl,
        private int $userId,
    ) {
    }

    public function giftCards(): Response
    {
        if (($denied = $this->kit->deny(AclObject::Billing)) !== null) {
            return $denied;
        }
        $errors = [];
        $code = $this->kit->request->query->getString('code');
        if ($this->kit->isPost()) {
            if (($bad = $this->kit->checkCsrf()) !== null) {
                return $bad;
            }
            $form = new FormInput($this->kit->request->request);
            try {
                $card = $this->giftCards->find($form->string('code')) ?? throw new ValidationException(['code' => 'No gift card with that code']);
                $recipientPid = $form->optionalId('recipient_pid');
                if ($recipientPid !== null && $this->db->fetchOne('SELECT 1 AS `x` FROM `patient_data` WHERE `pid` = ?', [$recipientPid]) === null) {
                    throw new ValidationException(['recipient_pid' => 'No patient with that ID']);
                }
                $this->giftCards->setRecipient(Row::int($card, 'id'), $recipientPid, $form->string('recipient_name'));
                return $this->kit->redirect($this->adminUrl . 'gift_cards.php', ['code' => Row::str($card, 'code')], Notice::Saved);
            } catch (ValidationException $e) {
                $errors = $e->errors;
                $code = $form->string('code');
            }
        }
        $card = $code !== '' ? $this->giftCards->find($code) : null;
        $outstanding = $this->db->fetchAll(
            'SELECT g.`code`, g.`balance`, g.`initial_value`, g.`issued_date`, g.`recipient_name`, TRIM(CONCAT(p.`fname`, \' \', p.`lname`)) AS `purchaser`'
            . ' FROM `cpi_gift_card` g LEFT JOIN `patient_data` p ON p.`pid` = g.`purchaser_pid`'
            . ' WHERE g.`active` = 1 AND g.`balance` > 0 ORDER BY g.`issued_date` DESC LIMIT 200'
        );
        $liability = Money::zero();
        foreach ($outstanding as $row) {
            $liability = $liability->add(Row::money($row, 'balance'));
        }
        return $this->kit->render('@cashpay/frontdesk/gift_cards.html.twig', [
            'code' => $code,
            'card' => $card === null ? null : [
                'code' => Row::str($card, 'code'),
                'initial' => Row::money($card, 'initial_value')->format(),
                'balance' => Row::money($card, 'balance')->format(),
                'issued' => Row::str($card, 'issued_date'),
                'active' => Row::bool($card, 'active'),
                'purchaser' => Row::str($card, 'purchaser_name'),
                'recipientName' => Row::str($card, 'recipient_name'),
                'recipientPid' => Row::intOrNull($card, 'recipient_pid'),
                'recipientPatient' => Row::str($card, 'recipient_patient'),
            ],
            'history' => $card === null ? [] : array_map(static fn (array $t): array => [
                'date' => Row::str($t, 'txn_date'),
                'action' => Row::str($t, 'action'),
                'amount' => Row::money($t, 'amount')->format(),
                'invoice' => Row::intOrNull($t, 'invoice_id'),
                'user' => Row::str($t, 'username'),
            ], $this->giftCards->history(Row::int($card, 'id'))),
            'searched' => $code !== '',
            'errors' => $errors,
            'outstanding' => array_map(static fn (array $r): array => [
                'code' => Row::str($r, 'code'),
                'balance' => Row::money($r, 'balance')->format(),
                'initial' => Row::money($r, 'initial_value')->format(),
                'issued' => Row::str($r, 'issued_date'),
                'recipient' => Row::str($r, 'recipient_name'),
                'purchaser' => Row::str($r, 'purchaser'),
            ], $outstanding),
            'liability' => $liability->format(),
        ], $errors !== [] ? 422 : 200);
    }

    public function cashDrawer(): Response
    {
        if (($denied = $this->kit->deny(AclObject::Billing)) !== null) {
            return $denied;
        }
        $facilities = $this->lookups->options(LookupSource::Facility);
        $facilityId = $this->kit->request->query->getInt('facility_id');
        if (!isset($facilities[$facilityId])) {
            $facilityId = (int) (array_key_first($facilities) ?? 0);
        }
        $dateText = $this->kit->request->query->getString('date');
        $date = $dateText !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $dateText) : false;
        if ($date === false) {
            $date = $this->clock->now()->setTime(0, 0);
        }
        $errors = [];
        if ($this->kit->isPost()) {
            if (($bad = $this->kit->checkCsrf()) !== null) {
                return $bad;
            }
            $form = new FormInput($this->kit->request->request);
            try {
                $notice = match ($form->string('action')) {
                    'open' => $this->drawerOpen($facilityId, $date, $form),
                    'close' => $this->drawerClose($facilityId, $date, $form),
                    'reopen' => $this->drawerReopen($facilityId, $date),
                    default => throw new ValidationException(['form' => 'Unknown action']),
                };
                return $this->kit->redirect($this->adminUrl . 'cash_drawer.php', ['facility_id' => $facilityId, 'date' => $date->format('Y-m-d')], $notice);
            } catch (ValidationException $e) {
                $errors = $e->errors;
            }
        }
        $day = $facilityId > 0 ? $this->drawer->day($facilityId, $date) : null;
        return $this->kit->render('@cashpay/frontdesk/cash_drawer.html.twig', [
            'facilities' => $facilities,
            'facilityId' => $facilityId,
            'date' => $date->format('Y-m-d'),
            'errors' => $errors,
            'day' => $day === null ? null : [
                'open' => $day['open'],
                'closed' => $day['closed'],
                'opening' => $day['opening']->format(),
                'cashIn' => $day['cashIn']->format(),
                'cashOut' => $day['cashOut']->format(),
                'adjustment' => $day['adjustment']->format(),
                'expected' => $day['expected']->format(),
                'closing' => $day['closing']?->format() ?? '',
                'variance' => $day['variance']?->format() ?? '',
                'varianceCents' => $day['variance']->cents ?? 0,
                'openedBy' => $day['openedBy'],
                'closedBy' => $day['closedBy'],
                'openedAt' => $day['openedAt'],
                'closedAt' => $day['closedAt'],
                'entries' => array_map(static fn (array $e): array => [
                    'time' => substr(Row::str($e, 'created_at'), 11, 5),
                    'patient' => Row::str($e, 'patient'),
                    'type' => Row::str($e, 'pay_type'),
                    'amount' => Row::money($e, 'amount')->add(Row::money($e, 'gratuity'))->format(),
                    'status' => Row::str($e, 'status'),
                    'user' => Row::str($e, 'username'),
                ], $day['entries']),
            ],
            'canReopen' => $this->kit->access->can(AclObject::Refunds),
        ], $errors !== [] ? 422 : 200);
    }

    private function drawerOpen(int $facilityId, \DateTimeImmutable $date, FormInput $form): Notice
    {
        $this->drawer->open($facilityId, $date, $form->money('opening') ?? Money::zero(), $this->userId);
        return Notice::DrawerOpened;
    }

    private function drawerClose(int $facilityId, \DateTimeImmutable $date, FormInput $form): Notice
    {
        $this->drawer->close($facilityId, $date, $form->money('counted') ?? Money::zero(), $form->money('adjustment', false) ?? Money::zero(), $this->userId);
        return Notice::DrawerClosed;
    }

    private function drawerReopen(int $facilityId, \DateTimeImmutable $date): Notice
    {
        if (!$this->kit->access->can(AclObject::Refunds)) {
            throw new ValidationException(['form' => 'Reopening a closed drawer needs the Cash-Pay refunds permission']);
        }
        $this->drawer->reopen($facilityId, $date);
        return Notice::DrawerReopened;
    }
}
