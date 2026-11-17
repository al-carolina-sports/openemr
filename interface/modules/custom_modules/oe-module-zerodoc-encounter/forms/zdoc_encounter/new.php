<?php

/**
 * "ZeroDoc Encounter Note" encounter form.
 *
 * Loaded by core's interface/patient_file/encounter/load_form.php (which fixes
 * the page name as new.php) and by view_form.php for an existing note, in both
 * cases through LoadEncounterFormFilterEvent -- see src/Bootstrap.php. Core has
 * already run AclMain::aclCheckForm('zdoc_encounter') and the sensitivity check
 * by the time this file is included.
 *
 * This is the scaffold proving the forms pattern end to end: one free-text
 * field that round-trips to form_zdoc_encounter. ZeroDoc's real sections
 * replace the field block below once the clinical schema lands.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../../../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Modules\ZeroDoc\Bootstrap;
use OpenEMR\Modules\ZeroDoc\Forms;

$zdocSession = SessionWrapperFactory::getInstance()->getActiveSession();
// view.php sets these when an existing note is being reopened.
$zdocEdit = $zdocEdit ?? null;
$zdocFormId = $zdocFormId ?? 0;
// Set by the wrapper in each form directory; defaults to this one.
$zdocFormDir = $zdocFormDir ?? 'zdoc_encounter';
$zdocMeta = Forms::meta($zdocFormDir);
$zdocBase = Bootstrap::webPath();
$zdocAction = $zdocBase . '/public/save.php?dir=' . urlencode($zdocFormDir)
    . '&mode=' . ($zdocEdit ? 'update&id=' . (int)$zdocFormId : 'new');
$zdocVal = static fn(string $k): string => (string)($zdocEdit[$k] ?? '');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo text($zdocMeta['form_name']); ?></title>
    <?php Header::setupHeader(); ?>
    <link rel="stylesheet" href="<?php echo attr(Bootstrap::asset('/public/assets/zerodoc.css')); ?>">
</head>
<body>
<div class="container-fluid mt-3 zdoc">
    <h2><?php echo text($zdocMeta['form_name']); ?></h2>

    <div class="zdoc-banner" id="zdoc-banner"
         data-endpoint="<?php echo attr($zdocBase . '/public/api.php'); ?>"
         data-csrf="<?php echo attr(CsrfUtils::collectCsrfToken(session: $zdocSession)); ?>">
        <?php echo xlt('Checking the ZeroDoc workflow service'); ?>&hellip;
    </div>

    <form method="post" id="zdoc-form" action="<?php echo attr($zdocAction); ?>" onsubmit="return top.restoreSession()">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $zdocSession)); ?>">

        <!-- SCAFFOLD FIELDS: replaced by ZeroDoc's real sections. -->
        <div class="form-group">
            <label for="zdoc-body" class="font-weight-bold"><?php echo xlt('Note'); ?></label>
            <textarea class="form-control" id="zdoc-body" name="body" rows="10"><?php echo text($zdocVal('body')); ?></textarea>
        </div>
        <!-- END SCAFFOLD FIELDS -->

        <div class="btn-group mb-4">
            <button type="submit" class="btn btn-primary btn-save" id="zdoc-save"><?php echo xlt('Save'); ?></button>
            <button type="button" class="btn btn-secondary btn-cancel"
                    onclick="top.restoreSession(); parent.closeTab(window.name, false);"><?php echo xlt('Cancel'); ?></button>
        </div>
    </form>
</div>
<script src="<?php echo attr(Bootstrap::asset('/public/assets/zerodoc.js')); ?>"></script>
</body>
</html>
