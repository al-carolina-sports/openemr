/**
 * Annie's Elegant Calendar Style: Week view "Stack | Stretch" toggle and the
 * "Hide: Sat | Sun" toggles (week and month views).
 *
 * Core's week view renders one table per selected provider, stacked. Stretch
 * re-lays that DOM out into one grid: a shared time column, then per day one
 * sub-column per provider. The existing td.schedule cells (with their events,
 * colors and bound handlers) are moved, not copied, so double-click to open,
 * click-to-create and the hover card keep working. Stack moves them back.
 *
 * Hide Saturday / Hide Sunday (v1.3.1, split from v1.3.0's single Hide
 * weekends) mark every Saturday cell and header with .ecs-sat and every
 * Sunday one with .ecs-sun (both also .ecs-weekend), using the weekday
 * computed from the cell's own date (the td.schedule "date" attribute in week
 * view, the day link's Date= in month view), never the column position, so it
 * is correct for any configured first day of the week. Classes on <html>
 * (ecs-hide-sat, ecs-hide-sun) then hide them and the remaining columns share
 * the width. Nothing is removed, so navigation, dates and appointments are
 * untouched.
 *
 * State comes from window.oeEcsConfig.weekends (config.js.php): per day the
 * admin's site default, the user's own choice (null = none) and the effective
 * value. A user's choice is saved server-side per user (user_settings, like
 * the remembered Custom View) through views.php?action=weekends; clearing it
 * falls back to the site default. v1.3.0 kept the single toggle in
 * localStorage; that value is migrated once to both days and then removed.
 */
(function () {
    'use strict';

    var config = window.oeEcsConfig;
    var labels = (config && config.labels) || {};
    var storageKey = 'oeEcsWeekLayout:' + ((config && config.user) || '');
    var legacyWeekendKey = 'oeEcsHideWeekends:' + ((config && config.user) || '');
    var DAYS = ['sat', 'sun'];
    var DAY_LABEL = { sat: labels.sat || 'Sat', sun: labels.sun || 'Sun' };
    var DAY_LONG = { sat: labels.hideSat || 'Hide Saturday', sun: labels.hideSun || 'Hide Sunday' };
    var DAY_HIDDEN = {
        sat: labels.satHiddenAppts || 'Saturday appointment(s) hidden',
        sun: labels.sunHiddenAppts || 'Sunday appointment(s) hidden'
    };

    function text(node) {
        return (node ? node.textContent : '').replace(/\s+/g, ' ').trim();
    }

    function el(tag, className, content) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (content !== undefined) {
            node.textContent = content;
        }
        return node;
    }

    function getPref() {
        try {
            return window.localStorage.getItem(storageKey) === 'stretch' ? 'stretch' : 'stack';
        } catch (e) {
            return 'stack';
        }
    }

    function setPref(value) {
        try {
            window.localStorage.setItem(storageKey, value);
        } catch (e) {
            // storage unavailable: the choice just isn't remembered
        }
    }

    /**
     * Per day {site, user, hide}. Without server state (e.g. config failed to
     * load) both days fall back to hidden, the shipped site default.
     */
    function normalizeWeekends(raw) {
        var out = {};
        DAYS.forEach(function (day) {
            var d = (raw && raw[day]) || {};
            var site = d.site !== false;
            var user = (d.user === true || d.user === false) ? d.user : null;
            out[day] = { site: site, user: user, hide: user === null ? site : user };
        });
        return out;
    }

    var weekendState = normalizeWeekends(config && config.weekends);

    function isHidden(day) {
        return weekendState[day].hide;
    }

    function hasOverride() {
        return DAYS.some(function (day) { return weekendState[day].user !== null; });
    }

    function weekendsApi(body) {
        if (!config || !config.endpoint) {
            return Promise.reject(new Error('no endpoint'));
        }
        return fetch(config.endpoint + '?action=weekends', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok || !data || !data.weekends) {
                    throw new Error((data && data.error) || ('HTTP ' + r.status));
                }
                return data.weekends;
            });
        });
    }

    /**
     * Set (true/false) or clear (null) the user's choice for one day. Applied
     * at once, then saved; if saving fails the previous state comes back.
     */
    function setDay(day, hide) {
        var before = normalizeWeekends(weekendState);
        weekendState[day].user = hide;
        weekendState[day].hide = hide === null ? weekendState[day].site : hide;
        applyHide();
        return weekendsApi({ day: day, hide: hide }).then(function (server) {
            weekendState = normalizeWeekends(server);
            applyHide();
        }, function (err) {
            weekendState = before;
            applyHide();
            if (window.console) {
                window.console.warn((labels.prefError || 'Could not save your weekend setting.') + ' ' + err.message);
            }
        });
    }

    function useSiteDefault() {
        return DAYS.reduce(function (p, day) {
            return p.then(function () {
                return weekendState[day].user === null ? null : setDay(day, null);
            });
        }, Promise.resolve());
    }

    /**
     * v1.3.0 stored one browser-only toggle ('0' off, '1' on). If it exists
     * and the user has no server-side choice yet, copy it to both days, then
     * remove it. Kept if saving fails so it can be retried next time.
     */
    function migrateLegacyWeekends() {
        var legacy = null;
        try {
            legacy = window.localStorage.getItem(legacyWeekendKey);
        } catch (e) {
            return;
        }
        if (legacy !== '0' && legacy !== '1') {
            return;
        }
        var drop = function () {
            try {
                window.localStorage.removeItem(legacyWeekendKey);
            } catch (e) {
                // ignore
            }
        };
        if (hasOverride()) {
            drop();
            return;
        }
        var hide = legacy === '1';
        setDay('sat', hide).then(function () {
            return weekendState.sat.user === hide ? setDay('sun', hide) : null;
        }).then(function () {
            if (weekendState.sat.user === hide && weekendState.sun.user === hide) {
                drop();
            }
        });
    }

    function currentView() {
        return (typeof pcViewtype !== 'undefined') ? pcViewtype : window.pcViewtype;
    }

    /** 'sat', 'sun' or null for a YYYYMMDD (or YYYY-MM-DD) date. */
    function weekendDayOf(value) {
        var m = /^(\d{4})-?(\d{2})-?(\d{2})$/.exec(String(value || '').trim());
        if (!m) {
            return null;
        }
        var dow = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])).getUTCDay();
        return dow === 6 ? 'sat' : (dow === 0 ? 'sun' : null);
    }

    function markDay(node, day) {
        node.classList.toggle('ecs-weekend', day !== null);
        node.classList.toggle('ecs-sat', day === 'sat');
        node.classList.toggle('ecs-sun', day === 'sun');
    }

    function markWeekend(node, ymd) {
        if (!node) {
            return;
        }
        if (ymd) {
            node.setAttribute('data-ecs-date', ymd);
        }
        markDay(node, weekendDayOf(ymd));
    }

    /**
     * Week view: each provider table has a header row of td.week_dateheader
     * and a schedule row of td.schedule[date], both emitted from the same day
     * list, so header i belongs to cell i of the same table.
     */
    function markWeekGrid(bigCal) {
        Array.prototype.forEach.call(bigCal.querySelectorAll('table'), function (table) {
            var cells = table.querySelectorAll(':scope > tbody > tr > td.schedule[date], :scope > tr > td.schedule[date]');
            var heads = table.querySelectorAll(':scope > tbody > tr > td.week_dateheader, :scope > tr > td.week_dateheader');
            Array.prototype.forEach.call(cells, function (cell, i) {
                var ymd = cell.getAttribute('date');
                markWeekend(cell, ymd);
                if (heads.length === cells.length) {
                    markWeekend(heads[i], ymd);
                }
            });
        });
    }

    /**
     * Month view: weekday from each cell's day link (Date=YYYYMMDD). A column
     * is a Saturday/Sunday column if its linked cells are; cells without a
     * link and the weekday headers take their column's day.
     * Returns the number of appointments per weekend day.
     */
    function markMonthGrid(bigCal) {
        var count = { sat: 0, sun: 0 };
        Array.prototype.forEach.call(bigCal.querySelectorAll('table'), function (table) {
            var columnDay = [];
            var heads = null;
            var rows = [];
            Array.prototype.forEach.call(table.rows, function (row) {
                var dayCells = row.querySelectorAll(':scope > td.schedule');
                if (!dayCells.length) {
                    if (row.querySelector(':scope > td.month_dateheader')) {
                        heads = row.querySelectorAll(':scope > td.month_dateheader');
                    }
                    return;
                }
                rows.push(dayCells);
                Array.prototype.forEach.call(dayCells, function (cell, i) {
                    var link = cell.querySelector('.month_daylink a[href]');
                    var m = link ? /[?&]Date=(\d{8})/.exec(link.getAttribute('href')) : null;
                    if (m) {
                        cell.setAttribute('data-ecs-date', m[1]);
                        if (columnDay[i] === undefined) {
                            columnDay[i] = weekendDayOf(m[1]);
                        }
                    }
                });
            });
            rows.forEach(function (dayCells) {
                Array.prototype.forEach.call(dayCells, function (cell, i) {
                    var ymd = cell.getAttribute('data-ecs-date');
                    var day = ymd ? weekendDayOf(ymd) : (columnDay[i] === undefined ? null : columnDay[i]);
                    markDay(cell, day);
                    if (day) {
                        count[day] += cell.querySelectorAll('.month_event').length;
                    }
                });
            });
            if (heads && heads.length === columnDay.length) {
                Array.prototype.forEach.call(heads, function (h, i) {
                    markDay(h, columnDay[i] === undefined ? null : columnDay[i]);
                });
            }
        });
        return count;
    }

    /** Appointments (not vacation/holiday blocks) per weekend day. */
    function countWeekendEvents(bigCal) {
        var count = { sat: 0, sun: 0 };
        DAYS.forEach(function (day) {
            var seen = {};
            Array.prototype.forEach.call(bigCal.querySelectorAll('td.schedule.ecs-' + day + ' .event:not(.hiddenevent)'), function (ev) {
                var key = (ev.closest('td.schedule').getAttribute('provider') || '') + ':' + (ev.getAttribute('data-eid') || ev.id);
                if (!seen[key]) {
                    seen[key] = true;
                    count[day]++;
                }
            });
        });
        return count;
    }

    var weekendCount = { sat: 0, sun: 0 };
    var weekendListeners = [];

    function applyHide() {
        DAYS.forEach(function (day) {
            document.documentElement.classList.toggle('ecs-hide-' + day, isHidden(day));
        });
        weekendListeners.forEach(function (fn) { fn(); });
    }

    function sourceNote(day) {
        return weekendState[day].user === null ? (labels.siteDefault || 'site default') : (labels.yourChoice || 'your choice');
    }

    /** "Hide: Sat | Sun" toggles (each with its own badge) for the bar above the grid. */
    function buildWeekendToggles() {
        var wrap = el('div', 'ecs-weekend-group');
        wrap.appendChild(el('span', 'ecs-weekend-label', labels.hide || 'Hide'));
        var group = el('div', 'btn-group btn-group-sm');
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', (labels.hide || 'Hide') + ': ' + DAY_LABEL.sat + ' | ' + DAY_LABEL.sun);
        var syncs = [];
        DAYS.forEach(function (day) {
            var btn = el('button', 'btn btn-outline-secondary ecs-weekend-toggle ecs-weekend-toggle-' + day);
            btn.type = 'button';
            btn.dataset.day = day;
            var check = el('span', 'ecs-check', '');
            btn.appendChild(check);
            btn.appendChild(document.createTextNode(DAY_LABEL[day]));
            var badge = el('span', 'badge badge-pill badge-warning ecs-weekend-badge', '');
            btn.appendChild(badge);
            syncs.push(function () {
                var on = isHidden(day);
                btn.classList.toggle('active', on);
                btn.setAttribute('aria-pressed', String(on));
                check.textContent = on ? '\u2713' : '';
                var n = weekendCount[day];
                var showBadge = on && n > 0;
                badge.textContent = showBadge ? String(n) : '';
                badge.hidden = !showBadge;
                btn.title = (showBadge ? n + ' ' + DAY_HIDDEN[day] : DAY_LONG[day]) + ' (' + sourceNote(day) + ')';
            });
            btn.addEventListener('click', function () {
                setDay(day, !isHidden(day));
            });
            group.appendChild(btn);
        });
        wrap.appendChild(group);
        var reset = el('button', 'btn btn-link btn-sm ecs-weekend-reset', '\u21ba');
        reset.type = 'button';
        reset.title = labels.useSiteDefault || 'Weekends: use site default';
        reset.setAttribute('aria-label', reset.title);
        reset.addEventListener('click', useSiteDefault);
        wrap.appendChild(reset);
        weekendListeners.push(function () {
            syncs.forEach(function (fn) { fn(); });
            reset.hidden = !hasOverride();
        });
        return wrap;
    }

    function makeBar(bigCal) {
        var bar = el('div', 'ecs-layout-toggle');
        bigCal.parentNode.insertBefore(bar, bigCal);
        return bar;
    }

    function initMonth() {
        if (currentView() !== 'month') {
            return;
        }
        var bigCal = document.getElementById('bigCal');
        if (!bigCal || !bigCal.querySelector('td.schedule')) {
            return;
        }
        weekendCount = markMonthGrid(bigCal);
        makeBar(bigCal).appendChild(buildWeekendToggles());
        applyHide();
    }

    function init() {
        if (currentView() !== 'week') {
            return;
        }
        var bigCal = document.getElementById('bigCal');
        if (!bigCal) {
            return;
        }
        markWeekGrid(bigCal);
        weekendCount = countWeekendEvents(bigCal);
        var bar = makeBar(bigCal);
        var weekendBtn = buildWeekendToggles();
        applyHide();
        var wrappers = Array.prototype.filter.call(bigCal.children, function (c) {
            return c.querySelector && c.querySelector('td.providerheader') && c.querySelector('td.schedule');
        });
        if (wrappers.length < 2) {
            bar.appendChild(weekendBtn);
            return;
        }

        // Read the stacked grids once.
        var providers = wrappers.map(function (w) {
            var table = w.querySelector('table');
            var rows = table.rows;
            var scheduleRow = null;
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].querySelector(':scope > td.schedule')) {
                    scheduleRow = rows[i];
                    break;
                }
            }
            var cells = scheduleRow ? Array.prototype.slice.call(scheduleRow.querySelectorAll(':scope > td.schedule')) : [];
            return {
                name: text(w.querySelector('td.providerheader')),
                wrapper: w,
                row: scheduleRow,
                cells: cells,
                timeCell: scheduleRow ? scheduleRow.querySelector(':scope > td.calendar-times-col') : null,
                dayHeaders: Array.prototype.slice.call(table.querySelectorAll('td.week_dateheader'))
            };
        }).filter(function (p) { return p.row && p.cells.length; });
        if (providers.length < 2) {
            bar.appendChild(weekendBtn);
            return;
        }
        var days = providers[0].cells.map(function (c, i) {
            return { date: c.getAttribute('date'), header: providers[0].dayHeaders[i] };
        });

        var stretchBox = null;
        var mode = 'stack';

        // First non-transparent background up the tree, so sticky headers are opaque.
        function solidBackground(node) {
            for (var n = node; n && n.nodeType === 1; n = n.parentElement) {
                var bg = window.getComputedStyle(n).backgroundColor;
                if (bg && bg !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(bg)) {
                    return bg;
                }
            }
            return '#fff';
        }

        // The stretch wrapper is the scroll container (both axes), sized to the
        // viewport, so the sticky header rows and time column pin inside it.
        // The provider row's top is the measured height of the day row.
        function fitStretch() {
            if (!stretchBox) {
                return;
            }
            var box = stretchBox.box;
            var table = box.firstElementChild;
            table.style.setProperty('--ecs-stretch-head-bg', solidBackground(bigCal));
            var dayRow = table.querySelector('tr.ecs-day-row');
            // First visible day header (Sat/Sun may be hidden).
            var dayHead = dayRow ? Array.prototype.filter.call(dayRow.querySelectorAll('th.ecs-day-head'), function (th) {
                return th.getBoundingClientRect().height > 0;
            })[0] : null;
            if (dayHead) {
                table.style.setProperty('--ecs-stretch-prov-top', dayHead.getBoundingClientRect().height + 'px');
            }
            var top = box.getBoundingClientRect().top;
            var available = Math.max(240, Math.floor(window.innerHeight - Math.max(top, 0) - 8));
            box.style.maxHeight = available + 'px';
        }
        window.addEventListener('resize', fitStretch);
        weekendListeners.push(function () { window.setTimeout(fitStretch, 0); });

        function buildStretch() {
            var scroll = el('div', 'ecs-stretch-scroll');
            var table = el('table', 'table ecs-stretch-table');
            var thead = el('thead');
            var dayRow = el('tr', 'ecs-day-row');
            var provRow = el('tr', 'ecs-prov-row');
            var corner = el('th', 'ecs-corner ecs-time-cell');
            corner.rowSpan = 2;
            dayRow.appendChild(corner);
            days.forEach(function (d) {
                var th = el('th', 'ecs-day-head week_dateheader');
                th.colSpan = providers.length;
                if (d.header) {
                    // Keep core's link to the day view; clone so the stacked header stays intact.
                    Array.prototype.forEach.call(d.header.childNodes, function (n) {
                        th.appendChild(n.cloneNode(true));
                    });
                    if (d.header.classList.contains('week_currday')) {
                        th.classList.add('week_currday');
                    }
                } else {
                    th.textContent = d.date || '';
                }
                markWeekend(th, d.date);
                dayRow.appendChild(th);
                providers.forEach(function (p, pi) {
                    var ph = el('th', pi === 0 ? 'ecs-prov-head ecs-day-head' : 'ecs-prov-head', p.name);
                    ph.title = p.name;
                    markWeekend(ph, d.date);
                    provRow.appendChild(ph);
                });
            });
            thead.appendChild(dayRow);
            thead.appendChild(provRow);
            table.appendChild(thead);
            var tbody = el('tbody');
            var bodyRow = el('tr');
            tbody.appendChild(bodyRow);
            table.appendChild(tbody);
            scroll.appendChild(table);
            return { box: scroll, row: bodyRow };
        }

        function toStretch() {
            if (mode === 'stretch') {
                return;
            }
            var built = buildStretch();
            // Move core's own time cell (td#times) so all of core's time-slot sizing still applies.
            var timeCell = providers[0].timeCell;
            if (timeCell) {
                timeCell.classList.add('ecs-time-cell');
                built.row.appendChild(timeCell);
            }
            days.forEach(function (d, di) {
                providers.forEach(function (p, pi) {
                    var cell = p.cells[di];
                    if (cell) {
                        cell.classList.toggle('ecs-day-first', pi === 0);
                        built.row.appendChild(cell);
                    }
                });
            });
            providers.forEach(function (p) { p.wrapper.classList.add('ecs-stretch-hidden'); });
            stretchBox = built;
            bigCal.insertBefore(built.box, providers[0].wrapper);
            mode = 'stretch';
            fitStretch();
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(fitStretch);
            }
            window.setTimeout(fitStretch, 300);
        }

        function toStack() {
            if (mode === 'stack' || !stretchBox) {
                return;
            }
            var timeCell = providers[0].timeCell;
            if (timeCell) {
                timeCell.classList.remove('ecs-time-cell');
                providers[0].row.insertBefore(timeCell, providers[0].row.firstChild);
            }
            providers.forEach(function (p) {
                p.cells.forEach(function (cell) {
                    cell.classList.remove('ecs-day-first');
                    p.row.appendChild(cell);
                });
                p.wrapper.classList.remove('ecs-stretch-hidden');
            });
            stretchBox.box.remove();
            stretchBox = null;
            mode = 'stack';
        }

        // Toggle UI above the grid: Week layout Stack | Stretch, then Hide: Sat | Sun.
        var toggle = bar;
        toggle.appendChild(el('span', '', labels.layout || 'Layout'));
        var group = el('div', 'btn-group btn-group-sm');
        group.setAttribute('role', 'group');
        var stackBtn = el('button', 'btn btn-outline-secondary', labels.stack || 'Stack');
        var stretchBtn = el('button', 'btn btn-outline-secondary', labels.stretch || 'Stretch');
        stackBtn.type = 'button';
        stretchBtn.type = 'button';
        group.appendChild(stackBtn);
        group.appendChild(stretchBtn);
        toggle.appendChild(group);
        toggle.appendChild(weekendBtn);

        function apply(value) {
            if (value === 'stretch') {
                toStretch();
            } else {
                toStack();
            }
            stackBtn.classList.toggle('active', mode === 'stack');
            stretchBtn.classList.toggle('active', mode === 'stretch');
            stackBtn.setAttribute('aria-pressed', String(mode === 'stack'));
            stretchBtn.setAttribute('aria-pressed', String(mode === 'stretch'));
        }
        window.oeEcsWeekLayoutApply = apply;
        stackBtn.addEventListener('click', function () { setPref('stack'); apply('stack'); });
        stretchBtn.addEventListener('click', function () { setPref('stretch'); apply('stretch'); });
        apply(getPref());
    }

    /**
     * Rollover menu on core's Week button: hover or focus shows "Stack" and
     * "Stretch" (current one checked). Choosing one saves it and opens the
     * week view (or re-lays out the grid if already in week view). Below a
     * divider, "Hide Saturday" and "Hide Sunday" toggle each day in place and
     * "Weekends: use site default" clears the user's own choices. A
     * plain click on Week still opens the week view as core does.
     */
    function initWeekMenu() {
        var weekBtn = document.getElementById('weekview');
        if (!weekBtn) {
            return;
        }
        var menu = el('div', 'ecs-week-menu dropdown-menu');
        menu.id = 'ecs_week_menu';
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', labels.layout || 'Week layout');
        var items = {};
        ['stack', 'stretch'].forEach(function (value) {
            var item = el('button', 'dropdown-item ecs-week-menu-item');
            item.type = 'button';
            item.setAttribute('role', 'menuitemradio');
            item.dataset.layout = value;
            item.appendChild(el('span', 'ecs-check', ''));
            item.appendChild(document.createTextNode(labels[value] || (value === 'stack' ? 'Stack' : 'Stretch')));
            item.addEventListener('click', function (e) {
                e.preventDefault();
                choose(value);
            });
            items[value] = item;
            menu.appendChild(item);
        });
        menu.appendChild(el('div', 'dropdown-divider'));
        var dayItems = {};
        DAYS.forEach(function (day) {
            var item = el('button', 'dropdown-item ecs-week-menu-item ecs-week-menu-' + day);
            item.type = 'button';
            item.setAttribute('role', 'menuitemcheckbox');
            item.dataset.day = day;
            item.appendChild(el('span', 'ecs-check', ''));
            item.appendChild(document.createTextNode(DAY_LONG[day]));
            item.addEventListener('click', function (e) {
                e.preventDefault();
                setDay(day, !isHidden(day));
                refresh();
            });
            dayItems[day] = item;
            menu.appendChild(item);
        });
        var resetItem = el('button', 'dropdown-item ecs-week-menu-item ecs-week-menu-reset');
        resetItem.type = 'button';
        resetItem.setAttribute('role', 'menuitem');
        resetItem.appendChild(el('span', 'ecs-check', ''));
        resetItem.appendChild(document.createTextNode(labels.useSiteDefault || 'Weekends: use site default'));
        resetItem.addEventListener('click', function (e) {
            e.preventDefault();
            useSiteDefault();
            refresh();
        });
        menu.appendChild(resetItem);
        weekendListeners.push(function () { refresh(); });
        document.body.appendChild(menu);
        weekBtn.setAttribute('aria-haspopup', 'menu');
        weekBtn.setAttribute('aria-controls', menu.id);
        weekBtn.setAttribute('aria-expanded', 'false');

        var hideTimer = null;
        function refresh() {
            var current = getPref();
            Object.keys(items).forEach(function (k) {
                var on = k === current;
                items[k].setAttribute('aria-checked', String(on));
                items[k].firstChild.textContent = on ? '\u2713' : '';
            });
            DAYS.forEach(function (day) {
                var hidden = isHidden(day);
                dayItems[day].setAttribute('aria-checked', String(hidden));
                dayItems[day].firstChild.textContent = hidden ? '\u2713' : '';
                dayItems[day].title = sourceNote(day);
            });
            resetItem.disabled = !hasOverride();
        }
        function show() {
            window.clearTimeout(hideTimer);
            refresh();
            var r = weekBtn.getBoundingClientRect();
            menu.style.top = Math.round(r.bottom + window.scrollY) + 'px';
            menu.style.left = Math.round(r.left + window.scrollX) + 'px';
            menu.classList.add('show');
            weekBtn.setAttribute('aria-expanded', 'true');
        }
        function hideSoon() {
            window.clearTimeout(hideTimer);
            hideTimer = window.setTimeout(hide, 250);
        }
        function hide() {
            menu.classList.remove('show');
            weekBtn.setAttribute('aria-expanded', 'false');
        }
        function choose(value) {
            setPref(value);
            hide();
            var isWeek = (typeof pcViewtype !== 'undefined' && pcViewtype === 'week');
            if (isWeek && typeof window.oeEcsWeekLayoutApply === 'function') {
                window.oeEcsWeekLayoutApply(value);
                return;
            }
            if (isWeek) {
                return; // fewer than two providers: nothing to re-lay out, choice is saved
            }
            // Same as core's ChangeView for the Week button.
            var viewtype = document.getElementById('viewtype');
            var form = document.getElementById('theform');
            if (viewtype && form) {
                viewtype.value = 'week';
                if (window.jQuery) {
                    window.jQuery(form).trigger('submit');
                } else {
                    form.submit();
                }
            }
        }

        weekBtn.addEventListener('mouseenter', show);
        weekBtn.addEventListener('mouseleave', hideSoon);
        weekBtn.addEventListener('focus', show);
        weekBtn.addEventListener('blur', function () {
            window.setTimeout(function () {
                if (!menu.contains(document.activeElement)) {
                    hide();
                }
            }, 0);
        });
        weekBtn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                show();
                items[getPref()].focus();
            }
        });
        menu.addEventListener('mouseenter', function () { window.clearTimeout(hideTimer); });
        menu.addEventListener('mouseleave', hideSoon);
        menu.addEventListener('keydown', function (e) {
            var list = [items.stack, items.stretch, dayItems.sat, dayItems.sun, resetItem].filter(function (b) { return !b.disabled; });
            var i = list.indexOf(document.activeElement);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                list[(i + (e.key === 'ArrowDown' ? 1 : list.length - 1)) % list.length].focus();
            } else if (e.key === 'Escape') {
                hide();
                weekBtn.focus();
            }
        });
        menu.addEventListener('focusout', function () {
            window.setTimeout(function () {
                if (!menu.contains(document.activeElement) && document.activeElement !== weekBtn) {
                    hide();
                }
            }, 0);
        });
    }

    function start() {
        init();
        initMonth();
        initWeekMenu();
        migrateLegacyWeekends();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
