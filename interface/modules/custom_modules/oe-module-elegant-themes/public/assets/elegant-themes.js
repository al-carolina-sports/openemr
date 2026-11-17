/**
 * Annie's Elegant Themes: pairs an icon with each top-level main menu label.
 * Loaded on every page through ScriptFilterEvent; only acts where the main
 * menu (#mainMenu, rendered by knockout) exists. Read-only DOM decoration.
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var iconBase = script ? script.src.replace(/elegant-themes\.js.*$/, 'icons/') : '';
    var BY_ID = {
        cal0: 'calendar', fin0: 'search', msg0: 'messages', patimg: 'patients',
        groupimg: 'users', modimg: 'settings', invimg: 'inventory', proimg: 'labs',
        admimg: 'admin', repimg: 'reports', misimg: 'notes', popup: 'documents'
    };
    var BY_LABEL = {
        'calendar': 'calendar', 'finder': 'search', 'flow': 'check-in', 'recalls': 'waitlist',
        'messages': 'messages', 'patient': 'patients', 'groups': 'users', 'fees': 'billing',
        'modules': 'settings', 'inventory': 'inventory', 'procedures': 'labs', 'admin': 'admin',
        'reports': 'reports', 'miscellaneous': 'notes', 'popups': 'documents', 'ensora erx': 'prescriptions',
        'telehealth': 'telehealth', 'fax/sms': 'messages'
    };

    function iconFor(el) {
        var data = null;
        try { data = window.ko ? window.ko.dataFor(el) : null; } catch (e) { data = null; }
        var label = (el.textContent || '').trim().toLowerCase();
        if (BY_LABEL[label]) { return BY_LABEL[label]; }
        if (data && data.menu_id && BY_ID[data.menu_id]) { return BY_ID[data.menu_id]; }
        return 'dashboard';
    }

    function decorate() {
        var menu = document.querySelector('#mainMenu .appMenu');
        if (!menu) { return false; }
        var labels = menu.querySelectorAll(':scope > div > .menuLabel, :scope > div > .menuSection > .menuLabel');
        labels.forEach(function (el) {
            if (el.getAttribute('data-aet-icon')) { return; }
            var name = iconFor(el);
            el.setAttribute('data-aet-icon', name);
            var i = document.createElement('span');
            i.className = 'aet-ico';
            i.setAttribute('aria-hidden', 'true');
            i.style.setProperty('--aet-ico', 'url("' + iconBase + name + '.svg")');
            // keep knockout's text binding intact: wrap existing text in a span, put icon before it
            el.insertBefore(i, el.firstChild);
        });
        // v1.2.2: short rail label for Miscellaneous (display only; full name kept as tooltip)
        labels.forEach(function (el) {
            Array.prototype.forEach.call(el.childNodes, function (n) {
                if (n.nodeType === 3 && n.nodeValue.trim() === 'Miscellaneous') {
                    n.nodeValue = n.nodeValue.replace('Miscellaneous', 'Misc');
                    el.setAttribute('title', 'Miscellaneous');
                }
            });
        });
        return labels.length > 0;
    }


    // v1.2.1: keep left-rail flyouts inside the viewport (rail themes make top-level flyouts position:fixed)
    // v1.5.2: also reserve the + APPT / + OFFICE footer so the last items (e.g. Cash-Pay Setup under Admin) stay reachable
    function footerReserve() {
        var footer = document.getElementById('aet-footer');
        if (!footer || !document.documentElement.classList.contains('aet-has-footer')) { return 0; }
        var cs = window.getComputedStyle(footer);
        if (cs.display === 'none' || cs.visibility === 'hidden') { return 0; }
        var h = footer.getBoundingClientRect().height;
        return h > 0 ? h : 34;
    }
    function placeFlyout(section, keepTop) {
        var panel = section && section.querySelector(':scope > .menuEntries');
        if (!panel || window.getComputedStyle(panel).position !== 'fixed') { return; }
        var label = section.querySelector(':scope > .menuLabel') || section;
        var gap = 8, reserved = footerReserve(), vh = window.innerHeight, floor = reserved + gap;
        var maxH = Math.max(80, vh - gap - floor);
        panel.style.setProperty('margin-top', '0px', 'important');
        panel.style.setProperty('max-height', maxH + 'px', 'important');
        var top = label.getBoundingClientRect().top;
        // accordion toggles keep the flyout where it is (only nudge up if it now overflows), so it never jumps from under the pointer
        if (keepTop && panel.style.top) { top = Math.min(parseFloat(panel.style.top), top); }
        var h = Math.min(panel.scrollHeight, maxH);
        if (top + h > vh - floor) { top = Math.max(gap, vh - floor - h); }
        panel.style.setProperty('top', Math.round(top) + 'px', 'important');
    }

    // v1.5.6: the avatar toggle (#username) is a plain div - make it reachable and operable from the keyboard
    // (Tab, Enter/Space opens, Arrow keys move through the items via Bootstrap, Escape closes and returns focus)
    function bindUserMenuKeys() {
        var t = document.getElementById('username'), ul = document.getElementById('userdropdown');
        if (!t || !ul || t.getAttribute('data-aet-keys')) { return; }
        t.setAttribute('data-aet-keys', '1');
        if (!t.hasAttribute('tabindex')) { t.setAttribute('tabindex', '0'); }
        ul.setAttribute('role', 'menu');
        t.addEventListener('keydown', function (ev) {
            var k = ev.key;
            if (k === 'Enter' || k === ' ' || k === 'Spacebar' || (k === 'ArrowDown' && !ul.classList.contains('show'))) {
                ev.preventDefault();
                if (!ul.classList.contains('show')) { t.click(); }
                setTimeout(function () { var a = ul.querySelector('a.dropdown-item'); if (a) { a.focus(); } }, 50);
            }
        });
        Array.prototype.forEach.call(ul.querySelectorAll('a.dropdown-item'), function (a) {
            if (!a.hasAttribute('tabindex')) { a.setAttribute('tabindex', '0'); }
            a.setAttribute('role', 'menuitem');
            a.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); a.click(); }
                if (ev.key === 'Escape' || ev.key === 'Esc') { ev.preventDefault(); if (ul.classList.contains('show')) { t.click(); } t.focus(); }
            });
        });
    }

    // v1.2.3: rail flyouts are driven by JS: click-to-pin, hover intent (open delay), forgiving close delay
    function bindFlyouts(root) {
        var TOPSEL = '.appMenu > div > .menuSection';
        var OPEN_DELAY = 100, CLOSE_DELAY = 400, SWITCH_DELAY = 250;
        var shown = null, pinned = null, openT = 0, closeT = 0;
        function isRail(sec) {
            var p = sec && sec.querySelector(':scope > .menuEntries');
            return !!p && window.getComputedStyle(p).position === 'fixed';
        }
        function topOf(el) { return el && el.closest ? el.closest(TOPSEL) : null; }
        function clearTimers() { clearTimeout(openT); clearTimeout(closeT); openT = closeT = 0; }
        function hide() {
            if (shown) { shown.classList.remove('aet-show'); }
            shown = null;
        }
        function show(sec) {
            if (shown && shown !== sec) { shown.classList.remove('aet-show'); }
            shown = sec; sec.classList.add('aet-show');
            placeFlyout(sec);
        }
        function setPinned(sec) {
            if (pinned) {
                pinned.classList.remove('aet-pinned');
                var ol = pinned.querySelector(':scope > .menuLabel'); if (ol) { ol.setAttribute('aria-expanded', 'false'); }
            }
            pinned = sec;
            if (sec) {
                sec.classList.add('aet-pinned');
                var l = sec.querySelector(':scope > .menuLabel'); if (l) { l.setAttribute('aria-expanded', 'true'); }
            }
        }
        function closeAll() { clearTimers(); setPinned(null); hide(); }

        root.addEventListener('mouseover', function (ev) {
            var sec = topOf(ev.target);
            if (!sec || !isRail(sec)) { return; }
            if (pinned) { if (sec === pinned) { clearTimeout(closeT); } return; } // never switch while pinned
            if (sec === shown) { clearTimeout(closeT); closeT = 0; return; }
            intend(sec);
        });
        // aim forgiveness: while a flyout is open and the pointer is still moving across other icons
        // (e.g. a diagonal path toward the flyout), keep postponing both the close and the switch;
        // switching only happens once the pointer rests on another icon.
        root.addEventListener('mousemove', function (ev) {
            if (pinned || !shown) { return; }
            var sec = topOf(ev.target);
            if (!sec || sec === shown || !isRail(sec)) { return; }
            intend(sec);
        });
        function intend(sec) {
            clearTimeout(openT);
            if (shown) {
                clearTimeout(closeT);
                closeT = setTimeout(function () { if (!pinned) { hide(); } }, CLOSE_DELAY + SWITCH_DELAY);
                openT = setTimeout(function () { clearTimeout(closeT); show(sec); }, SWITCH_DELAY);
            } else {
                openT = setTimeout(function () { show(sec); }, OPEN_DELAY);
            }
        }
        root.addEventListener('mouseout', function (ev) {
            var sec = topOf(ev.target);
            if (!sec || !isRail(sec)) { return; }
            if (ev.relatedTarget && sec.contains(ev.relatedTarget)) { return; }
            clearTimeout(openT); openT = 0;
            if (pinned) { return; }
            if (sec === shown) {
                clearTimeout(closeT);
                closeT = setTimeout(function () { if (!pinned) { hide(); } }, CLOSE_DELAY);
            }
        });
        // top-level icon click: pin / unpin / switch (capture so core handlers don't fight it)
        root.addEventListener('click', function (ev) {
            var lab = ev.target && ev.target.closest ? ev.target.closest(TOPSEL + ' > .menuLabel') : null;
            if (!lab) { return; }
            var sec = lab.parentElement;
            if (!isRail(sec)) { return; }
            ev.preventDefault(); ev.stopPropagation();
            clearTimers();
            if (pinned === sec) { setPinned(null); hide(); return; }
            setPinned(sec); show(sec);
        }, true);
        // nested submenu headers toggle an inline accordion (one open per level)
        root.addEventListener('click', function (ev) {
            var lab = ev.target && ev.target.closest ? ev.target.closest('.menuEntries .menuSection > .menuLabel') : null;
            if (!lab) { return; }
            var top = topOf(lab);
            if (!top || !isRail(top)) { return; }
            ev.preventDefault(); ev.stopPropagation();
            var sec = lab.parentElement, open = !sec.classList.contains('aet-open');
            var parent = sec.parentElement;
            if (parent) { Array.prototype.forEach.call(parent.children, function (c) { if (c !== sec) { c.classList.remove('aet-open'); } }); }
            sec.classList.toggle('aet-open', open);
            lab.setAttribute('aria-expanded', open ? 'true' : 'false');
            window.requestAnimationFrame(function () { placeFlyout(top, true); });
        }, true);
        // choosing a real menu item closes the flyout (after core handles the click)
        root.addEventListener('click', function (ev) {
            var item = ev.target && ev.target.closest ? ev.target.closest('.menuEntries .menuLabel') : null;
            if (!item || item.parentElement.classList.contains('menuSection')) { return; }
            var top = topOf(item);
            if (top && isRail(top)) { setTimeout(closeAll, 0); }
        });
        document.addEventListener('mousedown', function (ev) {
            if (!shown) { return; }
            if (shown.contains(ev.target)) { return; }
            var sec = topOf(ev.target);
            if (sec && root.contains(sec)) { return; } // another icon: its click handler switches
            closeAll();
        }, true);
        document.addEventListener('keydown', function (ev) {
            if ((ev.key === 'Escape' || ev.key === 'Esc') && shown) { closeAll(); }
        });
        window.addEventListener('resize', function () { if (shown) { placeFlyout(shown); } });
        // clicks inside the content iframe never reach this document; focus moving into it counts as an outside click
        window.addEventListener('blur', function () {
            setTimeout(function () {
                var ae = document.activeElement;
                if (shown && ae && ae.tagName === 'IFRAME') { closeAll(); }
            }, 0);
        });
    }

    // v1.2.3: on left-rail themes, move the existing patient search form (same DOM node, so knockout
    // bindings and core handlers stay intact) out of the narrow rail into a top bar above the tabs
    var searchHome = null;
    function placeSearch() {
        var box = document.getElementById('mainBox');
        var input = document.getElementById('anySearchBox');
        var form = input && input.closest('form');
        if (!box || !form) { return; }
        var rail = window.getComputedStyle(box).display === 'grid';
        var bar = document.getElementById('aet-topbar');
        if (rail) {
            if (!bar) {
                bar = document.createElement('div');
                bar.id = 'aet-topbar';
                bar.setAttribute('role', 'search');
                box.insertBefore(bar, document.getElementById('attendantData') || box.children[1] || null);
            }
            if (form.parentElement !== bar) {
                searchHome = { parent: form.parentElement, next: form.nextSibling };
                bar.appendChild(form);
            }
            document.documentElement.classList.add('aet-topsearch');
        } else {
            if (searchHome && form.parentElement === bar) { searchHome.parent.insertBefore(form, searchHome.next); }
            document.documentElement.classList.remove('aet-topsearch');
        }
    }

    function start() {
        if (!document.getElementById('mainMenu')) { return; }
        document.documentElement.classList.add('aet-main');
        decorate();
        bindFlyouts(document.getElementById('mainMenu'));
        bindUserMenuKeys(); setTimeout(bindUserMenuKeys, 1500);
        placeSearch();
        window.addEventListener('resize', placeSearch);
        var obs = new MutationObserver(function () { decorate(); });
        obs.observe(document.getElementById('mainMenu'), { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
