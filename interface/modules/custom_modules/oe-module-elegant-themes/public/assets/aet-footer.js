/**
 * Annie's Elegant Themes v1.4.0: persistent footer with + APPT / + OFFICE.
 * Opens the native add_edit_event.php dialog with native GET params only.
 * Copyright (c) 2026 Carolina Sports and Spine. GPL-3.0.
 */
(function () {
    'use strict';
    if (window !== window.top || window.aetFooterLoaded) { return; }
    window.aetFooterLoaded = true;
    var script = document.currentScript;
    var ajaxUrl = script ? script.src.replace(/assets\/aet-footer\.js.*$/, 'ajax.php') : '';

    function calWindows() {
        var out = [];
        var frames = document.querySelectorAll('iframe');
        for (var i = 0; i < frames.length; i++) {
            try {
                var w = frames[i].contentWindow;
                if (w && w.document && /\/main\/calendar\/index\.php/.test(w.location.pathname)) { out.push(w); }
            } catch (e) { /* cross-origin */ }
        }
        return out;
    }
    function calUsers() {
        var c = calWindows();
        for (var i = 0; i < c.length; i++) {
            var sel = c[i].document.getElementById('pc_username');
            if (sel) {
                return Array.prototype.filter.call(sel.options, function (o) { return o.selected; })
                    .map(function (o) { return o.value; });
            }
        }
        return [];
    }
    function post(data) {
        var fd = new FormData();
        fd.append('csrf_token_form', window.csrf_token_js || '');
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) { v.forEach(function (x) { fd.append(k + '[]', x); }); } else { fd.append(k, v); }
        });
        if (typeof window.restoreSession === 'function') { window.restoreSession(); }
        return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) { throw new Error('http ' + r.status); }
            return r.json();
        });
    }
    function currentPid() {
        try {
            var p = window.app_view_model.application_data.patient();
            return p && p.pid ? String(p.pid()) : '';
        } catch (e) { return ''; }
    }
    window.aetFooterRefresh = function () {
        calWindows().forEach(function (w) {
            try { if (typeof w.refreshme === 'function') { w.refreshme(); } } catch (e) { /* ignore */ }
        });
    };
    function localNow() {
        var d = new Date(), z = function (n) { return ('0' + n).slice(-2); };
        return '' + d.getFullYear() + z(d.getMonth() + 1) + z(d.getDate()) + z(d.getHours()) + z(d.getMinutes());
    }
    function open(kind) {
        post({ action: 'apptctx', cal_users: calUsers(), now: localNow() }).then(function (ctx) {
            if (!ctx || !ctx.can) { return; }
            var d = kind === 'office' ? ctx.office : ctx.appt;
            var q = ['date=' + encodeURIComponent(ctx.date),
                'starttimeh=' + encodeURIComponent(d.slot.h),
                'starttimem=' + encodeURIComponent(('0' + d.slot.m).slice(-2))];
            if (d.userid) { q.push('userid=' + encodeURIComponent(d.userid)); }
            if (kind === 'office') {
                q.push('prov=true', 'catid=' + encodeURIComponent(d.catid));
            } else {
                var pid = currentPid();
                if (pid) { q.push('patientid=' + encodeURIComponent(pid)); }
            }
            var url = (window.webroot_url || '') + '/interface/main/calendar/add_edit_event.php?' + q.join('&');
            if (typeof window.dlgopen === 'function') {
                window.dlgopen(url, '_blank', 780, 675, '', '', { onClosed: 'aetFooterRefresh' });
            } else {
                window.open(url, '_blank', 'width=780,height=675');
            }
        }).catch(function (e) { if (window.console) { console.warn('aet-footer', e); } });
    }
    function button(kind, icon, label, title) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'aet-fbtn aet-fbtn-' + kind;
        b.id = 'aet-f-' + kind;
        b.title = title;
        b.setAttribute('aria-label', title);
        b.innerHTML = '<i class="fa ' + icon + '" aria-hidden="true"></i><span></span>';
        b.querySelector('span').textContent = label;
        b.addEventListener('click', function () { open(kind); });
        return b;
    }
    function boot() {
        if (!document.getElementById('mainBox') || document.getElementById('aet-footer')) { return; }
        post({ action: 'apptctx', cal_users: [] }).then(function (ctx) {
            if (!ctx || !ctx.can) { return; }
            var f = document.createElement('div');
            f.id = 'aet-footer';
            f.setAttribute('role', 'toolbar');
            f.setAttribute('aria-label', 'Quick booking');
            f.appendChild(button('appt', 'fa-calendar-plus', '+ APPT', 'New patient appointment'));
            f.appendChild(button('office', 'fa-business-time', '+ OFFICE', 'New provider office block (Reserved)'));
            document.body.appendChild(f);
            document.documentElement.classList.add('aet-has-footer');
            window.dispatchEvent(new Event('resize'));
        }).catch(function () { /* no footer on failure */ });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
}());
