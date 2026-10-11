/**
 * Appointment Hover Card
 *
 * Replaces the calendar's native title tooltip with a card built from
 * public/ajax.php. All values are inserted with textContent (never innerHTML).
 * If anything fails, the native tooltip is restored.
 */
(function () {
    'use strict';

    var config = window.oeApptHoverCardConfig;

    // Used by the config page's Preview button (no config is present there).
    window.oeApptHoverCard = { renderCard: renderCard };

    if (!config) {
        return;
    }

    // ---- The only place that knows about the calendar's markup. ----------
    // Re-test after every OpenEMR upgrade (templates/calendar/default/views/*).
    // Day/Week events are div.event[data-eid]; Month events are div.month_event[data-eid].
    // Which views get the card is decided server-side (Bootstrap::shouldInject).
    var SELECTORS = {
        appointment: '.event[data-eid], .month_event[data-eid]',
        eidAttribute: 'data-eid',
        titleAttribute: 'title',
        // Core's picture pop-up icon: onmouseover="ShowImage(...)" inside the event content.
        legacyHover: '[onmouseover*="ShowImage"]'
    };
    // ----------------------------------------------------------------------

    var cache = new Map();
    var card = null;
    var showTimer = null;
    var hideTimer = null;
    var activeEl = null;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function renderCard(data, width) {
        var root = el('div', 'oe-hc');
        root.style.width = (width || 360) + 'px';
        var body = el('div', 'oe-hc-body');
        var blocks = ['appointment', 'patient', 'contact'];
        blocks.forEach(function (name) {
            var rows = (data.blocks && data.blocks[name]) || [];
            if (!rows.length) {
                return;
            }
            var section = el('div', 'oe-hc-block oe-hc-' + name);
            rows.forEach(function (row) {
                var line = el('div', 'oe-hc-row');
                line.appendChild(el('span', 'oe-hc-label', row.label + ': '));
                line.appendChild(el('span', 'oe-hc-value', row.value));
                section.appendChild(line);
            });
            body.appendChild(section);
        });
        root.appendChild(body);

        if (data.photo) {
            var photo = el('div', 'oe-hc-photo');
            photo.style.width = data.photo.size + 'px';
            photo.style.height = data.photo.size + 'px';
            photo.classList.add('oe-hc-initials');
            photo.textContent = data.photo.initials || '';
            if (data.photo.url && config) {
                loadPhoto(data.photo.url, photo);
            }
            root.appendChild(photo);
        }
        return root;
    }

    // The photo endpoint needs the CSRF header, which <img src> cannot send, so fetch it as a blob.
    function loadPhoto(url, holder) {
        fetch(url, { credentials: 'same-origin', headers: { 'X-CSRF-Token': config.csrf } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('photo ' + response.status);
                }
                return response.blob();
            })
            .then(function (blob) {
                var img = document.createElement('img');
                img.alt = '';
                img.src = URL.createObjectURL(blob);
                holder.textContent = '';
                holder.classList.remove('oe-hc-initials');
                holder.appendChild(img);
            })
            .catch(function () { /* keep the initials */ });
    }

    function ensureCard() {
        if (!card) {
            card = el('div', 'oe-hc-wrap');
            card.style.display = 'none';
            card.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
            card.addEventListener('mouseleave', scheduleHide);
            document.body.appendChild(card);
        }
        return card;
    }

    function position(target) {
        var rect = target.getBoundingClientRect();
        var wrap = ensureCard();
        wrap.style.display = 'block';
        var left = rect.right + 8;
        if (left + wrap.offsetWidth > window.innerWidth) {
            left = Math.max(8, rect.left - wrap.offsetWidth - 8);
        }
        var top = Math.min(rect.top, window.innerHeight - wrap.offsetHeight - 8);
        wrap.style.left = (left + window.scrollX) + 'px';
        wrap.style.top = (Math.max(8, top) + window.scrollY) + 'px';
    }

    function hide() {
        if (card) {
            card.style.display = 'none';
        }
        activeEl = null;
    }

    function scheduleHide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hide, config.hideDelayMs);
    }

    // Remove every native tooltip under the event (event, patient name, address, age, DOB...)
    // and core's ShowImage pop-up handlers. Titles are kept in data-oe-hc-title so they can be
    // put back if the card cannot be shown.
    function suppressNativeTooltip(target) {
        var nodes = [target].concat(Array.prototype.slice.call(target.querySelectorAll('[title]')));
        nodes.forEach(function (node) {
            var title = node.getAttribute(SELECTORS.titleAttribute);
            if (title !== null) {
                node.setAttribute('data-oe-hc-title', title);
                node.removeAttribute(SELECTORS.titleAttribute);
            }
        });
        target.querySelectorAll(SELECTORS.legacyHover).forEach(function (node) {
            node.removeAttribute('onmouseover');
            node.removeAttribute('onmouseout');
        });
        var popup = document.getElementById('popup');
        if (popup) {
            popup.style.display = 'none';
        }
    }

    function restoreNativeTooltip(target) {
        var nodes = [target].concat(Array.prototype.slice.call(target.querySelectorAll('[data-oe-hc-title]')));
        nodes.forEach(function (node) {
            var title = node.getAttribute('data-oe-hc-title');
            if (title !== null) {
                node.setAttribute(SELECTORS.titleAttribute, title);
                node.removeAttribute('data-oe-hc-title');
            }
        });
    }

    // Appointment id: data-eid, or the middle part of id="{date}-{eid}-{cattype}".
    function eidOf(target) {
        var eid = target.getAttribute(SELECTORS.eidAttribute);
        if (eid && /^\d+$/.test(eid)) {
            return eid;
        }
        var m = /-(\d+)-[^-]*$/.exec(target.id || '');
        return m ? m[1] : '';
    }

    function fetchCard(eid) {
        if (cache.has(eid)) {
            return Promise.resolve(cache.get(eid));
        }
        var url = config.endpoint + '?eid=' + encodeURIComponent(eid);
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': config.csrf }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('hovercard ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                cache.set(eid, data);
                return data;
            });
    }

    function open(target) {
        var eid = eidOf(target);
        if (!eid) {
            return;
        }
        activeEl = target;
        fetchCard(eid).then(function (data) {
            if (activeEl !== target) {
                return; // pointer already moved on
            }
            var wrap = ensureCard();
            wrap.textContent = '';
            wrap.appendChild(renderCard(data, config.cardWidth));
            position(target);
        }).catch(function () {
            restoreNativeTooltip(target);
        });
    }

    function appointmentFrom(node) {
        return node && node.closest ? node.closest(SELECTORS.appointment) : null;
    }

    function insideCard(node) {
        return !!(card && node && card.contains(node));
    }

    if (config.clickMode) {
        document.addEventListener('mouseover', function (event) {
            var target = appointmentFrom(event.target);
            if (target) {
                suppressNativeTooltip(target);
            }
        });
        document.addEventListener('click', function (event) {
            var target = appointmentFrom(event.target);
            if (target) {
                suppressNativeTooltip(target);
                open(target);
            } else if (!insideCard(event.target)) {
                hide();
            }
        });
    } else {
        // One rule: the pointer is either over an appointment / the card, or it is not.
        // Every mouseover (including onto empty grid) re-evaluates, so leaving both always hides.
        document.addEventListener('mouseover', function (event) {
            var target = appointmentFrom(event.target);
            if (target) {
                suppressNativeTooltip(target); // before the browser's tooltip delay
                clearTimeout(hideTimer);
                if (target === activeEl) {
                    return;
                }
                clearTimeout(showTimer);
                showTimer = setTimeout(function () { open(target); }, config.hoverDelayMs);
                return;
            }
            if (insideCard(event.target)) {
                clearTimeout(hideTimer);
                return;
            }
            clearTimeout(showTimer);
            if (activeEl) {
                scheduleHide();
            }
        });
        // Pointer left the page/iframe entirely.
        document.documentElement.addEventListener('mouseleave', function () {
            clearTimeout(showTimer);
            if (activeEl) {
                scheduleHide();
            }
        });
    }
})();
