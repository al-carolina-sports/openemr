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
    var SELECTORS = {
        appointment: '.event[data-eid]',
        eidAttribute: 'data-eid',
        titleAttribute: 'title'
    };

    // TODO: confirm how to tell Day/Week/Month apart on a running instance.
    // Until then every view that renders .event[data-eid] is eligible.
    function currentViewAllowed() {
        return true;
    }
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
            if (data.photo.url) {
                var img = document.createElement('img');
                img.src = data.photo.url;
                img.alt = '';
                photo.appendChild(img);
            } else {
                photo.classList.add('oe-hc-initials');
                photo.textContent = data.photo.initials || '';
            }
            root.appendChild(photo);
        }
        return root;
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

    function suppressNativeTooltip(target) {
        var title = target.getAttribute(SELECTORS.titleAttribute);
        if (title !== null) {
            target.setAttribute('data-oe-hc-title', title);
            target.removeAttribute(SELECTORS.titleAttribute);
        }
    }

    function restoreNativeTooltip(target) {
        var title = target.getAttribute('data-oe-hc-title');
        if (title !== null) {
            target.setAttribute(SELECTORS.titleAttribute, title);
            target.removeAttribute('data-oe-hc-title');
        }
    }

    function fetchCard(eid) {
        if (cache.has(eid)) {
            return Promise.resolve(cache.get(eid));
        }
        var url = config.endpoint + '?eid=' + encodeURIComponent(eid)
            + '&csrf_token_form=' + encodeURIComponent(config.csrf);
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
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
        var eid = target.getAttribute(SELECTORS.eidAttribute);
        if (!eid) {
            return;
        }
        activeEl = target;
        suppressNativeTooltip(target);
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

    if (currentViewAllowed()) {
        if (config.clickMode) {
            document.addEventListener('click', function (event) {
                var target = appointmentFrom(event.target);
                if (target) {
                    open(target);
                } else if (!card || !card.contains(event.target)) {
                    hide();
                }
            });
        } else {
            document.addEventListener('mouseover', function (event) {
                var target = appointmentFrom(event.target);
                if (!target || target === activeEl) {
                    clearTimeout(hideTimer);
                    return;
                }
                clearTimeout(showTimer);
                clearTimeout(hideTimer);
                showTimer = setTimeout(function () { open(target); }, config.hoverDelayMs);
            });
            document.addEventListener('mouseout', function (event) {
                var target = appointmentFrom(event.target);
                if (target) {
                    clearTimeout(showTimer);
                    scheduleHide();
                }
            });
        }
    }
})();
