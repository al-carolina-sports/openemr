<?php

/**
 * Gift cards: issuing when a gift card invoice is paid, lookup, redeeming
 * as a payment and putting value back on refunds.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Rewards;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class GiftCardService
{
    /** No 0/O or 1/I/L, so codes read back over the phone. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * @param \Closure(int): int $random returns a uniform random integer from 0 to the given maximum
     */
    public function __construct(private Database $db, private \Closure $random)
    {
    }

    public static function withSystemRandom(Database $db): self
    {
        return new self($db, static fn (int $max): int => random_int(0, $max));
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function find(string $code): ?array
    {
        $normal = self::normalizeCode($code);
        if ($normal === '') {
            return null;
        }
        return $this->db->fetchOne(
            'SELECT g.*, TRIM(CONCAT(p.`fname`, \' \', p.`lname`)) AS `purchaser_name`, TRIM(CONCAT(r.`fname`, \' \', r.`lname`)) AS `recipient_patient`'
            . ' FROM `cpi_gift_card` g LEFT JOIN `patient_data` p ON p.`pid` = g.`purchaser_pid` LEFT JOIN `patient_data` r ON r.`pid` = g.`recipient_pid`'
            . ' WHERE REPLACE(g.`code`, \'-\', \'\') = ?',
            [$normal]
        );
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function history(int $cardId): array
    {
        return $this->db->fetchAll(
            'SELECT t.*, u.`username` FROM `cpi_gift_card_txn` t LEFT JOIN `users` u ON u.`id` = t.`created_by`'
            . ' WHERE t.`gift_card_id` = ? ORDER BY t.`id` DESC',
            [$cardId]
        );
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function forPatient(int $pid): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM `cpi_gift_card` WHERE `purchaser_pid` = ? OR `recipient_pid` = ? ORDER BY `issued_date` DESC, `id` DESC',
            [$pid, $pid]
        );
    }

    /**
     * Issues a card for every unissued gift card line on a fully paid invoice.
     *
     * @return list<string> the new codes
     */
    public function issueForPaidInvoice(int $invoiceId, int $pid, \DateTimeImmutable $date, int $userId): array
    {
        $codes = [];
        $lines = $this->db->fetchAll(
            "SELECT `id`, `net`, `description` FROM `cpi_line` WHERE `parent_type` = 'invoice' AND `parent_id` = ? AND `item_type` = 'gift_card' AND `item_id` IS NULL",
            [$invoiceId]
        );
        foreach ($lines as $line) {
            $value = Row::money($line, 'net');
            if ($value->cents <= 0) {
                continue;
            }
            $code = $this->newCode();
            $recipient = trim((string) preg_replace('/^Gift card(?: for)?\s*-?\s*/i', '', Row::str($line, 'description')));
            $cardId = $this->db->insert(
                'INSERT INTO `cpi_gift_card` (`code`, `purchaser_pid`, `recipient_name`, `initial_value`, `balance`, `issued_invoice_id`, `issued_date`, `active`, `source`)'
                . " VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'sale')",
                [$code, $pid, $recipient !== '' ? mb_substr($recipient, 0, 150) : null, $value->toDecimalString(), $value->toDecimalString(), $invoiceId, $date->format('Y-m-d')]
            );
            $this->db->execute(
                "INSERT INTO `cpi_gift_card_txn` (`gift_card_id`, `txn_date`, `amount`, `invoice_id`, `pid`, `action`, `created_by`) VALUES (?, ?, ?, ?, ?, 'issue', ?)",
                [$cardId, $date->format('Y-m-d'), $value->toDecimalString(), $invoiceId, $pid, $userId]
            );
            $this->db->execute('UPDATE `cpi_line` SET `item_id` = ?, `code` = ? WHERE `id` = ?', [$cardId, $code, Row::int($line, 'id')]);
            $codes[] = $code;
        }
        return $codes;
    }

    /**
     * Takes value off a card for a payment. Returns the card id.
     */
    public function redeem(string $code, Money $amount, int $pid, \DateTimeImmutable $date, int $userId): int
    {
        $card = $this->find($code) ?? throw new ValidationException(['gift_card_code' => 'No gift card with that code']);
        if (!Row::bool($card, 'active')) {
            throw new ValidationException(['gift_card_code' => 'That gift card is not active']);
        }
        $balance = Row::money($card, 'balance');
        if ($amount->cents > $balance->cents) {
            throw new ValidationException(['amount' => 'The gift card has only ' . $balance->format() . ' left']);
        }
        $cardId = Row::int($card, 'id');
        $this->db->execute('UPDATE `cpi_gift_card` SET `balance` = `balance` - ? WHERE `id` = ?', [$amount->toDecimalString(), $cardId]);
        $this->db->execute(
            "INSERT INTO `cpi_gift_card_txn` (`gift_card_id`, `txn_date`, `amount`, `pid`, `action`, `created_by`) VALUES (?, ?, ?, ?, 'redeem', ?)",
            [$cardId, $date->format('Y-m-d'), (new Money(-$amount->cents))->toDecimalString(), $pid, $userId]
        );
        return $cardId;
    }

    public function linkPayment(int $cardId, int $paymentId): void
    {
        $this->db->execute(
            "UPDATE `cpi_gift_card_txn` SET `payment_id` = ? WHERE `gift_card_id` = ? AND `payment_id` IS NULL AND `action` = 'redeem' ORDER BY `id` DESC LIMIT 1",
            [$paymentId, $cardId]
        );
    }

    /**
     * Puts value back on the card a payment was taken from.
     */
    public function restoreForPayment(int $paymentId, Money $amount, int $pid, \DateTimeImmutable $date, int $userId): void
    {
        $txn = $this->db->fetchOne("SELECT `gift_card_id` FROM `cpi_gift_card_txn` WHERE `payment_id` = ? AND `action` = 'redeem' LIMIT 1", [$paymentId]);
        if ($txn === null) {
            return;
        }
        $cardId = Row::int($txn, 'gift_card_id');
        $this->db->execute('UPDATE `cpi_gift_card` SET `balance` = `balance` + ? WHERE `id` = ?', [$amount->toDecimalString(), $cardId]);
        $this->db->execute(
            "INSERT INTO `cpi_gift_card_txn` (`gift_card_id`, `txn_date`, `amount`, `payment_id`, `pid`, `action`, `created_by`) VALUES (?, ?, ?, ?, ?, 'refund', ?)",
            [$cardId, $date->format('Y-m-d'), $amount->toDecimalString(), $paymentId, $pid, $userId]
        );
    }

    public function setRecipient(int $cardId, ?int $recipientPid, string $recipientName): void
    {
        $this->db->execute(
            'UPDATE `cpi_gift_card` SET `recipient_pid` = ?, `recipient_name` = ? WHERE `id` = ?',
            [$recipientPid, $recipientName === '' ? null : mb_substr($recipientName, 0, 150), $cardId]
        );
    }

    private function newCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < 12; $i++) {
                $code .= self::ALPHABET[($this->random)(strlen(self::ALPHABET) - 1)];
                if ($i === 3 || $i === 7) {
                    $code .= '-';
                }
            }
            if ($this->db->fetchOne('SELECT 1 AS `x` FROM `cpi_gift_card` WHERE `code` = ?', [$code]) === null) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not create a unique gift card code');
    }
}
