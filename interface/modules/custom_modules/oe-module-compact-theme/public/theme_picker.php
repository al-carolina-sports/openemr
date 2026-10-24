<?php

/**
 * Theme picker: Miscellaneous > Themes.
 *
 * Every user can choose their own theme pack, density, menu style and accent.
 * Users with Administration > Superuser access can also save the practice
 * default. Choices are stored in the same globals / user_settings rows that
 * Admin > Config and User Settings use.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\CurrentRequest;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\CompactTheme\Density;
use OpenEMR\Modules\CompactTheme\Layout;
use OpenEMR\Modules\CompactTheme\MenuStyle;
use OpenEMR\Modules\CompactTheme\ThemeChoice;
use OpenEMR\Modules\CompactTheme\ThemePreferences;
use OpenEMR\Modules\CompactTheme\ThemeRegistry;
use OpenEMR\Modules\CompactTheme\ThemeSettings;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId = $session->get('authUserID');
if (!is_numeric($authUserId) || (int)$authUserId <= 0) {
    echo xlt('Not authorized');
    exit;
}
$userId = (int)$authUserId;
$isAdmin = AclMain::aclCheckCore('admin', 'super');

$request = CurrentRequest::get();
$globals = OEGlobalsBag::getInstance();
$registry = new ThemeRegistry(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'themes');
$preferences = new ThemePreferences();

// ---- save (POST, then redirect so a refresh never re-submits) ----
if ($request->getMethod() === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($request->request->getString('csrf_token_form'), $session)) {
        CsrfUtils::csrfNotVerified();
    }
    $action = $request->request->getString('action');
    $status = 'invalid';
    if ($action === 'reset_me') {
        $preferences->clearForUser($userId);
        $status = 'reset';
    } else {
        $choice = ThemeChoice::fromPost($request->request, $registry);
        if ($choice !== null && $action === 'save_me') {
            $preferences->saveForUser($userId, $choice);
            $status = 'saved_me';
        } elseif ($choice !== null && $action === 'save_default' && $isAdmin) {
            $preferences->saveAsPracticeDefault($choice);
            $status = 'saved_default';
        }
    }
    header('Location: theme_picker.php?status=' . rawurlencode($status), true, 303);
    exit;
}

// ---- current values (the globals bag already holds this user's overrides) ----
$current = ThemeSettings::fromGlobals($globals, $registry);
$currentThemeId = $current->theme === null ? ThemeSettings::THEME_OFF : $current->theme->id;
$themes = $registry->all();
$hasOverride = $preferences->userHasOverride($userId);
$defaults = $preferences->practiceDefaults();
$defaultThemeId = $defaults[ThemeSettings::KEY_THEME] ?? ThemeSettings::DEFAULT_THEME_ID;
$defaultTheme = $defaultThemeId === ThemeSettings::THEME_OFF ? null : $registry->find($defaultThemeId);
$defaultThemeName = $defaultTheme === null ? xl('Off') : $defaultTheme->name;

$status = $request->query->getString('status');
$messages = [
    'saved_me' => xl('Saved for you. Reload OpenEMR to see it everywhere.'),
    'saved_default' => xl('Saved as the practice default. Users without their own choice get it after reloading OpenEMR.'),
    'reset' => xl('You are back on the practice default. Reload OpenEMR to see it everywhere.'),
    'invalid' => xl('That choice could not be saved. Pick an installed theme and try again.'),
];
$csrfToken = CsrfUtils::collectCsrfToken(session: $session);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Themes'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
      .cmt-picker { max-width: 1080px; }
      .cmt-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
      .cmt-option { display: block; cursor: pointer; margin: 0; }
      .cmt-option input { position: absolute; opacity: 0; pointer-events: none; }
      .cmt-tile { background: var(--cmt-surface, #fff); border: 2px solid var(--cmt-border, #dee2e6); border-radius: 10px; display: block; height: 100%; padding: 8px; }
      .cmt-option input:checked + .cmt-tile { border-color: var(--cmt-a, #007bff); }
      .cmt-option input:focus-visible + .cmt-tile { box-shadow: 0 0 0 3px var(--cmt-a-ring, rgba(0, 123, 255, 0.35)); }
      .cmt-pv { border: 1px solid; border-radius: 6px; display: flex; height: 92px; overflow: hidden; }
      .cmt-pv-rail { flex: 0 0 18px; }
      .cmt-pv-main { display: flex; flex: 1; flex-direction: column; gap: 5px; padding: 0 0 6px; }
      .cmt-pv-nav { align-items: center; border-bottom: 1px solid; display: flex; gap: 4px; height: 14px; padding: 0 6px; }
      .cmt-pv-nav i, .cmt-pv-card i { border-radius: 2px; display: block; height: 3px; }
      .cmt-pv-row { display: flex; gap: 5px; padding: 0 6px; }
      .cmt-pv-card { border: 1px solid; border-radius: 4px; display: flex; flex: 1; flex-direction: column; gap: 4px; height: 34px; padding: 5px; }
      .cmt-pv-btn { border-radius: 3px; display: block; height: 8px; margin-left: 6px; width: 28px; }
      .cmt-pv-off { align-items: center; background: repeating-linear-gradient(45deg, #f1f3f5, #f1f3f5 6px, #e9ecef 6px, #e9ecef 12px); color: #495057; justify-content: center; }
      .cmt-tile-name { font-weight: 600; margin-top: 8px; }
      .cmt-tile-meta { font-size: 0.85em; }
      .cmt-seg { display: inline-flex; flex-wrap: wrap; gap: 4px; }
      .cmt-seg label { border: 1px solid var(--cmt-border, #ced4da); border-radius: 6px; cursor: pointer; margin: 0; padding: 4px 10px; }
      .cmt-seg input { position: absolute; opacity: 0; pointer-events: none; }
      .cmt-seg input:checked + span { color: var(--cmt-a, #007bff); font-weight: 600; }
    </style>
</head>
<body>
<div class="container-fluid cmt-picker py-3">
    <h1 class="h4 mb-1"><?php echo xlt('Themes'); ?></h1>
    <p class="text-muted mb-3">
        <?php echo xlt('Choose how OpenEMR looks for you.'); ?>
        <?php echo xlt('Practice default'); ?>: <strong><?php echo text($defaultThemeName); ?></strong>.
        <?php echo $hasOverride ? xlt('You are using your own choice.') : xlt('You are using the practice default.'); ?>
    </p>

    <?php if (isset($messages[$status])) { ?>
        <div class="alert <?php echo $status === 'invalid' ? 'alert-danger' : 'alert-success'; ?> d-flex flex-wrap align-items-center justify-content-between" role="status">
            <span><?php echo text($messages[$status]); ?></span>
            <?php if ($status !== 'invalid') { ?>
                <button type="button" class="btn btn-sm btn-primary" id="cmt-reload"><?php echo xlt('Reload OpenEMR'); ?></button>
            <?php } ?>
        </div>
    <?php } ?>

    <form method="post" action="theme_picker.php">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">

        <fieldset class="mb-3">
            <legend class="h6"><?php echo xlt('Theme'); ?></legend>
            <div class="cmt-grid">
                <?php foreach ($themes as $theme) {
                    $c = $theme->preview; ?>
                    <label class="cmt-option">
                        <input type="radio" name="theme" value="<?php echo attr($theme->id); ?>" <?php echo $theme->id === $currentThemeId ? 'checked' : ''; ?>>
                        <span class="cmt-tile">
                            <span class="cmt-pv" aria-hidden="true" style="background: <?php echo attr($c['bg']); ?>; border-color: <?php echo attr($c['border']); ?>;">
                                <?php if ($theme->layout === Layout::Rail) { ?>
                                    <span class="cmt-pv-rail" style="background: <?php echo attr($c['rail']); ?>;"></span>
                                <?php } ?>
                                <span class="cmt-pv-main">
                                    <span class="cmt-pv-nav" style="background: <?php echo attr($c['nav']); ?>; border-color: <?php echo attr($c['border']); ?>;">
                                        <i style="background: <?php echo attr($c['accent']); ?>; width: 10px;"></i>
                                        <i style="background: <?php echo attr($c['muted']); ?>; width: 14px;"></i>
                                        <i style="background: <?php echo attr($c['muted']); ?>; width: 14px;"></i>
                                    </span>
                                    <span class="cmt-pv-row">
                                        <span class="cmt-pv-card" style="background: <?php echo attr($c['surface']); ?>; border-color: <?php echo attr($c['border']); ?>;">
                                            <i style="background: <?php echo attr($c['text']); ?>; width: 70%;"></i>
                                            <i style="background: <?php echo attr($c['muted']); ?>; width: 50%;"></i>
                                        </span>
                                        <span class="cmt-pv-card" style="background: <?php echo attr($c['surface']); ?>; border-color: <?php echo attr($c['border']); ?>;">
                                            <i style="background: <?php echo attr($c['text']); ?>; width: 60%;"></i>
                                            <i style="background: <?php echo attr($c['muted']); ?>; width: 40%;"></i>
                                        </span>
                                    </span>
                                    <span class="cmt-pv-btn" style="background: <?php echo attr($c['accent']); ?>;"></span>
                                </span>
                            </span>
                            <span class="d-block cmt-tile-name"><?php echo text($theme->name); ?></span>
                            <span class="d-block text-muted cmt-tile-meta"><?php echo text($theme->description); ?></span>
                        </span>
                    </label>
                <?php } ?>
                <label class="cmt-option">
                    <input type="radio" name="theme" value="<?php echo attr(ThemeSettings::THEME_OFF); ?>" <?php echo $currentThemeId === ThemeSettings::THEME_OFF ? 'checked' : ''; ?>>
                    <span class="cmt-tile">
                        <span class="cmt-pv cmt-pv-off" aria-hidden="true"><?php echo xlt('Stock'); ?></span>
                        <span class="d-block cmt-tile-name"><?php echo xlt('Off'); ?></span>
                        <span class="d-block text-muted cmt-tile-meta"><?php echo xlt('The standard OpenEMR look.'); ?></span>
                    </span>
                </label>
            </div>
        </fieldset>

        <div class="d-flex flex-wrap mb-3" style="gap: 24px;">
            <fieldset>
                <legend class="h6"><?php echo xlt('Density'); ?></legend>
                <div class="cmt-seg">
                    <?php foreach ([Density::Compact->value => xl('Compact'), Density::Cozy->value => xl('Cozy')] as $value => $label) { ?>
                        <label><input type="radio" name="density" value="<?php echo attr($value); ?>" <?php echo $current->density->value === $value ? 'checked' : ''; ?>><span><?php echo text($label); ?></span></label>
                    <?php } ?>
                </div>
            </fieldset>
            <fieldset>
                <legend class="h6"><?php echo xlt('Main menu'); ?></legend>
                <div class="cmt-seg">
                    <?php foreach (
                        [
                            MenuStyle::IconsAndLabels->value => xl('Icons and labels'),
                            MenuStyle::IconsOnly->value => xl('Icons only'),
                            MenuStyle::LabelsOnly->value => xl('Labels only'),
                        ] as $value => $label
                    ) { ?>
                        <label><input type="radio" name="menu_style" value="<?php echo attr($value); ?>" <?php echo $current->menuStyle->value === $value ? 'checked' : ''; ?>><span><?php echo text($label); ?></span></label>
                    <?php } ?>
                </div>
            </fieldset>
            <fieldset>
                <legend class="h6"><?php echo xlt('Accent color'); ?></legend>
                <div class="d-flex align-items-center" style="gap: 8px;">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="use_accent" name="use_accent" value="1" <?php echo $current->accent !== null ? 'checked' : ''; ?>>
                        <label class="custom-control-label" for="use_accent"><?php echo xlt('Use my own'); ?></label>
                    </div>
                    <label class="sr-only" for="accent"><?php echo xlt('Accent color'); ?></label>
                    <input type="color" id="accent" name="accent" value="#<?php echo attr($current->accent ?? '2563eb'); ?>">
                </div>
            </fieldset>
        </div>

        <div class="d-flex flex-wrap" style="gap: 8px;">
            <button type="submit" class="btn btn-primary" name="action" value="save_me"><?php echo xlt('Save for me'); ?></button>
            <?php if ($hasOverride) { ?>
                <button type="submit" class="btn btn-secondary" name="action" value="reset_me"><?php echo xlt('Use practice default'); ?></button>
            <?php } ?>
            <?php if ($isAdmin) { ?>
                <button type="submit" class="btn btn-secondary" name="action" value="save_default"><?php echo xlt('Save as practice default'); ?></button>
            <?php } ?>
        </div>
    </form>
</div>
<script>
    (function () {
        "use strict";
        const reload = document.getElementById("cmt-reload");
        if (reload) {
            reload.addEventListener("click", function () {
                // the theme and menu are applied when the main window loads
                (window.top || window).location.reload();
            });
        }
    })();
</script>
</body>
</html>
