<?php

/**
 * The shared OpenEMR Twig environment with the module's templates
 * registered under the @cashpay namespace.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing;

use OpenEMR\BC\ServiceContainer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ModuleTwig
{
    public static function environment(): Environment
    {
        $twig = ServiceContainer::getTwig();
        $loader = $twig->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            throw new \LogicException('Twig loader cannot take the module templates');
        }
        if (!in_array('cashpay', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__) . '/templates', 'cashpay');
        }
        return $twig;
    }
}
