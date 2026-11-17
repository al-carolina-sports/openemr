/*
 * ZeroDoc Clinical Encounter Workflow -- front-end.
 *
 * Loaded two ways:
 *   1. linked directly by the module's own pages (the encounter form's new.php,
 *      and status.php)
 *   2. injected into the core encounter pages by ScriptFilterEvent, gated per
 *      page and per role server-side (src/Bootstrap.php)
 *
 * Rules this file must keep to:
 *   * The CSRF token goes in the X-CSRF-Token HEADER, never the URL.
 *   * Nothing here is a security control. The server decides what a user may
 *     see or do, and omits what they may not; this file only renders what it
 *     was given.
 *   * Degrade quietly. If the endpoint is unreachable -- module disabled
 *     mid-session, permission removed -- say so and leave the stock page
 *     working.
 */
(function () {
    'use strict';

    var banner = document.getElementById('zdoc-banner');
    if (!banner) {
        // Injected into a core page with no ZeroDoc container: nothing to do.
        return;
    }

    var endpoint = banner.getAttribute('data-endpoint');
    var csrf = banner.getAttribute('data-csrf');
    if (!endpoint || !csrf) {
        return;
    }

    function show(state, text) {
        banner.setAttribute('data-state', state);
        banner.textContent = text;
    }

    fetch(endpoint + '?action=status', {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf }
    }).then(function (response) {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    }).then(function (data) {
        var parts = ['ZeroDoc ' + data.version];
        if (Array.isArray(data.schema)) {
            data.schema.forEach(function (row) {
                parts.push(row.component + ' schema r' + row.version);
            });
        }
        if (data.can_document === false) {
            parts.push('read only');
        }
        show('ok', parts.join(' · '));
    }).catch(function () {
        show('error', 'ZeroDoc workflow service unavailable; the note can still be typed and saved.');
    });
})();
