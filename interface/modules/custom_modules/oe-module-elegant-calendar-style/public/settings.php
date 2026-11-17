<?php

/**
 * Annie's Elegant Calendar Style: settings page (admin only).
 * Site defaults for Hide Saturday / Hide Sunday (v1.3.1). Each user can
 * still override them from the calendar.
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
use OpenEMR\Modules\ElegantCalendarStyle\Config;
use OpenEMR\Modules\ElegantCalendarStyle\WeekendSettings;

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo xlt('Access denied');
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$settings = new WeekendSettings();
$message = '';
$messageClass = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token_form'] ?? '';
    if (!is_string($token) || !CsrfUtils::verifyCsrfToken($token, session: $session)) {
        CsrfUtils::csrfNotVerified();
    }
    try {
        $settings->saveSiteDefaults(!empty($_POST['hide_saturday']), !empty($_POST['hide_sunday']));
        $message = xlt('Settings saved.');
    } catch (Throwable $e) {
        error_log('oe-module-elegant-calendar-style settings: ' . $e::class);
        $message = xlt('Could not save settings.');
        $messageClass = 'danger';
    }
}

$site = $settings->siteDefaults();
$overrides = ['sat' => 0, 'sun' => 0];
try {
    foreach (WeekendSettings::DAYS as $day) {
        $overrides[$day] = (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS n FROM `user_settings` WHERE `setting_label` = ?',
            'n',
            [WeekendSettings::userLabel($day)]
        );
    }
} catch (Throwable) {
    // counts are informational only
}
$checked = static fn (bool $on): string => $on ? ' checked' : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo text("Annie's Elegant Calendar Style"); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top">
<div class="container-fluid mt-3">
    <h3><?php echo text("Annie's Elegant Calendar Style"); ?> <small class="text-muted">v<?php echo text(Config::VERSION); ?></small></h3>
    <?php if ($message !== '') { ?>
        <div class="alert alert-<?php echo attr($messageClass); ?>"><?php echo text($message); ?></div>
    <?php } ?>
    <form method="post" id="ecsSettingsForm">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $session)); ?>">
        <h5><?php echo xlt('Weekend days (site default)'); ?></h5>
        <p class="text-muted"><?php echo xlt('Applies to the calendar week and month views for every user who has not chosen otherwise. Users can override each day with the Hide: Sat | Sun toggles above the calendar.'); ?></p>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="hide_saturday" name="hide_saturday" value="1"<?php echo $checked($site['sat']); ?>>
            <label class="form-check-label" for="hide_saturday"><?php echo xlt('Hide Saturday'); ?></label>
            <small class="text-muted ml-2"><?php echo text(sprintf(xl('%d user(s) chose their own setting'), $overrides['sat'])); ?></small>
        </div>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="hide_sunday" name="hide_sunday" value="1"<?php echo $checked($site['sun']); ?>>
            <label class="form-check-label" for="hide_sunday"><?php echo xlt('Hide Sunday'); ?></label>
            <small class="text-muted ml-2"><?php echo text(sprintf(xl('%d user(s) chose their own setting'), $overrides['sun'])); ?></small>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo xlt('Save'); ?></button>
    </form>
</div>
</body>
</html>
