<?php

/**
 * Admin > ZeroDoc Workflow: a read-only "did it load?" page.
 *
 * Shows the module version, the registered encounter forms with their
 * registry.state, the installed schema revisions, and whether the modules
 * ZeroDoc can cooperate with are enabled. This is the verification surface
 * referenced in README.md and DEPLOY.md.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../globals.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\Header;
use OpenEMR\Modules\ZeroDoc\Bootstrap;
use OpenEMR\Modules\ZeroDoc\Capability;
use OpenEMR\Modules\ZeroDoc\Forms;
use OpenEMR\Modules\ZeroDoc\SchemaVersion;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo xlt('Not authorized');
    exit;
}

$registryRows = QueryUtils::fetchRecords(
    'SELECT `directory`, `name`, `state`, `category`, `aco_spec` FROM `registry` WHERE `directory` IN ('
    . implode(', ', array_fill(0, count(Forms::formdirs()), '?')) . ') ORDER BY `directory`',
    Forms::formdirs()
);

$neighbours = [
    'AI Scribe' => Capability::AI_SCRIBE,
    'Cash-Pay Invoicing' => Capability::CASHPAY,
    'Prior Authorizations' => Capability::PRIOR_AUTH,
    'Fax / SMS' => Capability::FAXSMS,
    'Weno eRx' => Capability::WENO,
    'DORN labs' => Capability::DORN,
    'Telehealth' => Capability::TELEHEALTH,
    'EHI Exporter' => Capability::EHI_EXPORTER,
];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('ZeroDoc Workflow'); ?></title>
    <?php Header::setupHeader(); ?>
    <link rel="stylesheet" href="<?php echo attr(Bootstrap::asset('/public/assets/zerodoc.css')); ?>">
</head>
<body>
<div class="container-fluid mt-3 zdoc">
    <h2><?php echo xlt('ZeroDoc Clinical Encounter Workflow'); ?></h2>
    <p class="text-muted"><?php echo xlt('Version'); ?> <?php echo text(Bootstrap::ASSET_VERSION); ?></p>

    <h4><?php echo xlt('Encounter forms'); ?></h4>
    <?php if ($registryRows === []) { ?>
        <div class="alert alert-warning">
            <?php echo xlt('No registry rows found. The install SQL has not been run; see DEPLOY.md.'); ?>
        </div>
    <?php } else { ?>
    <table class="table table-sm w-auto">
        <thead><tr>
            <th><?php echo xlt('Directory'); ?></th>
            <th><?php echo xlt('Name'); ?></th>
            <th><?php echo xlt('Category'); ?></th>
            <th><?php echo xlt('ACO'); ?></th>
            <th><?php echo xlt('Shown'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($registryRows as $row) { ?>
            <tr>
                <td><code><?php echo text((string)$row['directory']); ?></code></td>
                <td><?php echo text((string)$row['name']); ?></td>
                <td><?php echo text((string)$row['category']); ?></td>
                <td><code><?php echo text((string)$row['aco_spec']); ?></code></td>
                <td><?php echo ((int)$row['state'] === 1) ? xlt('yes') : xlt('no'); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php } ?>

    <h4><?php echo xlt('Schema'); ?></h4>
    <?php $schema = SchemaVersion::all(); ?>
    <?php if ($schema === []) { ?>
        <div class="alert alert-warning"><?php echo xlt('zdoc_schema_version is missing or empty; the install SQL has not been run.'); ?></div>
    <?php } else { ?>
    <table class="table table-sm w-auto">
        <thead><tr>
            <th><?php echo xlt('Component'); ?></th>
            <th><?php echo xlt('Revision'); ?></th>
            <th><?php echo xlt('Applied'); ?></th>
            <th><?php echo xlt('Note'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($schema as $row) { ?>
            <tr>
                <td><code><?php echo text($row['component']); ?></code></td>
                <td><?php echo text((string)$row['version']); ?></td>
                <td><?php echo text($row['applied_at']); ?></td>
                <td><?php echo text($row['note'] ?? ''); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php } ?>

    <h4><?php echo xlt('Modules ZeroDoc can work with'); ?></h4>
    <p class="text-muted small">
        <?php echo xlt('ZeroDoc degrades rather than failing when one of these is off. Nothing here is a hard requirement.'); ?>
    </p>
    <table class="table table-sm w-auto">
        <tbody>
        <?php foreach ($neighbours as $label => $dir) { ?>
            <tr>
                <td><?php echo text($label); ?></td>
                <td><code><?php echo text($dir); ?></code></td>
                <td><?php echo Capability::isEnabled($dir) ? xlt('enabled') : xlt('off'); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
</body>
</html>
