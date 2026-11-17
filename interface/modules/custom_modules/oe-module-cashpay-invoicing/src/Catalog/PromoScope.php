<?php

/**
 * What a promotional discount applies to. An empty list means "all".
 * Stored as JSON in cpi_promo_discount.scope_json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Catalog;

final readonly class PromoScope
{
    /** Invoice types a promo can be limited to. */
    public const INVOICE_TYPES = [
        'immediate' => 'Immediate sale',
        'prepaid' => 'Prepaid',
        'surgery' => 'Surgery',
        'gift_card' => 'Gift card',
        'membership' => 'Membership',
    ];

    /** JSON key => human label, in display order. */
    public const DIMENSIONS = [
        'facility_ids' => 'Offices',
        'procedure_category_ids' => 'Procedure categories',
        'procedure_ids' => 'Procedures',
        'product_category_ids' => 'Product categories',
        'product_ids' => 'Products',
        'vendor_ids' => 'Vendors',
        'manufacturer_ids' => 'Manufacturers',
    ];

    /**
     * @param list<string> $invoiceTypes
     * @param array<string, list<int>> $ids dimension key => ids
     * @param bool $noProducts true when the promo must never apply to products
     */
    public function __construct(
        public array $invoiceTypes = [],
        public array $ids = [],
        public bool $noProducts = false,
    ) {
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self();
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self();
        }
        if (!is_array($data)) {
            return new self();
        }
        $types = [];
        foreach (is_array($data['invoice_types'] ?? null) ? $data['invoice_types'] : [] as $type) {
            if (is_string($type) && isset(self::INVOICE_TYPES[$type])) {
                $types[] = $type;
            }
        }
        $ids = [];
        foreach (array_keys(self::DIMENSIONS) as $key) {
            $ids[$key] = self::intList($data[$key] ?? []);
        }
        return new self(array_values(array_unique($types)), $ids, ($data['no_products'] ?? false) === true);
    }

    /**
     * Builds a scope from submitted form arrays.
     *
     * @param array<array-key, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $data = [];
        $data['invoice_types'] = is_array($input['invoice_types'] ?? null) ? $input['invoice_types'] : [];
        foreach (array_keys(self::DIMENSIONS) as $key) {
            $data[$key] = is_array($input[$key] ?? null) ? $input[$key] : [];
        }
        $data['no_products'] = in_array($input['no_products'] ?? '', ['1', 1, true], true);
        return self::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function toJson(): string
    {
        $data = ['invoice_types' => $this->invoiceTypes];
        foreach (array_keys(self::DIMENSIONS) as $key) {
            $data[$key] = $this->ids($key);
        }
        $data['no_products'] = $this->noProducts;
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<int>
     */
    public function ids(string $dimension): array
    {
        return $this->ids[$dimension] ?? [];
    }

    public function isUnrestricted(): bool
    {
        if ($this->invoiceTypes !== [] || $this->noProducts) {
            return false;
        }
        foreach ($this->ids as $list) {
            if ($list !== []) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_int($item) && $item > 0) {
                $out[] = $item;
            } elseif (is_string($item) && preg_match('/^[1-9]\d{0,9}$/', $item) === 1) {
                $out[] = (int) $item;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }
}
