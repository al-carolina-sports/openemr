<?php

/**
 * Where Stripe sends the patient after a pay link or card-on-file link.
 * Shows a plain message only: no patient data, no OpenEMR login.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../../../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Request;

$query = Request::createFromGlobals()->query;
$message = match (true) {
    $query->getString('paid') === '1' => 'Thank you. Your payment was received.',
    $query->getString('saved') === '1' => 'Thank you. Your card was saved.',
    default => 'Nothing was charged. You can close this page.',
};
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Thank you</title><style>body{font-family:system-ui,Arial,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;'
    . 'text-align:center;color:#222}h1{font-size:1.4rem}</style></head><body><h1>'
    . htmlspecialchars($message, ENT_QUOTES)
    . '</h1><p>You can close this page.</p></body></html>';
