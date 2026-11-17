/**
 * Annie's Elegant Themes v1.3.0 features (all themes):
 *  - contact card icon after DOB/age in the patient banner (hover popup, click opens Demographics > Contact)
 *  - recent patients dropdown in the patient search (last 8 per user, stored server-side)
 * Loaded on every page through ScriptFilterEvent; the main-page parts only run on the tabs page.
 * Data is rendered with textContent only.
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var ajaxUrl = script ? script.src.replace(/assets\/aet-features\.js.*$/, 'ajax.php') : '';

    // ---- Demographics page opened from the contact card: expand + show the Contact tab ----
    if (/[?&]aet_contact=1/.test(window.location.search)) {
        var tries = 0;
        var showContact = function () {
            var tab = document.getElementById('header_tab_Contact');
            if (!tab) { if (++tries < 40) { setTimeout(showContact, 150); } return; }
            var col = tab.closest('.collapse');
            if (col && !col.classList.contains('show')) {
                var toggler = document.querySelector('[data-target="#' + col.id + '"], [href="#' + col.id + '"]');
                if (toggler) { toggler.click(); } else { col.classList.add('show'); }
            }
            setTimeout(function () {
                tab.click();
                tab.scrollIntoView({ block: 'start' });
                tab.setAttribute('data-aet-shown', '1');
            }, 250);
        };
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', showContact); } else { showContact(); }
        return;
    }

    // main tabs page only: wait for the knockout view model (created after DOM ready by core)
    var bootTries = 0;
    function waitBoot() {
        if (!document.getElementById('mainBox') && document.readyState !== 'loading') { return; } // not the tabs page
        if (document.getElementById('attendantData') && window.app_view_model && window.app_view_model.application_data) { boot(); return; }
        if (++bootTries < 100) { setTimeout(waitBoot, 150); }
    }
    waitBoot();

    function boot() {
        if (window.__aetFeatures) { return; }
        window.__aetFeatures = true;
        initRecording();
        initContactCard();
        initRecent();
    }

    function post(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token_form', window.csrf_token_js || '');
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { j.__status = r.status; return j; });
        });
    }
    function val(x) { return typeof x === 'function' ? x() : x; }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined && text !== null) { e.textContent = text; }
        return e;
    }
    function currentPid() {
        var p = window.app_view_model.application_data.patient();
        return p ? parseInt(val(p.pid), 10) || 0 : 0;
    }
    function openChart(pid, extra) {
        var url = (window.webroot_url || '') + '/interface/patient_file/summary/demographics.php?'
            + (pid ? 'set_pid=' + encodeURIComponent(pid) : '') + (extra || '');
        if (window.restoreSession) { window.restoreSession(); }
        window.navigateTab(url, 'pat', function () { window.activateTabByName('pat', true); });
    }

    // ---- recent patients: record every patient selection (search, calendar, finder, ...) ----
    var lastTouched = 0;
    function initRecording() {
        var obs = window.app_view_model.application_data.patient;
        var rec = function (p) {
            var pid = p ? parseInt(val(p.pid), 10) || 0 : 0;
            if (!pid || pid === lastTouched) { return; }
            lastTouched = pid;
            post('touch', { pid: pid });
        };
        if (obs && obs.subscribe) { obs.subscribe(rec); rec(obs()); }
    }

    // ---- contact card ----
    var aclOk = true, card = null, cardPid = 0, hideT = 0, pinned = false, lastPointer = 'mouse';
    var ICON = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><rect x="2.5" y="4.5" width="19" height="15" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="8.5" cy="10.5" r="2.3" fill="currentColor"/><path d="M4.8 16.2c.6-2 2-3 3.7-3s3.1 1 3.7 3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M14 9.5h5M14 12.5h5M14 15.5h3" stroke="currentColor" stroke-width="1.6"/></svg>';
    function initContactCard() {
        var host = document.getElementById('attendantData');
        var place = function () {
            if (!aclOk) { return; }
            var dob = host.querySelector('[data-bind*="str_dob"]');
            if (!dob || !currentPid()) { return; }
            if (dob.nextElementSibling && dob.nextElementSibling.classList.contains('aet-cc-btn')) { return; }
            var b = el('button', 'aet-cc-btn');
            b.type = 'button';
            b.title = 'Contact info (click to open Demographics)';
            b.setAttribute('aria-label', 'Patient contact info');
            b.setAttribute('aria-haspopup', 'dialog');
            b.innerHTML = ICON;
            dob.parentNode.insertBefore(b, dob.nextSibling);
            b.addEventListener('pointerdown', function (e) { lastPointer = e.pointerType || 'mouse'; });
            b.addEventListener('mouseenter', function () { if (lastPointer !== 'touch') { clearTimeout(hideT); showCard(b); } });
            b.addEventListener('mouseleave', function () { if (!pinned) { scheduleHide(); } });
            b.addEventListener('focus', function () { showCard(b); });
            b.addEventListener('blur', function () { if (!pinned) { scheduleHide(); } });
            b.addEventListener('click', function (e) {
                e.preventDefault();
                if (lastPointer === 'touch' && !(card && card.classList.contains('show') && pinned)) {
                    pinned = true; showCard(b); return;
                }
                hideCard(true);
                openChart(0, 'aet_contact=1');
            });
        };
        new MutationObserver(place).observe(host, { childList: true, subtree: true });
        window.app_view_model.application_data.patient.subscribe(function () { cardPid = 0; hideCard(true); setTimeout(place, 0); });
        place();
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hideCard(true); } });
        document.addEventListener('mousedown', function (e) {
            if (card && card.classList.contains('show') && !card.contains(e.target) && !e.target.closest('.aet-cc-btn')) { hideCard(true); }
        }, true);
    }
    function ensureCard() {
        if (card) { return card; }
        card = el('div', 'aet-cc');
        card.setAttribute('role', 'dialog');
        card.setAttribute('aria-label', 'Patient contact info');
        card.addEventListener('mouseenter', function () { clearTimeout(hideT); });
        card.addEventListener('mouseleave', function () { if (!pinned) { scheduleHide(); } });
        document.body.appendChild(card);
        return card;
    }
    function scheduleHide() { clearTimeout(hideT); hideT = setTimeout(function () { hideCard(false); }, 350); }
    function hideCard(force) { if (card && (force || !pinned)) { card.classList.remove('show'); pinned = false; } }
    function positionCard(btn) {
        var r = btn.getBoundingClientRect(), c = card.getBoundingClientRect();
        var left = Math.min(r.left, window.innerWidth - c.width - 8);
        var top = r.bottom + 6;
        if (top + c.height > window.innerHeight - 8) { top = Math.max(8, r.top - c.height - 6); }
        card.style.left = Math.max(8, left) + 'px';
        card.style.top = top + 'px';
    }
    function showCard(btn) {
        ensureCard();
        var pid = currentPid();
        card.classList.add('show');
        if (cardPid === pid && card.childNodes.length) { positionCard(btn); return; }
        cardPid = pid;
        card.textContent = '';
        card.appendChild(el('div', 'aet-cc-loading', 'Loading contact info...'));
        positionCard(btn);
        post('contact', { pid: pid }).then(function (j) {
            if (j.__status === 403) { aclOk = false; hideCard(true); var b = document.querySelector('.aet-cc-btn'); if (b) { b.remove(); } return; }
            if (pid !== currentPid()) { return; }
            renderCard(j.contact || null);
            positionCard(btn);
        }).catch(function () { card.textContent = 'Contact info unavailable'; });
    }
    function row(parent, label, node) {
        var r = el('div', 'aet-cc-row');
        r.appendChild(el('span', 'aet-cc-lbl', label));
        var v = el('span', 'aet-cc-val');
        if (typeof node === 'string') { v.textContent = node; } else { v.appendChild(node); }
        r.appendChild(v);
        parent.appendChild(r);
    }
    function link(href, text) { var a = el('a', null, text); a.href = href; return a; }
    function tel(n) { return 'tel:' + String(n).replace(/[^0-9+]/g, ''); }
    function renderCard(c) {
        card.textContent = '';
        if (!c) { card.appendChild(el('div', 'aet-cc-loading', 'No contact info')); return; }
        var h = el('div', 'aet-cc-head');
        h.appendChild(el('strong', null, c.name + (c.preferred_name ? ' (' + c.preferred_name + ')' : '')));
        h.appendChild(el('span', 'aet-cc-sub', 'DOB ' + c.dob + (c.pubpid ? '  ?  ID ' + c.pubpid : '')));
        card.appendChild(h);
        var sec = el('div', 'aet-cc-sec');
        (c.phones || []).forEach(function (p) { row(sec, p.label, link(tel(p.value), p.value)); });
        if (c.email) { row(sec, 'Email', link('mailto:' + c.email, c.email)); }
        if (c.address && c.address.length) {
            var a = el('span'); c.address.forEach(function (l, i) { if (i) { a.appendChild(document.createElement('br')); } a.appendChild(document.createTextNode(l)); });
            row(sec, 'Address', a);
        }
        if (!sec.childNodes.length) { sec.appendChild(el('div', 'aet-cc-empty', 'No phone, email or address on file')); }
        card.appendChild(sec);
        if (c.hipaa && c.hipaa.length) {
            var hs = el('div', 'aet-cc-sec');
            hs.appendChild(el('div', 'aet-cc-title', 'Contact preferences'));
            c.hipaa.forEach(function (p) { row(hs, p.label, p.value); });
            card.appendChild(hs);
        }
        if (c.emergency && (c.emergency.name || c.emergency.phone)) {
            var es = el('div', 'aet-cc-sec');
            es.appendChild(el('div', 'aet-cc-title', 'Emergency contact'));
            if (c.emergency.name) { row(es, 'Name', c.emergency.name); }
            if (c.emergency.phone) { row(es, 'Phone', link(tel(c.emergency.phone), c.emergency.phone)); }
            card.appendChild(es);
        }
        var f = el('div', 'aet-cc-foot');
        var open = link('#', 'Open Demographics');
        open.addEventListener('click', function (e) { e.preventDefault(); hideCard(true); openChart(0, 'aet_contact=1'); });
        f.appendChild(open);
        card.appendChild(f);
    }

    // ---- recent patients dropdown in the patient search ----
    var menu = null;
    function initRecent() {
        var input = document.getElementById('anySearchBox');
        var go = document.getElementById('search_globals');
        if (!input || !go || document.getElementById('aet-recent-btn')) { return; }
        var b = el('button', 'btn btn-sm btn-secondary aet-recent-btn');
        b.id = 'aet-recent-btn';
        b.type = 'button';
        b.title = 'Recent patients';
        b.setAttribute('aria-label', 'Recent patients');
        b.setAttribute('aria-haspopup', 'listbox');
        b.setAttribute('aria-expanded', 'false');
        b.innerHTML = '<svg viewBox="0 0 12 12" width="10" height="10" aria-hidden="true"><path d="M2 4l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
        go.parentNode.insertBefore(b, go);
        b.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            if (menu && menu.classList.contains('show')) { closeMenu(); } else { openMenu(b); }
        });
        document.addEventListener('mousedown', function (e) {
            if (menu && menu.classList.contains('show') && !menu.contains(e.target) && e.target !== b && !b.contains(e.target)) { closeMenu(); }
        }, true);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenu(); } });
        window.addEventListener('resize', closeMenu);
    }
    function closeMenu() {
        if (!menu) { return; }
        menu.classList.remove('show');
        var b = document.getElementById('aet-recent-btn'); if (b) { b.setAttribute('aria-expanded', 'false'); }
    }
    function openMenu(btn) {
        if (!menu) { menu = el('div', 'aet-recent'); menu.setAttribute('role', 'listbox'); document.body.appendChild(menu); }
        menu.textContent = '';
        menu.appendChild(el('div', 'aet-recent-empty', 'Loading...'));
        var grp = btn.closest('.input-group') || btn, r = grp.getBoundingClientRect();
        menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 340)) + 'px';
        menu.style.top = (r.bottom + 4) + 'px';
        menu.style.minWidth = Math.max(300, r.width) + 'px';
        menu.classList.add('show');
        btn.setAttribute('aria-expanded', 'true');
        post('recent', {}).then(function (j) {
            menu.textContent = '';
            menu.appendChild(el('div', 'aet-recent-title', 'Recent patients'));
            var list = j.recent || [];
            if (j.__status === 403) { menu.appendChild(el('div', 'aet-recent-empty', 'Not authorized')); return; }
            if (!list.length) { menu.appendChild(el('div', 'aet-recent-empty', 'No recent patients yet')); return; }
            list.forEach(function (p) {
                var it = el('button', 'aet-recent-item');
                it.type = 'button';
                it.setAttribute('role', 'option');
                it.setAttribute('data-pid', p.pid);
                it.appendChild(el('span', 'aet-recent-name', p.name));
                it.appendChild(el('span', 'aet-recent-meta', 'DOB ' + (p.dob || '-') + '  ?  ID ' + (p.pubpid || '-')));
                it.addEventListener('click', function () { closeMenu(); openChart(p.pid); });
                menu.appendChild(it);
            });
        }).catch(function () { menu.textContent = 'Recent patients unavailable'; });
    }
}());
