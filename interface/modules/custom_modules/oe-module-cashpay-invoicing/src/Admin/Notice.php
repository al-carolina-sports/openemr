<?php

/**
 * Confirmation shown after a change. Passed in the redirect URL by name,
 * so only these fixed texts can ever be displayed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

enum Notice
{
    case Saved;
    case Added;
    case Updated;
    case Removed;
    case Activated;
    case Deactivated;
    case StockAdjusted;
    case OrderReceived;
    case CountRecorded;
    case SettingsSaved;
    case Imported;
    case Created;
    case Locked;
    case Unlocked;
    case Copied;
    case Converted;
    case Deleted;
    case Received;
    case Emailed;
    case Uploaded;

    public function text(): string
    {
        return match ($this) {
            self::Saved => 'Saved.',
            self::Added => 'Added.',
            self::Updated => 'Updated.',
            self::Removed => 'Removed.',
            self::Activated => 'Record activated.',
            self::Deactivated => 'Record deactivated. It stays on old invoices but cannot be added to new ones.',
            self::StockAdjusted => 'Stock adjusted.',
            self::OrderReceived => 'Order received into stock.',
            self::CountRecorded => 'Count recorded.',
            self::SettingsSaved => 'Settings saved.',
            self::Imported => 'Import finished.',
            self::Created => 'Created. Add items below.',
            self::Locked => 'Invoice locked.',
            self::Unlocked => 'Invoice unlocked. The reason was saved to the lock history.',
            self::Copied => 'Copy created.',
            self::Converted => 'Invoice created from the quote.',
            self::Deleted => 'Deleted.',
            self::Received => 'Item received.',
            self::Emailed => 'Quote emailed to the patient.',
            self::Uploaded => 'Signed copy saved to the patient documents.',
        };
    }

    public static function fromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }
        return null;
    }
}
