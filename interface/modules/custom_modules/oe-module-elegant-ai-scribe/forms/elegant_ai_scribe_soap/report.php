<?php

/**
 * AI Scribe Note - SOAP: thin wrapper around the shared form in ../elegant_ai_scribe.
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../elegant_ai_scribe/report.php';

function elegant_ai_scribe_soap_report($pid, $encounter, $cols, $id): void
{
    elegant_ai_scribe_report($pid, $encounter, $cols, $id, 'elegant_ai_scribe_soap');
}
