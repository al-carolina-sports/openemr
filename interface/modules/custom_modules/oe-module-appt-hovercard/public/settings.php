<?php

/**
 * Appointment Hover Card: configuration page (admin only).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../globals.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ApptHoverCard\Bootstrap;
use OpenEMR\Modules\ApptHoverCard\FieldCatalog;
use OpenEMR\Modules\ApptHoverCard\RoleResolver;
use OpenEMR\Modules\ApptHoverCard\Settings;
use OpenEMR\Modules\ApptHoverCard\SettingsRepository;

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo xlt('Access denied');
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$repo = new SettingsRepository();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token_form'] ?? '';
    if (!is_string($token) || !CsrfUtils::verifyCsrfToken($token, session: $session)) {
        CsrfUtils::csrfNotVerified();
    }
    try {
        if (isset($_POST['restore_defaults'])) {
            $repo->restoreDefaults();
            $message = xlt('Defaults restored.');
        } else {
            $repo->save($_POST);
            $message = xlt('Settings saved.');
        }
    } catch (Throwable $e) {
        error_log('oe-module-appt-hovercard settings: ' . $e::class);
        $message = xlt('Could not save settings.');
    }
}

$settings = $repo->all();
$roles = (new RoleResolver())->allTitles();
$catalog = FieldCatalog::all();
$layoutFields = QueryUtils::fetchRecords(
    "SELECT `field_id`, `title` FROM `layout_options` WHERE `form_id` = 'DEM' AND `uor` > 0 ORDER BY `title`"
);
$assets = OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_PATH . '/public/assets/';
$checked = static fn (bool $on): string => $on ? ' checked' : '';
$hasRole = static fn (string $key, string $role): bool => in_array($role, (array)$settings[$key], true);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Appointment Hover Card'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <link rel="stylesheet" href="<?php echo attr($assets); ?>hovercard.css">
</head>
<body class="body_top">
<div class="container-fluid mt-3">
    <h3><?php echo xlt('Appointment Hover Card'); ?></h3>
    <?php if ($message !== '') { ?>
        <div class="alert alert-info"><?php echo text($message); ?></div>
    <?php } ?>

    <form method="post" id="hcForm">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $session)); ?>">

        <h5><?php echo xlt('General'); ?></h5>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="enabled" name="enabled" value="1"<?php echo $checked((bool)$settings['enabled']); ?>>
            <label class="form-check-label" for="enabled"><?php echo xlt('Enable hover card (off restores the native tooltip)'); ?></label>
        </div>
        <div class="form-group">
            <label><?php echo xlt('Calendar views'); ?></label><br>
            <?php foreach (Settings::VIEWS as $view) { ?>
                <label class="mr-3">
                    <input type="checkbox" name="views[]" value="<?php echo attr($view); ?>"<?php echo $checked(in_array($view, (array)$settings['views'], true)); ?>>
                    <?php echo text(ucfirst($view)); ?>
                </label>
            <?php } ?>
        </div>
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="hover_delay_ms"><?php echo xlt('Hover delay (ms)'); ?></label>
                <input type="number" class="form-control" id="hover_delay_ms" name="hover_delay_ms" min="0" max="5000" value="<?php echo attr((string)$settings['hover_delay_ms']); ?>">
            </div>
            <div class="form-group col-md-3">
                <label for="hide_delay_ms"><?php echo xlt('Hide delay (ms)'); ?></label>
                <input type="number" class="form-control" id="hide_delay_ms" name="hide_delay_ms" min="0" max="5000" value="<?php echo attr((string)$settings['hide_delay_ms']); ?>">
            </div>
            <div class="form-group col-md-3">
                <label for="card_width"><?php echo xlt('Card width (px)'); ?></label>
                <input type="number" class="form-control" id="card_width" name="card_width" min="240" max="640" value="<?php echo attr((string)$settings['card_width']); ?>">
            </div>
        </div>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="click_mode" name="click_mode" value="1"<?php echo $checked((bool)$settings['click_mode']); ?>>
            <label class="form-check-label" for="click_mode"><?php echo xlt('Show on click instead of hover (touch screens)'); ?></label>
        </div>

        <h5 class="mt-4"><?php echo xlt('Fields'); ?></h5>
        <p class="text-muted"><?php echo xlt('Lower order numbers appear first within their block. Empty fields are hidden on the card.'); ?></p>
        <table class="table table-sm">
            <thead>
            <tr>
                <th><?php echo xlt('Show'); ?></th>
                <th><?php echo xlt('Block'); ?></th>
                <th><?php echo xlt('Field'); ?></th>
                <th><?php echo xlt('Order'); ?></th>
                <th><?php echo xlt('Label'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array)$settings['fields'] as $key => $field) { ?>
                <tr data-field="<?php echo attr((string)$key); ?>">
                    <td><input type="checkbox" name="fields[<?php echo attr((string)$key); ?>][show]" value="1"<?php echo $checked((bool)$field['show']); ?>></td>
                    <td><?php echo text($catalog[$key]['block']); ?></td>
                    <td><?php echo text($catalog[$key]['label']); ?><?php echo $catalog[$key]['sensitive'] ? ' <span class="badge badge-secondary">' . xlt('DOB/contact roles') . '</span>' : ''; ?></td>
                    <td><input type="number" class="form-control form-control-sm" style="width:5rem" min="0" max="999" name="fields[<?php echo attr((string)$key); ?>][order]" value="<?php echo attr((string)$field['order']); ?>"></td>
                    <td><input type="text" class="form-control form-control-sm" maxlength="60" name="fields[<?php echo attr((string)$key); ?>][label]" value="<?php echo attr((string)$field['label']); ?>"></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

        <div class="form-row">
            <?php foreach (['source_preferred_contact' => 'Preferred contact method field', 'source_best_time' => 'Best time to call field'] as $name => $title) { ?>
                <div class="form-group col-md-4">
                    <label for="<?php echo attr($name); ?>"><?php echo xlt($title); ?></label>
                    <select class="form-control" id="<?php echo attr($name); ?>" name="<?php echo attr($name); ?>">
                        <option value=""><?php echo xlt('Not set up yet'); ?></option>
                        <?php foreach ($layoutFields as $lf) { ?>
                            <option value="<?php echo attr((string)$lf['field_id']); ?>"<?php echo $settings[$name] === $lf['field_id'] ? ' selected' : ''; ?>>
                                <?php echo text((string)$lf['title'] . ' (' . (string)$lf['field_id'] . ')'); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
            <?php } ?>
        </div>
        <p class="text-muted"><?php echo xlt('Create these two fields first under Admin > Forms > Layouts (Demographics), then pick them here.'); ?></p>

        <h5 class="mt-4"><?php echo xlt('Photo'); ?></h5>
        <div class="form-row">
            <div class="form-group col-md-3 form-check">
                <input type="checkbox" class="form-check-input" id="photo_show" name="photo_show" value="1"<?php echo $checked((bool)$settings['photo_show']); ?>>
                <label class="form-check-label" for="photo_show"><?php echo xlt('Show photo'); ?></label>
            </div>
            <div class="form-group col-md-3">
                <label for="photo_category"><?php echo xlt('Document category'); ?></label>
                <input type="text" class="form-control" id="photo_category" name="photo_category" value="<?php echo attr((string)$settings['photo_category']); ?>">
            </div>
            <div class="form-group col-md-3">
                <label for="photo_size"><?php echo xlt('Size'); ?></label>
                <select class="form-control" id="photo_size" name="photo_size">
                    <?php foreach (Settings::PHOTO_SIZES as $size => $px) { ?>
                        <option value="<?php echo attr($size); ?>"<?php echo $settings['photo_size'] === $size ? ' selected' : ''; ?>><?php echo text(ucfirst($size) . ' (' . $px . ' px)'); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group col-md-3">
                <label for="photo_missing"><?php echo xlt('No photo on file'); ?></label>
                <select class="form-control" id="photo_missing" name="photo_missing">
                    <option value="initials"<?php echo $settings['photo_missing'] === 'initials' ? ' selected' : ''; ?>><?php echo xlt('Show initials'); ?></option>
                    <option value="hide"<?php echo $settings['photo_missing'] === 'hide' ? ' selected' : ''; ?>><?php echo xlt('Hide area'); ?></option>
                </select>
            </div>
        </div>

        <h5 class="mt-4"><?php echo xlt('Privacy and access'); ?></h5>
        <?php
        foreach (['roles_card' => 'Roles that see the card (others keep the native tooltip)', 'roles_contact' => 'Roles that see DOB and contact details (others see the appointment block only)'] as $name => $title) { ?>
            <div class="form-group">
                <label><?php echo xlt($title); ?></label><br>
                <label class="mr-3">
                    <input type="checkbox" name="<?php echo attr($name); ?>[]" value="<?php echo attr(Settings::ALL_ROLES); ?>"<?php echo $checked($hasRole($name, Settings::ALL_ROLES)); ?>>
                    <strong><?php echo xlt('All roles'); ?></strong>
                </label>
                <?php foreach ($roles as $role) { ?>
                    <label class="mr-3">
                        <input type="checkbox" name="<?php echo attr($name); ?>[]" value="<?php echo attr($role); ?>"<?php echo $checked($hasRole($name, $role)); ?>>
                        <?php echo text($role); ?>
                    </label>
                <?php } ?>
            </div>
        <?php } ?>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="mask_phone" name="mask_phone" value="1"<?php echo $checked((bool)$settings['mask_phone']); ?>>
            <label class="form-check-label" for="mask_phone"><?php echo xlt('Mask phone numbers (last 4 digits only)'); ?></label>
        </div>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="audit_views" name="audit_views" value="1"<?php echo $checked((bool)$settings['audit_views']); ?>>
            <label class="form-check-label" for="audit_views"><?php echo xlt('Log card views to the audit log (one entry per patient per session)'); ?></label>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo xlt('Save'); ?></button>
        <button type="submit" name="restore_defaults" value="1" class="btn btn-secondary" onclick="return confirm(<?php echo attr_js(xla('Restore all settings to their defaults?')); ?>);"><?php echo xlt('Restore defaults'); ?></button>
        <button type="button" class="btn btn-outline-secondary" id="previewBtn"><?php echo xlt('Preview'); ?></button>
    </form>

    <div id="previewArea" class="mt-4" style="position:relative;"></div>
</div>

<script src="<?php echo attr($assets); ?>hovercard.js"></script>
<script>
    (function () {
        var sample = {
            time_range: '9:30–9:45 AM', provider: 'Alex Provider', facility: 'Main Office', reason: 'Follow-up',
            comments: 'Sample comment', status: 'Arrived', name: 'Sam Sample (Sammy)', dob_age: '1980-04-02 (46)',
            insurance: 'Sample Health Plan', balance: '$0.00', last_visit: '2026-09-15', email: 'sam@example.com',
            cell: '(555) 555-0100', other_phones: '(555) 555-0101', preferred_contact: 'Text', best_time: 'Mornings'
        };
        var blockOf = <?php echo json_encode(array_map(static fn (array $d): string => $d['block'], $catalog), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        document.getElementById('previewBtn').addEventListener('click', function () {
            var blocks = {appointment: [], patient: [], contact: []};
            document.querySelectorAll('tr[data-field]').forEach(function (row) {
                var key = row.getAttribute('data-field');
                if (!row.querySelector('input[type=checkbox]').checked) { return; }
                blocks[blockOf[key]].push({
                    order: parseInt(row.querySelector('input[type=number]').value, 10) || 0,
                    key: key,
                    label: row.querySelector('input[type=text]').value,
                    value: sample[key]
                });
            });
            Object.keys(blocks).forEach(function (b) {
                blocks[b].sort(function (x, y) { return x.order - y.order; });
            });
            var width = parseInt(document.getElementById('card_width').value, 10) || 360;
            var photo = document.getElementById('photo_show').checked ? {url: null, initials: 'SS', size: 100} : null;
            var area = document.getElementById('previewArea');
            area.textContent = '';
            var wrap = document.createElement('div');
            wrap.className = 'oe-hc-wrap';
            wrap.style.position = 'static';
            wrap.style.display = 'inline-block';
            wrap.appendChild(window.oeApptHoverCard.renderCard({blocks: blocks, photo: photo}, width));
            area.appendChild(wrap);
        });
    })();
</script>
</body>
</html>
