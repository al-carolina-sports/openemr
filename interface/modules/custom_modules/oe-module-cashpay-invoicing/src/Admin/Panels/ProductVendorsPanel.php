<?php

/**
 * Vendors a product can be ordered from, with one default.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin\Panels;

use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupSource;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panel;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;

final readonly class ProductVendorsPanel implements PanelHandler
{
    public function __construct(private Database $db, private LookupRepository $lookups)
    {
    }

    public function panel(): Panel
    {
        return Panel::ProductVendors;
    }

    public function title(): string
    {
        return 'Vendors';
    }

    public function load(int $ownerId): array
    {
        return [
            'rows' => $this->db->fetchAll(
                'SELECT pv.`vendor_id`, pv.`is_default`, v.`name` FROM `cpi_product_vendor` pv'
                . ' JOIN `cpi_vendor` v ON v.`id` = pv.`vendor_id` WHERE pv.`product_id` = ? ORDER BY pv.`is_default` DESC, v.`name`',
                [$ownerId]
            ),
            'vendors' => $this->lookups->options(LookupSource::Vendor),
        ];
    }

    public function handle(int $ownerId, string $op, FormInput $input, int $userId): Notice
    {
        $vendorId = $input->requiredId('vendor_id');
        return match ($op) {
            'save' => $this->add($ownerId, $vendorId, $input->bool('is_default')),
            'default' => $this->makeDefault($ownerId, $vendorId),
            'delete' => $this->remove($ownerId, $vendorId),
            default => throw new ValidationException(['op' => 'Unknown action']),
        };
    }

    private function add(int $productId, int $vendorId, bool $isDefault): Notice
    {
        $this->db->execute(
            'INSERT IGNORE INTO `cpi_product_vendor` (`product_id`, `vendor_id`, `is_default`) VALUES (?, ?, 0)',
            [$productId, $vendorId]
        );
        if ($isDefault) {
            $this->makeDefault($productId, $vendorId);
        }
        return Notice::Added;
    }

    private function makeDefault(int $productId, int $vendorId): Notice
    {
        $this->db->transaction(function () use ($productId, $vendorId): void {
            $this->db->execute('UPDATE `cpi_product_vendor` SET `is_default` = 0 WHERE `product_id` = ?', [$productId]);
            $this->db->execute(
                'UPDATE `cpi_product_vendor` SET `is_default` = 1 WHERE `product_id` = ? AND `vendor_id` = ?',
                [$productId, $vendorId]
            );
        });
        return Notice::Updated;
    }

    private function remove(int $productId, int $vendorId): Notice
    {
        $this->db->execute(
            'DELETE FROM `cpi_product_vendor` WHERE `product_id` = ? AND `vendor_id` = ?',
            [$productId, $vendorId]
        );
        return Notice::Removed;
    }
}
