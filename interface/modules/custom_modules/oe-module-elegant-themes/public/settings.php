<?php

/**
 * Elegant Themes picker (Miscellaneous > Elegant Themes, Admin > Elegant Themes).
 * Any logged-in user picks their own theme; admin/super can also set the site default.
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
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Modules\ElegantThemes\ThemeRepository;
use OpenEMR\Modules\ElegantThemes\Themes;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$userId = (int)$session->get('authUserID');
if ($userId <= 0) {
    http_response_code(403);
    echo xlt('Not authorized');
    exit;
}
$isAdmin = AclMain::aclCheckCore('admin', 'super');
$repo = new ThemeRepository();
$saved = false;
$error = '';

$valid = static fn(string $v): bool => $v === Themes::OFF || $v === Themes::SITE || Themes::isTheme($v);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);
    $mine = (string)($_POST['my_theme'] ?? Themes::SITE);
    if ($valid($mine)) {
        $repo->save($userId, $mine);
        $saved = true;
    } else {
        $error = xl('Unknown theme');
    }
    if ($isAdmin && isset($_POST['site_theme'])) {
        $site = (string)$_POST['site_theme'];
        if ($site !== Themes::SITE && $valid($site)) {
            $repo->save(0, $site);
        }
    }
}

$myRaw = $repo->getRaw($userId) ?? Themes::SITE;
$siteDefault = $repo->getSiteDefault();
$active = $repo->resolve($userId);

$options = [Themes::SITE => ['name' => xl('Site default'), 'blurb' => xl('Follow the theme chosen by the administrator.'), 'swatch' => []],
            Themes::OFF => ['name' => xl('Off (OpenEMR theme)'), 'blurb' => xl('No module theme; your normal OpenEMR theme (e.g. Solar).'), 'swatch' => []]]
    + Themes::ALL;
$iconBase = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-elegant-themes/public/assets/icons/';
$shotBase = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-elegant-themes/public/assets/screens/';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt("Elegant Themes"); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .aet-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px; }
        .aet-opt { display: block; border: 1px solid rgba(128,128,128,.35); border-radius: 6px; padding: 8px 10px; cursor: pointer; margin: 0; }
        .aet-opt:hover { border-color: rgba(128,128,128,.8); }
        .aet-opt input { margin-right: 6px; }
        .aet-opt.on { outline: 2px solid currentColor; }
        .aet-sw { display: inline-block; width: 18px; height: 18px; border-radius: 3px; margin-right: 3px; border: 1px solid rgba(0,0,0,.2); vertical-align: middle; }
        .aet-blurb { font-size: .85em; opacity: .8; margin-top: 2px; }
        .aet-meta { font-size: .78em; margin-top: 4px; }
        .aet-menu { display: inline-block; padding: 0 6px; border-radius: 9px; border: 1px solid currentColor; font-weight: 600; margin-right: 6px; }
        .aet-desc { font-size: .82em; margin-top: 4px; }
        .aet-suits { font-size: .8em; opacity: .8; margin-top: 2px; }
        .aet-shots { display: flex; gap: 6px; margin-top: 6px; }
        .aet-shots a { flex: 1; display: block; text-align: center; font-size: .72em; opacity: .85; text-decoration: none; color: inherit; }
        .aet-shots img { width: 100%; aspect-ratio: 16/10; object-fit: cover; object-position: top left; border: 1px solid rgba(128,128,128,.4); border-radius: 4px; display: block; }
        .aet-shots a:hover img { border-color: currentColor; }
        #aet-lb { position: fixed; inset: 0; background: rgba(0,0,0,.82); display: none; align-items: center; justify-content: center; flex-direction: column; z-index: 2000; cursor: zoom-out; }
        #aet-lb.open { display: flex; }
        #aet-lb img { max-width: 94vw; max-height: 86vh; border-radius: 4px; box-shadow: 0 6px 30px rgba(0,0,0,.6); }
        #aet-lb div { color: #fff; margin-top: 8px; font-size: .9em; }
        .aet-icons img { width: 20px; height: 20px; margin: 2px; opacity: .8; }
    </style>
</head>
<body class="body_top">
<div class="container-fluid mt-2">
    <h4><?php echo text("Annie's Elegant Themes"); ?> <small class="text-muted">v<?php echo text(Themes::VERSION); ?></small></h4>
    <?php if ($saved) { ?>
        <div class="alert alert-success py-1" id="aet-saved"><?php echo xlt('Saved. Reloading OpenEMR to apply the theme...'); ?></div>
    <?php } elseif ($error !== '') { ?>
        <div class="alert alert-danger py-1"><?php echo text($error); ?></div>
    <?php } ?>
    <p class="mb-2"><?php echo xlt('Currently active for you'); ?>: <strong id="aet-active"><?php echo text($active === Themes::OFF ? xl('Off (OpenEMR theme)') : Themes::ALL[$active]['name']); ?></strong></p>
    <form method="post" id="aet-form">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $session)); ?>" />
        <h6><?php echo xlt('My theme'); ?></h6>
        <div class="aet-grid mb-3">
            <?php foreach ($options as $key => $o) { ?>
                <label class="aet-opt<?php echo $myRaw === $key ? ' on' : ''; ?>">
                    <input type="radio" name="my_theme" value="<?php echo attr($key); ?>" <?php echo $myRaw === $key ? 'checked' : ''; ?> />
                    <strong><?php echo text($o['name']); ?></strong>
                    <?php foreach ($o['swatch'] as $c) { ?><span class="aet-sw" style="background:<?php echo attr($c); ?>"></span><?php } ?>
                    <div class="aet-blurb"><?php echo text($o['blurb']); ?></div>
                    <?php if (isset($o['menu'])) { ?>
                        <div class="aet-meta"><span class="aet-menu"><?php echo $o['menu'] === 'left' ? xlt('Menu: left icon rail') : xlt('Menu: top bar'); ?></span><?php echo text($o['density']); ?></div>
                        <div class="aet-desc"><?php echo text($o['desc']); ?></div>
                        <div class="aet-suits"><strong><?php echo xlt('Suits'); ?>:</strong> <?php echo text($o['suits']); ?></div>
                        <div class="aet-shots">
                            <?php foreach (['calendar' => xl('Calendar week'), 'hovercard' => xl('Patient hover card')] as $shot => $label) {
                                if (!is_file(__DIR__ . '/assets/screens/' . $key . '-' . $shot . '.jpg')) {
                                    continue;
                                }
                                $src = $shotBase . $key . '-' . $shot . '.jpg?v=' . Themes::VERSION; ?>
                                <a href="<?php echo attr($src); ?>" class="aet-zoom" data-cap="<?php echo attr($o['name'] . ' - ' . $label); ?>"><img src="<?php echo attr($src); ?>" alt="<?php echo attr($o['name'] . ' - ' . $label); ?>" loading="lazy" /><?php echo text($label); ?></a>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </label>
            <?php } ?>
        </div>
        <?php if ($isAdmin) { ?>
            <h6><?php echo xlt('Site default (users who chose "Site default")'); ?></h6>
            <select name="site_theme" class="form-control form-control-sm mb-3" style="max-width: 320px;">
                <option value="" <?php echo $siteDefault === Themes::OFF ? 'selected' : ''; ?>><?php echo xlt('Off (OpenEMR theme)'); ?></option>
                <?php foreach (Themes::ALL as $key => $o) { ?>
                    <option value="<?php echo attr($key); ?>" <?php echo $siteDefault === $key ? 'selected' : ''; ?>><?php echo text($o['name']); ?></option>
                <?php } ?>
            </select>
        <?php } ?>
        <button type="submit" class="btn btn-primary btn-sm btn-save"><?php echo xlt('Save and apply'); ?></button>
    </form>
    <p class="text-muted small mt-3 mb-1"><?php echo xlt('Menu icon set'); ?>:</p>
    <div class="aet-icons">
        <?php foreach (glob(__DIR__ . '/assets/icons/*.svg') ?: [] as $f) {
            $n = basename($f, '.svg'); ?><img src="<?php echo attr($iconBase . $n . '.svg'); ?>" alt="<?php echo attr($n); ?>" title="<?php echo attr($n); ?>" /><?php } ?>
    </div>
</div>
<div id="aet-lb" role="dialog" aria-modal="true"><img alt="" /><div></div></div>
<script>
    (function () {
        var lb = document.getElementById('aet-lb');
        document.querySelectorAll('.aet-zoom').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation(); // do not toggle the card radio
                lb.querySelector('img').src = a.getAttribute('href');
                lb.querySelector('div').textContent = a.getAttribute('data-cap') + ' (' + <?php echo xlj('click or Esc to close'); ?> + ')';
                lb.classList.add('open');
            });
        });
        lb.addEventListener('click', function () { lb.classList.remove('open'); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { lb.classList.remove('open'); } });
    })();
    document.querySelectorAll('.aet-opt input').forEach(function (r) {
        r.addEventListener('change', function () {
            document.querySelectorAll('.aet-opt').forEach(function (l) { l.classList.toggle('on', l.contains(r) && r.checked); });
        });
    });
    <?php if ($saved) { ?>
    setTimeout(function () { try { top.location.reload(); } catch (e) { location.reload(); } }, 600);
    <?php } ?>
</script>
</body>
</html>
