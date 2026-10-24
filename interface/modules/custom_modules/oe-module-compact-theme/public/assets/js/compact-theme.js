/**
 * Annie's Compact Themes client script.
 *
 * Loaded in <head> on every OpenEMR page (main window and each tab's iframe)
 * before the module stylesheets. It reads the user's settings from its own
 * query string and tags <html> so compact-base.css and the theme pack can
 * scope everything:
 *
 *   data-cmt-theme   the selected theme pack id
 *   data-cmt-layout  top | rail
 *   data-cmt-density compact | cozy
 *   data-cmt-menu    both | icons | labels
 *   --cmt-accent     optional custom accent (6 hex digits)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

(function () {
    "use strict";

    const allowed = {
        l: ["top", "rail"],
        d: ["compact", "cozy"],
        m: ["both", "icons", "labels"]
    };

    const script = document.currentScript;
    if (!script || !script.src) {
        return;
    }

    const params = new URL(script.src, window.location.href).searchParams;
    const pick = function (key, fallback) {
        const value = params.get(key);
        return allowed[key].indexOf(value) !== -1 ? value : fallback;
    };

    // theme ids are folder names under public/themes (see ThemeManifest::ID_PATTERN)
    const theme = params.get("t");
    if (!theme || !/^[a-z0-9][a-z0-9_-]{0,31}$/.test(theme)) {
        return;
    }

    const root = document.documentElement;
    root.setAttribute("data-cmt-theme", theme);
    root.setAttribute("data-cmt-layout", pick("l", "top"));
    root.setAttribute("data-cmt-density", pick("d", "compact"));
    root.setAttribute("data-cmt-menu", pick("m", "both"));

    const accent = params.get("a");
    if (accent && /^[0-9a-f]{6}$/i.test(accent)) {
        root.style.setProperty("--cmt-accent", "#" + accent);
        // pick black or white text on the accent by relative luminance
        const channel = function (hex) {
            const c = parseInt(hex, 16) / 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        };
        const luminance = 0.2126 * channel(accent.slice(0, 2)) +
            0.7152 * channel(accent.slice(2, 4)) +
            0.0722 * channel(accent.slice(4, 6));
        root.style.setProperty("--cmt-on-accent", luminance > 0.4 ? "#0b0d10" : "#ffffff");
    }

    // Icon-only menu: the label text stays in the DOM for screen readers, and
    // becomes a hover tooltip on the top-level items.
    if (pick("m", "both") === "icons") {
        window.addEventListener("load", function () {
            const labels = document.querySelectorAll("#mainMenu .appMenu > div > .menuLabel, #mainMenu .appMenu > div > .menuSection > .menuLabel");
            labels.forEach(function (label) {
                const text = (label.textContent || "").trim();
                if (text && !label.getAttribute("title")) {
                    label.setAttribute("title", text);
                }
            });
        });
    }
})();
