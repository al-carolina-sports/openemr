<?php

/**
 * One rich-text financial note per patient, kept apart from clinical notes.
 * HTML is cleaned with HTMLPurifier before it is stored.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Patient;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;

final readonly class FinancialNotes
{
    private const MAX_BYTES = 200_000;

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{html: string, updatedAt: string, updatedBy: string}
     */
    public function load(int $pid): array
    {
        $row = $this->db->fetchOne(
            'SELECT n.`body_html`, n.`updated_at`, u.`username` FROM `cpi_financial_note` n LEFT JOIN `users` u ON u.`id` = n.`updated_by` WHERE n.`pid` = ?',
            [$pid]
        );
        return $row === null
            ? ['html' => '', 'updatedAt' => '', 'updatedBy' => '']
            : ['html' => Row::str($row, 'body_html'), 'updatedAt' => Row::str($row, 'updated_at'), 'updatedBy' => Row::str($row, 'username')];
    }

    public function save(int $pid, string $html, int $userId): void
    {
        $clean = self::clean(mb_substr($html, 0, self::MAX_BYTES));
        $this->db->execute(
            'INSERT INTO `cpi_financial_note` (`pid`, `body_html`, `updated_by`) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `body_html` = VALUES(`body_html`), `updated_by` = VALUES(`updated_by`)',
            [$pid, $clean, $userId]
        );
    }

    public static function clean(string $html): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p,br,b,strong,i,em,u,ul,ol,li,span,div,h4,h5,table,thead,tbody,tr,td,th,a[href]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', []);
        return (new \HTMLPurifier($config))->purify($html);
    }
}
