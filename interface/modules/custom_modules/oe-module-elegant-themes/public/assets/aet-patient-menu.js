/**
 * Annie's Elegant Themes v1.5.3: pinned patient menu.
 *
 * OpenEMR draws the patient menu (Dashboard, History, Assessments, Report, Documents, ...)
 * inside each patient page, below a page heading whose size, container and margins differ
 * from page to page, so the menu jumped left/right/up/down on every click. A few
 * destinations (Documents, Auths, Cash-Pay) do not draw it at all.
 *
 * In the patient tab this script moves the page's own menu into one fixed bar at the very
 * top of the tab (directly under the main menu / tab strip) with a constant height and
 * left edge, reserves that space, highlights the current tab, and opens dropdowns as
 * fixed popovers so a horizontally scrolling bar never clips them. Pages that do not draw
 * the menu get the same server-rendered menu (ajax.php action=pmenu, current patient only).
 * v1.5.4: the page content under the bar is normalised too. Each patient page wraps its
 * content differently (centred fixed-width .container, .container-fluid, body margins, a
 * padded heading navbar, col/m-4 offsets), so headings started 10-172px from the left and
 * 6-16px below the bar. Top-level wrappers are made full width with one gutter, then the
 * first heading/block is measured and the wrapper padding is trimmed so every page starts
 * at the menu's left edge and the same gap below the bar.
 * v1.5.5: the same content alignment runs on every page opened in a main tab (encounter
 * pages incl. the nested Summary/form frames, and the pages behind the main menu), with
 * the 12px gutter measured from the tab's left edge and a 12px top gap; centred headings and
 * tables are measured at their column's edge and the calendar grid keeps its own layout. The
 * patient menu is still only pinned in the patient tab.
 * DOM only; no core file is touched.
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';
    var win = window, doc = document;
    var top;
    try { top = win.top; if (top === win || !top.document) { return; } } catch (e) { return; }
    // v1.5.5: find the main tab frame this page lives in (itself, or an ancestor for nested
    // frames such as the encounter Summary/form tabs). Dialogs and the main page are skipped.
    var tabWin = null, frameOffX = 0;
    try {
        for (var w = win; w && w !== top; w = w.parent) {
            var f = w.frameElement;
            if (!f) { break; }
            if (f.closest && f.closest('#mainFrames_div') && w.parent === top) { tabWin = w; break; }
        }
    } catch (e) { tabWin = null; }
    if (!tabWin) { return; }
    var isTabFrame = tabWin === win;
    var fe = win.frameElement;

    var script = doc.currentScript;
    var assets = script ? script.src.replace(/aet-patient-menu\.js.*$/, '') : '';
    var ajaxUrl = assets ? assets.replace(/assets\/$/, 'ajax.php') : '';
    var CACHE = 'aet-pmenu:';
    var ICONS = {
        dashboard: 'dashboard', history: 'notes', sdoc: 'consents', report: 'reports',
        documents: 'documents', transactions: 'orders', issues: 'alerts', ledger: 'billing',
        external_data: 'labs', patient_reported_outcomes: 'tasks', mod_pa: 'check-in',
        cpi_patient: 'memberships', Modules: 'settings'
    };
    // destinations that do not draw the menu themselves (matched before any menu is known)
    var NEEDS_INJECT = [
        function (u) { return /\/controller\.php$/.test(u.pathname) && /(^|[?&])document(&|=|$)/.test(u.search); },
        // the patient's Auths page only - the all-patients report (Reports > Insurance > Authorizations) is not a chart page
        function (u) { return u.pathname.indexOf('/oe-module-prior-authorizations/public/') !== -1 && u.pathname.indexOf('/public/reports/') === -1; },
        function (u) { return u.pathname.indexOf('/oe-module-cashpay-invoicing/public/patient/') !== -1; }
    ];

    function currentPid() {
        try {
            var p = top.app_view_model.application_data.patient();
            var v = p ? (typeof p.pid === 'function' ? p.pid() : p.pid) : 0;
            return parseInt(v, 10) || 0;
        } catch (e) { return 0; }
    }
    function nativeNav() {
        var navs = doc.querySelectorAll('nav.navbar');
        for (var i = 0; i < navs.length; i++) {
            var n = navs[i];
            if (n.closest('#aet-pmenu')) { continue; }
            if (n.querySelector('#myNavbar > .navbar-nav') && n.querySelector('a[href*="/patient_file/summary/demographics.php"]')) {
                return n;
            }
        }
        return null;
    }
    function store(pid, html) {
        try { top.sessionStorage.setItem(CACHE + pid, JSON.stringify({ t: Date.now(), h: html })); } catch (e) { /* storage full/blocked */ }
    }
    function cached(pid) {
        try {
            var c = JSON.parse(top.sessionStorage.getItem(CACHE + pid) || 'null');
            return c && (Date.now() - c.t) < 30 * 60 * 1000 ? c.h : null;
        } catch (e) { return null; }
    }
    function parseNav(html) {
        var t = doc.createElement('div');
        t.innerHTML = html;
        var n = t.querySelector('nav.navbar');
        return n && n.querySelector('#myNavbar > .navbar-nav') ? n : null;
    }
    function samePage(href) {
        var a = doc.createElement('a');
        a.href = href;
        return a;
    }
    // score how well a menu link matches this page (0 = no match)
    function score(href) {
        if (!href || href === '#' || href.indexOf('javascript:') === 0) { return 0; }
        var a = samePage(href), here = win.location;
        if (a.pathname !== here.pathname) { return 0; }
        var s = 1, q = a.search.replace(/^\?/, '').split('&');
        q.forEach(function (kv) {
            if (!kv) { return; }
            var k = kv.split('=')[0];
            if (/^(pid|patient_id|set_pid|csrf_token_form)$/.test(k)) { return; }
            if (here.search.replace(/^\?/, '').split('&').indexOf(kv) !== -1) { s += 2; } else if (k === 'view' || k === 'entity') { s -= 2; }
        });
        return s;
    }
    function markActive(bar) {
        var links = bar.querySelectorAll('.navbar-nav a[href]');
        var best = null, bestS = 0;
        Array.prototype.forEach.call(links, function (a) {
            var s = score(a.getAttribute('href'));
            if (s > bestS) { best = a; bestS = s; }
        });
        Array.prototype.forEach.call(bar.querySelectorAll('.aet-pm-active'), function (e) { e.classList.remove('aet-pm-active'); });
        var pageActive = bar.querySelector('.navbar-nav > li.active');
        var li = best ? best.closest('li') : pageActive;
        if (!li) { return; }
        li.classList.add('aet-pm-active');
        if (best) { best.setAttribute('aria-current', 'page'); }
        var parentLi = li.parentElement && li.parentElement.closest('.navbar-nav > li');
        if (parentLi && parentLi !== li) { parentLi.classList.add('aet-pm-active'); }
    }
    function decorate(bar) {
        Array.prototype.forEach.call(bar.querySelectorAll('.navbar-nav > li'), function (li) {
            var a = li.querySelector(':scope > a');
            if (!a || a.querySelector('.aet-ico')) { return; }
            var key = li.id || a.id || '';
            if (!ICONS[key] && a.classList.contains('dropdown-toggle')) { key = a.id; }
            var name = ICONS[key] || (/modules/i.test(a.textContent) ? 'settings' : 'dashboard');
            var i = doc.createElement('span');
            i.className = 'aet-ico';
            i.setAttribute('aria-hidden', 'true');
            i.style.setProperty('--aet-ico', 'url("' + assets + 'icons/' + name + '.svg")');
            a.insertBefore(i, a.firstChild);
            a.title = (a.textContent || '').trim();
        });
    }

    // ---- dropdowns as fixed popovers (consistent on every page, not clipped by the scrolling row) ----
    var openMenu = null;
    function closeMenu() {
        if (!openMenu) { return; }
        openMenu.classList.remove('show');
        var t = openMenu.parentElement && openMenu.parentElement.querySelector(':scope > .dropdown-toggle');
        if (t) { t.setAttribute('aria-expanded', 'false'); }
        if (openMenu.parentElement) { openMenu.parentElement.classList.remove('show'); }
        openMenu = null;
    }
    function openFor(toggle) {
        var menu = toggle.parentElement.querySelector(':scope > .dropdown-menu');
        if (!menu) { return; }
        if (openMenu === menu) { closeMenu(); return; }
        closeMenu();
        var r = toggle.getBoundingClientRect();
        menu.classList.add('show');
        toggle.parentElement.classList.add('show');
        toggle.setAttribute('aria-expanded', 'true');
        var w = menu.offsetWidth, left = Math.max(4, Math.min(r.left, win.innerWidth - w - 4));
        menu.style.left = left + 'px';
        menu.style.top = Math.round(r.bottom + 2) + 'px';
        openMenu = menu;
    }
    function wireDropdowns(bar) {
        bar.addEventListener('click', function (e) {
            var t = e.target.closest('.dropdown-toggle');
            if (t && bar.contains(t)) {
                e.preventDefault();
                e.stopPropagation(); // keep Bootstrap's delegated handler out of it
                openFor(t);
                return;
            }
            if (e.target.closest('.dropdown-menu a')) { setTimeout(closeMenu, 0); }
        });
        doc.addEventListener('click', function (e) { if (openMenu && !openMenu.contains(e.target)) { closeMenu(); } });
        doc.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenu(); } });
        win.addEventListener('resize', closeMenu);
        var row = bar.querySelector('.navbar-nav');
        if (row) { row.addEventListener('scroll', closeMenu, { passive: true }); }
        win.addEventListener('scroll', closeMenu, { passive: true });
    }

    function pin(nav) {
        if (doc.getElementById('aet-pmenu')) { return; }
        var bar = doc.createElement('div');
        bar.id = 'aet-pmenu';
        bar.setAttribute('role', 'navigation');
        bar.setAttribute('aria-label', 'Patient menu');
        // normalise: always expanded, no collapse toggler, single row
        var tog = nav.querySelector('.navbar-toggler');
        if (tog) { tog.remove(); }
        var col = nav.querySelector('#myNavbar');
        if (col) { col.classList.add('show'); }
        nav.classList.add('aet-pm-nav');
        bar.appendChild(nav);
        doc.body.insertBefore(bar, doc.body.firstChild);
        doc.documentElement.classList.add('aet-pm');
        decorate(bar);
        markActive(bar);
        wireDropdowns(bar);
        // pages that add .active after load (history.php, pat_ledger.php) - keep our marker in sync once
        setTimeout(function () { markActive(bar); }, 600);
        alignSoon();
        var act = bar.querySelector('.navbar-nav > li.aet-pm-active');
        var row = bar.querySelector('.navbar-nav');
        if (act && row && row.scrollWidth > row.clientWidth) {
            var ar = act.getBoundingClientRect(), rr = row.getBoundingClientRect();
            if (ar.right > rr.right || ar.left < rr.left) { row.scrollLeft += ar.left - rr.left - 24; }
        }
    }


    // ---- v1.5.4: same left edge / top gap for the page content under the bar ----
    var GAP = 12;
    function cssPx(name, fallback) {
        var v = parseFloat(getComputedStyle(doc.documentElement).getPropertyValue(name));
        return isNaN(v) ? fallback : v;
    }
    function visible(el) {
        for (var e = el; e && e !== doc.body; e = e.parentElement) {
            var cs = getComputedStyle(e);
            if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) { return false; }
        }
        return true;
    }
    // first visible text on the page (outside the bar) and its nearest block box
    function firstContent() {
        var w = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, { acceptNode: function (n) {
            if (!n.nodeValue.trim()) { return NodeFilter.FILTER_REJECT; }
            var p = n.parentElement;
            if (!p || p.closest('#aet-pmenu, script, style, noscript, .modal, [hidden]')) { return NodeFilter.FILTER_REJECT; }
            return NodeFilter.FILTER_ACCEPT;
        } });
        var n;
        while ((n = w.nextNode())) {
            if (!visible(n.parentElement)) { continue; }
            var r = doc.createRange();
            r.selectNodeContents(n);
            var rr = r.getBoundingClientRect();
            if (rr.width < 1 || rr.height < 1) { continue; }
            var blk = n.parentElement;
            while (blk && blk !== doc.body && getComputedStyle(blk).display.indexOf('inline') === 0) { blk = blk.parentElement; }
            return { text: rr, block: blk.getBoundingClientRect(), el: blk, left: columnLeft(n.parentElement, rr.left, rr.right) };
        }
        return null;
    }
    // where the content column starts: a centred heading (Flow Board) or a centred table (Lab Overview)
    // is measured at its column's left edge, not at its first letter
    function contentBox(el) {
        var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
        return [r.left + parseFloat(cs.borderLeftWidth) + parseFloat(cs.paddingLeft), r.right - parseFloat(cs.borderRightWidth) - parseFloat(cs.paddingRight)];
    }
    function columnLeft(el, x, textRight) {
        for (var e = el; e && e !== doc.body; e = e.parentElement) {
            var cs = getComputedStyle(e);
            if (cs.display.indexOf('inline') === 0 && cs.display !== 'inline-block' && cs.display !== 'inline-table') { continue; }
            // centred text only counts when it really sits in the middle of its box (not a flex row's first item)
            if (/center|right|end/.test(cs.textAlign) && !/^(TD|TH)$/.test(e.tagName)) {
                var cb = contentBox(e);
                if (x - cb[0] > 8 && Math.abs((x - cb[0]) - (cb[1] - textRight)) < 8) { x = cb[0]; }
            }
            var p = e.parentElement;
            if (!p || /^(TR|TBODY|THEAD|TABLE)$/.test(p.tagName)) { continue; }
            var r = e.getBoundingClientRect(), pb = contentBox(p), gl = r.left - pb[0], gr = pb[1] - r.right;
            if (gl > 8 && Math.abs(gl - gr) < 6) { x = pb[0]; }
        }
        return x;
    }
    function topWrapper(el) {
        var e = el;
        while (e && e.parentElement && e.parentElement !== doc.body) { e = e.parentElement; }
        return e && e.parentElement === doc.body ? e : null;
    }
    function isWrapper(el) {
        if (!el || el.id === 'aet-pmenu' || el.nodeType !== 1) { return false; }
        if (/^(SCRIPT|STYLE|LINK|IFRAME|NOSCRIPT|TEMPLATE)$/.test(el.tagName) || el.classList.contains('modal')) { return false; }
        var cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.position === 'absolute' || cs.position === 'fixed') { return false; }
        return true;
    }
    // pages whose own layout must not be touched (calendar grid, full-bleed apps)
    var SKIP_ALIGN = [
        /\/interface\/main\/calendar\//, /\/interface\/main\/tabs\/main\.php/, /\/portal\//
    ];
    function alignAllowed() {
        var u = win.location.pathname + win.location.search;
        return !SKIP_ALIGN.some(function (re) { return re.test(u); });
    }
    // left offset of this document inside the main tab frame (nested frames)
    function offsetInTab() {
        var x = 0;
        try { for (var w = win; w !== tabWin; w = w.parent) { x += w.frameElement.getBoundingClientRect().left; } } catch (e) { x = 0; }
        return x;
    }
    function fixedAncestor(el) {
        for (var e = el; e && e !== doc.body; e = e.parentElement) {
            var p = getComputedStyle(e).position;
            if (p === 'fixed' || p === 'absolute' || p === 'sticky') { return true; }
        }
        return false;
    }
    function normalizeContent() {
        if (!doc.body || !alignAllowed()) { return; }
        var bar = doc.getElementById('aet-pmenu');
        var pad = cssPx('--aet-pm-pad', 12), barH = bar ? cssPx('--aet-pm-h', 36) : 0;
        doc.documentElement.classList.add('aet-pc');
        // 1. every top-level page container: full width, no centring, one gutter
        var wraps = [];
        Array.prototype.forEach.call(doc.body.children, function (c) {
            if (!isWrapper(c)) { return; }
            if (c.matches('.container, .container-fluid, .container-xl, .container-lg, .container-md, #container_div, form, main, section') || c.querySelector(':scope > .row, :scope > .container, :scope > .container-fluid')) {
                c.classList.add('aet-pc-wrap');
                wraps.push(c);
            }
        });
        var fc = firstContent();
        if (!fc || fixedAncestor(fc.el)) { return; }
        var main = topWrapper(fc.el);
        // tables / inline wrappers are moved through the body instead
        if (main && (!isWrapper(main) || /^(TABLE|TBODY|SPAN|A|IMG|INPUT|SELECT|BUTTON)$/.test(main.tagName) || /^(table|inline)/.test(getComputedStyle(main).display))) { main = null; }
        if (main && wraps.indexOf(main) === -1) { main.classList.add('aet-pc-wrap'); wraps.push(main); }
        var target = main || doc.body;
        var cs = getComputedStyle(target);
        var off = offsetInTab();
        // a frame embedded inside a page (e.g. the Documents viewer) is a panel, not a page: leave it
        if (!isTabFrame && off > pad + 4) { doc.documentElement.classList.remove('aet-pc'); return; }
        var wantL = Math.max(0, pad - off);
        var dx = wantL - (fc.left + win.scrollX);
        // a big inward offset is the page's own layout (sidebar, centred form) - only the wrapper CSS applies there
        if (dx < -40) { dx = 0; }
        // when the measured block is the wrapper itself its top never moves - use the text line instead
        var topY = (fc.el === target || fc.el === doc.body) ? fc.text.top : fc.block.top;
        var dy = (barH + GAP) - (topY + win.scrollY);
        // content far below the target is the page's own layout: only give back the wrapper's own top padding
        if (dy < -60) { dy = Math.max(dy, -(parseFloat(getComputedStyle(target).paddingTop) || 0)); }
        if (Math.abs(dx) >= 0.5) {
            var rawL = parseFloat(cs.paddingLeft) - Math.min(0, parseFloat(cs.marginLeft) || 0) + dx;
            var pl = Math.max(0, rawL);
            target.style.setProperty('padding-left', pl + 'px', 'important');
            // an inner offset bigger than the gutter (e.g. Auths' .m-4): pull the wrapper left by the rest
            if (target !== doc.body) { target.style.setProperty('margin-left', Math.min(0, rawL) + 'px', 'important'); }
            wraps.forEach(function (w) { if (w !== target) { w.style.setProperty('padding-left', pl + 'px', 'important'); } });
        }
        if (Math.abs(dy) >= 0.5) {
            var rawT = parseFloat(cs.paddingTop) - Math.min(0, parseFloat(cs.marginTop) || 0) + dy;
            if (target === doc.body) {
                target.style.setProperty('padding-top', Math.max(barH, rawT) + 'px', 'important');
            } else {
                target.style.setProperty('padding-top', Math.max(0, rawT) + 'px', 'important');
                target.style.setProperty('margin-top', Math.min(0, rawT) + 'px', 'important');
            }
        }
        // nested frames (encounter Summary / form tabs) depend on our offset: let them re-align
        Array.prototype.forEach.call(doc.querySelectorAll('iframe'), function (f) {
            try { if (f.contentWindow && f.contentWindow.__aetAlign) { f.contentWindow.__aetAlign(); } } catch (e) { /* cross-origin */ }
        });
    }
    win.__aetAlign = function () { try { normalizeContent(); } catch (e) { /* never break the page */ } };
    function alignSoon() {
        win.__aetAlign();
        if (doc.readyState !== 'complete') { win.addEventListener('load', win.__aetAlign); }
        setTimeout(win.__aetAlign, 700);
        setTimeout(win.__aetAlign, 1600); // late page scripts / stylesheets (Forms Administration)
    }

    function fetchMenu(pid) {
        if (!ajaxUrl || !top.csrf_token_js || !win.fetch) { return Promise.resolve(null); }
        var body = new URLSearchParams({ action: 'pmenu', pid: String(pid), csrf_token_form: top.csrf_token_js });
        return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { return j && j.ok && j.html && j.pid === pid ? j.html : null; })
            .catch(function () { return null; });
    }

    function unreserve() {
        if (!doc.getElementById('aet-pmenu')) { doc.documentElement.classList.remove('aet-pm'); }
    }
    function run() {
        if (!doc.body || doc.getElementById('aet-pmenu')) { return; }
        var pid = currentPid();
        var nav = nativeNav();
        if (nav) {
            if (pid) { store(pid, nav.outerHTML); }
            pin(nav);
            return;
        }
        if (!pid) { unreserve(); return; }
        var here = new URL(win.location.href);
        var html = cached(pid);
        var inMenu = false;
        if (html) {
            var probe = parseNav(html);
            if (probe) {
                Array.prototype.forEach.call(probe.querySelectorAll('.navbar-nav a[href]'), function (a) {
                    if (score(a.getAttribute('href')) > 0) { inMenu = true; }
                });
            }
        }
        var listed = NEEDS_INJECT.some(function (f) { return f(here); });
        if (!inMenu && !listed) { unreserve(); return; }
        // pages that show the patient id in the URL must be for the selected patient
        var qp = here.searchParams.get('patient_id') || here.searchParams.get('pid');
        if (qp && parseInt(qp, 10) !== pid) { unreserve(); return; }
        if (html) {
            var n = parseNav(html);
            if (n) { pin(n); } else { unreserve(); }
            return;
        }
        // reserve the space now so the page does not shift when the menu arrives
        doc.documentElement.classList.add('aet-pm');
        fetchMenu(pid).then(function (h) {
            var n = h ? parseNav(h) : null;
            if (n) { store(pid, h); pin(n); } else { unreserve(); alignSoon(); }
        });
    }

    // Reserve the bar's space before first paint when this page is a known menu destination
    // (menu already seen for this patient, or a page that never draws it), so nothing shifts.
    (function early() {
        if (!isTabFrame) { return; }
        var pid = currentPid();
        if (!pid) { return; }
        var here;
        try { here = new URL(win.location.href); } catch (e) { return; }
        var html = cached(pid), hit = NEEDS_INJECT.some(function (f) { return f(here); });
        if (!hit && html) {
            var re = /href="([^"]+)"/g, m;
            while ((m = re.exec(html))) { if (score(m[1].replace(/&amp;/g, '&')) > 0) { hit = true; break; } }
        }
        if (hit) { doc.documentElement.classList.add('aet-pm'); }
    })();

    function boot() {
        if (isTabFrame) { run(); }
        // pages without the pinned menu (everything else opened from the main menu, encounter pages, nested frames)
        if (!doc.getElementById('aet-pmenu') && !doc.documentElement.classList.contains('aet-pm')) { alignSoon(); }
    }
    if (alignAllowed() && (isTabFrame || offsetInTab() <= cssPx('--aet-pm-pad', 12) + 4)) { doc.documentElement.classList.add('aet-pc'); }
    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', boot);
        // pages that keep streaming for seconds (Forms Administration) are aligned while they load too
        var tries = 0, iv = setInterval(function () {
            if (doc.readyState !== 'loading' || ++tries > 30) { clearInterval(iv); return; }
            if (doc.body && !doc.documentElement.classList.contains('aet-pm')) { win.__aetAlign(); }
        }, 400);
    } else { boot(); }
})();
