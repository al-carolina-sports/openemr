/**
 * Annie's Elegant Themes v1.5.0: Espresso / shadcn extras. Only acts when the active theme
 * sets --aet-modern. On main.php: breadcrumb ("Dashboard / <active tab>") in the top bar and,
 * for shadcn, a light/dark toggle that saves the user's choice through the module ajax endpoint.
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var ajax = script ? script.src.replace(/assets\/aet-modern\.js.*$/, 'ajax.php') : '';
    function modern() {
        return (window.getComputedStyle(document.documentElement).getPropertyValue('--aet-modern') || '').trim();
    }
    function activeTab() {
        var tabs = document.querySelectorAll('#tabs_div .tabSpan');
        for (var i = 0; i < tabs.length; i++) {
            var t = tabs[i];
            if (/active|tabChosen/.test(t.className) || t.getAttribute('aria-selected') === 'true') {
                return (t.innerText || '').replace(/\s+/g, ' ').trim();
            }
        }
        try {
            var vm = window.app_view_model && window.app_view_model.application_data.tabs.tabsList();
            for (var j = 0; vm && j < vm.length; j++) { if (vm[j].visible()) { return vm[j].title(); } }
        } catch (e) { /* ignore */ }
        return '';
    }
    function crumb(bar) {
        var c = document.getElementById('aet-crumb');
        if (!c) {
            c = document.createElement('nav');
            c.id = 'aet-crumb';
            c.setAttribute('aria-label', 'Breadcrumb');
            bar.insertBefore(c, bar.firstChild);
        }
        var name = activeTab(), pt = '';
        try {
            var p = window.app_view_model && window.app_view_model.application_data.patient();
            if (p && p.pname) { pt = typeof p.pname === 'function' ? p.pname() : p.pname; }
        } catch (e) { pt = ''; }
        var parts = ['Dashboard'];
        if (pt && /dashboard|summary|history|encounter|visit/i.test(name)) { parts.push(pt); }
        if (name) { parts.push(name); }
        var key = parts.join('|');
        if (c.getAttribute('data-k') === key) { return; }
        c.setAttribute('data-k', key);
        c.textContent = '';
        parts.forEach(function (t, i) {
            if (i) { var s = document.createElement('span'); s.className = 'sep'; s.textContent = '/'; c.appendChild(s); }
            var e = document.createElement(i === parts.length - 1 ? 'b' : 'span'); e.textContent = t; c.appendChild(e);
        });
    }
    function toggle(bar, mode) {
        if (mode !== 'shadcn' || document.getElementById('aet-mode')) { return; }
        var dark = (window.getComputedStyle(document.documentElement).getPropertyValue('color-scheme') || '').indexOf('dark') >= 0;
        var btn = document.createElement('button');
        btn.type = 'button'; btn.id = 'aet-mode';
        btn.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
        btn.setAttribute('aria-label', btn.title);
        btn.textContent = dark ? '\u2600' : '\u263E';
        btn.addEventListener('click', function () {
            if (!window.csrf_token_js) { return; }
            btn.disabled = true;
            var body = new URLSearchParams({ action: 'mode', dark: dark ? '0' : '1', csrf_token_form: window.csrf_token_js });
            fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (j && j.ok) { window.location.reload(); } else { btn.disabled = false; } })
                .catch(function () { btn.disabled = false; });
        });
        bar.appendChild(btn);
    }
    function tick() {
        var mode = modern();
        var bar = document.getElementById('aet-topbar');
        if (!mode || !bar) { return; }
        crumb(bar);
        toggle(bar, mode);
    }
    function start() {
        if (!document.getElementById('mainMenu') || !modern()) { return; }
        tick();
        window.setInterval(tick, 600);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
}());
