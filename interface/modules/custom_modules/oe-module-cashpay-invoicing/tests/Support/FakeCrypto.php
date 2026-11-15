<?php

/**
 * Reversible stand-in for OpenEMR's crypto service.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests\Support;

use OpenEMR\Common\Crypto\CryptoInterface;
use OpenEMR\Common\Crypto\KeySource;

final class FakeCrypto implements CryptoInterface
{
    public function encryptStandard(?string $value, KeySource $keySource = KeySource::Drive): string
    {
        return 'enc:' . base64_encode($value ?? '');
    }

    public function decryptStandard(?string $value, KeySource $keySource = KeySource::Drive, ?int $minimumVersion = null): false|string
    {
        return is_string($value) && str_starts_with($value, 'enc:') ? (string) base64_decode(substr($value, 4), true) : false;
    }

    public function cryptCheckStandard(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, 'enc:');
    }

    public function encryptForDatabase(?string $value): string
    {
        return 'enc:' . base64_encode($value ?? '');
    }

    public function encryptForFilesystem(?string $value): string
    {
        return $this->encryptForDatabase($value);
    }

    public function decryptFromDatabase(?string $value, ?int $minimumVersion = null): string
    {
        if (!is_string($value) || !str_starts_with($value, 'enc:')) {
            return $value ?? '';
        }
        $plain = base64_decode(substr($value, 4), true);
        return is_string($plain) ? $plain : '';
    }

    public function decryptFromFilesystem(?string $value): string
    {
        return $this->decryptFromDatabase($value);
    }

    public function isDatabaseValueLatest(string $value): bool
    {
        return true;
    }

    public function isFilesystemValueLatest(string $value): bool
    {
        return true;
    }
}
