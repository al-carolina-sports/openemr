/**
 * AI Assistant chat panel (main tabs page). Plain JS, no dependencies.
 * Replies are inserted as text, never as HTML. Nothing is kept in browser
 * storage: the conversation lives on the server.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';

    const script = document.currentScript;
    if (!script) {
        return;
    }
    const endpoint = script.dataset.aiaEndpoint || '';
    const csrf = script.dataset.aiaCsrf || '';
    let t = {};
    try {
        t = JSON.parse(script.dataset.aiaStrings || '{}');
    } catch (e) {
        t = {};
    }
    const say = (key, fallback) => (typeof t[key] === 'string' && t[key] !== '' ? t[key] : fallback);

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function build() {
        const panel = el('aside', 'aia-panel');
        panel.id = 'aia-panel';
        panel.hidden = true;
        panel.setAttribute('role', 'complementary');
        panel.setAttribute('aria-label', say('title', 'AI Assistant'));

        const header = el('div', 'aia-header');
        header.appendChild(el('strong', 'aia-title', say('title', 'AI Assistant')));
        const reset = el('button', 'btn btn-sm btn-link aia-reset', say('reset', 'New chat'));
        reset.type = 'button';
        const close = el('button', 'btn btn-sm btn-link aia-close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', say('close', 'Close'));
        header.append(reset, close);

        const notice = el('div', 'aia-notice', say('notice', ''));
        const log = el('div', 'aia-log');
        log.setAttribute('role', 'log');
        log.setAttribute('aria-live', 'polite');

        const form = el('form', 'aia-form');
        const input = el('textarea', 'form-control aia-input');
        input.rows = 2;
        input.maxLength = 2000;
        input.placeholder = say('placeholder', 'Ask about the schedule');
        input.setAttribute('aria-label', say('placeholder', 'Message'));
        const send = el('button', 'btn btn-primary btn-sm aia-send', say('send', 'Send'));
        send.type = 'submit';
        form.append(input, send);

        panel.append(header, notice, log, form);
        document.body.appendChild(panel);
        return { panel, log, form, input, send, reset, close };
    }

    function init() {
        const toggle = document.getElementById('aia-toggle');
        if (!toggle || endpoint === '') {
            return;
        }
        const ui = build();
        let busy = false;

        function add(role, text, tools) {
            const item = el('div', 'aia-msg aia-' + role);
            item.appendChild(el('div', 'aia-text', text));
            if (Array.isArray(tools) && tools.length > 0) {
                const unique = tools.filter((v, i) => tools.indexOf(v) === i);
                item.appendChild(el('div', 'aia-tools', '→ ' + unique.join(', ')));
            }
            ui.log.appendChild(item);
            ui.log.scrollTop = ui.log.scrollHeight;
            return item;
        }

        function intro() {
            ui.log.textContent = '';
            add('assistant', say('intro', 'Ask me about the schedule.'));
        }

        function open(show) {
            ui.panel.hidden = !show;
            toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
            document.body.classList.toggle('aia-open', show);
            if (show) {
                if (ui.log.childElementCount === 0) {
                    intro();
                }
                ui.input.focus();
            } else {
                toggle.focus();
            }
        }

        async function post(fields) {
            const body = new FormData();
            body.append('csrf_token', csrf);
            Object.keys(fields).forEach((k) => body.append(k, fields[k]));
            const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
            const data = await response.json();
            if (typeof data.reply !== 'string') {
                throw new Error('bad response');
            }
            return data;
        }

        function setBusy(on) {
            busy = on;
            ui.send.disabled = on;
            ui.reset.disabled = on;
            ui.input.readOnly = on;
        }

        async function submit() {
            const text = ui.input.value.trim();
            if (text === '' || busy) {
                return;
            }
            add('user', text);
            ui.input.value = '';
            setBusy(true);
            const waiting = add('pending', say('thinking', 'Thinking...'));
            try {
                const data = await post({ action: 'send', message: text });
                waiting.remove();
                add(data.error ? 'error' : 'assistant', data.reply, data.tools);
            } catch (e) {
                waiting.remove();
                add('error', say('network', 'Could not reach the assistant.'));
            } finally {
                setBusy(false);
                ui.input.focus();
            }
        }

        toggle.addEventListener('click', () => open(ui.panel.hidden));
        ui.close.addEventListener('click', () => open(false));
        ui.form.addEventListener('submit', (e) => {
            e.preventDefault();
            submit();
        });
        ui.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                submit();
            }
        });
        ui.panel.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                open(false);
            }
        });
        ui.reset.addEventListener('click', async () => {
            if (busy) {
                return;
            }
            setBusy(true);
            try {
                await post({ action: 'reset' });
                intro();
            } catch (e) {
                add('error', say('network', 'Could not reach the assistant.'));
            } finally {
                setBusy(false);
                ui.input.focus();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
