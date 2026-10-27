<?php

/**
 * Stripe webhook endpoint. Stripe calls this without an OpenEMR login, so
 * every request is checked against the accounts' webhook signing secrets
 * before anything is read from it.
 *
 * Point Stripe at: https://<your-server>/interface/modules/custom_modules/oe-module-cashpay-invoicing/public/stripe/webhook.php?site=default
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// No interactive session: Stripe authenticates with the signature instead.
$ignoreAuth = true;
require_once(__DIR__ . '/../../../../../globals.php');

use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Modules\CashpayInvoicing\ModuleServices;
use Symfony\Component\HttpFoundation\Response;

$request = CurrentRequest::get();
if (!$request->isMethod('POST')) {
    (new Response('', 405))->send();
    return;
}
$signature = $request->headers->get('Stripe-Signature') ?? '';
$status = (new ModuleServices())->webhook()->handle($request->getContent(), $signature);
(new Response('', $status))->send();
