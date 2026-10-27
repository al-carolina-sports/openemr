<?php

/**
 * Access control objects of the "cashpay" ACO section.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing;

/**
 * Backed because the value is stored in phpGACL.
 */
enum AclObject: string
{
    case View = 'view';
    case Billing = 'billing';
    case Refunds = 'refunds';
    case Setup = 'setup';
    case Settings = 'settings';
    case Commissions = 'commissions';
    case Reports = 'reports';

    public const SECTION = 'cashpay';
    public const SECTION_TITLE = 'Cash-Pay';

    public function title(): string
    {
        return match ($this) {
            self::View => 'Cash-Pay: view quotes, invoices and payments',
            self::Billing => 'Cash-Pay: create quotes and invoices, take payments',
            self::Refunds => 'Cash-Pay: refunds, voids and unlocking invoices',
            self::Setup => 'Cash-Pay: catalog, packages, memberships and discounts',
            self::Settings => 'Cash-Pay: module settings (taxes, card charge, rewards, Stripe)',
            self::Commissions => 'Cash-Pay: commission rules and runs',
            self::Reports => 'Cash-Pay: reports',
        };
    }

    /**
     * Stock OpenEMR groups that receive this object on first enable.
     * Administrators get everything; admins can change grants later
     * under Admin > ACL.
     *
     * @return list<string>
     */
    public function defaultGroups(): array
    {
        return match ($this) {
            self::View, self::Billing => ['Administrators', 'Accounting', 'Front Office'],
            self::Refunds, self::Reports => ['Administrators', 'Accounting'],
            self::Setup, self::Settings, self::Commissions => ['Administrators'],
        };
    }
}
