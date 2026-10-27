<?php

/**
 * Stripe accounts kept in cpi_settings under "stripe_accounts". Secret keys
 * and webhook signing secrets are encrypted with OpenEMR's crypto service.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Stripe;

use OpenEMR\Common\Crypto\CryptoInterface;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;

final readonly class StripeAccountRepository
{
    private const SETTING = 'stripe_accounts';

    public function __construct(private Database $db, private CryptoInterface $crypto)
    {
    }

    /**
     * @return list<StripeAccount>
     */
    public function all(): array
    {
        $row = $this->db->fetchOne('SELECT `setting_value` FROM `cpi_settings` WHERE `setting_key` = ?', [self::SETTING]);
        if ($row === null) {
            return [];
        }
        try {
            $data = json_decode(Row::str($row, 'setting_value'), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }
        $accounts = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = is_string($item['key'] ?? null) ? $item['key'] : '';
            if (preg_match('/^[a-z0-9_-]{1,40}$/', $key) !== 1) {
                continue;
            }
            $facilities = [];
            foreach (is_array($item['facility_ids'] ?? null) ? $item['facility_ids'] : [] as $id) {
                if (is_int($id) && $id > 0) {
                    $facilities[] = $id;
                }
            }
            $accounts[] = new StripeAccount(
                key: $key,
                label: self::str($item, 'label'),
                live: ($item['live'] ?? false) === true,
                publishableKey: self::str($item, 'publishable_key'),
                secretKey: $this->decrypt(self::str($item, 'secret_key')),
                webhookSecret: $this->decrypt(self::str($item, 'webhook_secret')),
                terminalLocationId: self::str($item, 'terminal_location_id'),
                facilityIds: $facilities,
                isDefault: ($item['default'] ?? false) === true,
            );
        }
        return $accounts;
    }

    public function byKey(string $key): ?StripeAccount
    {
        foreach ($this->all() as $account) {
            if ($account->key === $key) {
                return $account;
            }
        }
        return null;
    }

    /**
     * The account for an office: one that lists the office, else the default.
     */
    public function forFacility(?int $facilityId): ?StripeAccount
    {
        $default = null;
        $first = null;
        foreach ($this->all() as $account) {
            if (!$account->isConfigured()) {
                continue;
            }
            if ($facilityId !== null && in_array($facilityId, $account->facilityIds, true)) {
                return $account;
            }
            if ($account->isDefault && $default === null) {
                $default = $account;
            }
            $first ??= $account;
        }
        return $default ?? $first;
    }

    /**
     * Saves the list. An empty secret on an existing account keeps the stored one.
     *
     * @param list<StripeAccount> $accounts
     */
    public function save(array $accounts, int $userId): void
    {
        $existing = [];
        foreach ($this->all() as $account) {
            $existing[$account->key] = $account;
        }
        $data = [];
        foreach ($accounts as $account) {
            $old = $existing[$account->key] ?? null;
            $data[] = [
                'key' => $account->key,
                'label' => $account->label,
                'live' => $account->live,
                'publishable_key' => $account->publishableKey,
                'secret_key' => $this->crypto->encryptForDatabase($account->secretKey !== '' ? $account->secretKey : ($old->secretKey ?? '')),
                'webhook_secret' => $this->crypto->encryptForDatabase($account->webhookSecret !== '' ? $account->webhookSecret : ($old->webhookSecret ?? '')),
                'terminal_location_id' => $account->terminalLocationId,
                'facility_ids' => $account->facilityIds,
                'default' => $account->isDefault,
            ];
        }
        $this->db->execute(
            'INSERT INTO `cpi_settings` (`setting_key`, `setting_value`, `updated_by`) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_by` = VALUES(`updated_by`)',
            [self::SETTING, json_encode($data, JSON_THROW_ON_ERROR), $userId]
        );
    }

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return $this->crypto->decryptFromDatabase($value);
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private static function str(array $item, string $key): string
    {
        $value = $item[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
