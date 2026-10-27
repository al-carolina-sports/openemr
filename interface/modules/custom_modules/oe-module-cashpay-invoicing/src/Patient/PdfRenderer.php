<?php

/**
 * Turns the module's self-contained print HTML into a PDF.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Patient;

use Mpdf\Mpdf;
use OpenEMR\Pdf\Config_Mpdf;

final class PdfRenderer
{
    public function render(string $html): string
    {
        $pdf = new Mpdf(Config_Mpdf::getConfigMpdf());
        // The HTML carries its own styles and no external references.
        $pdf->whitelistStreamWrappers = [];
        $pdf->WriteHTML($html);
        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes)) {
            throw new \RuntimeException('PDF could not be produced');
        }
        return $bytes;
    }
}
