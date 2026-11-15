<?php

/**
 * One Stripe account (one per income entity, e.g. BFM and TRM settle
 * separately) and the offices whose payments go to it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Stripe;

final readonly class StripeAccount
{
    /**
     * @param list<int> $facilityIds
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $live,
        public string $publishableKey,
        public string $secretKey,
        public string $webhookSecret,
        public string $terminalLocationId,
        public array $facilityIds,
        public bool $isDefault,
    ) {
        if (preg_match('/^[a-z0-9_-]{1,40}$/', $key) !== 1) {
            throw new \DomainException('Bad Stripe account key');
        }
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    /** True when the secret key's mode matches the account's mode. */
    public function keyMatchesMode(): bool
    {
        if ($this->secretKey === '') {
            return true;
        }
        $live = str_starts_with($this->secretKey, 'sk_live_') || str_starts_with($this->secretKey, 'rk_live_');
        return $live === $this->live;
    }
}
