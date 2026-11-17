/**
 * Annie's Elegant Calendar Style: Custom Views.
 * Adds a "Custom Views" picker above the calendar's provider list. Choosing a
 * view selects its members in core's pc_username[] list and submits once.
 * All text is inserted with textContent (no HTML from data).
 */
(function () {
    'use strict';

    var config = window.oeEcsConfig;
    if (!config) {
        return;
    }
    var L = config.labels;
    var state = { views: [], users: [], isAdmin: false, lastViewId: null };

    function el(tag, attrs, text) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'className') {
                node.className = attrs[k];
            } else {
                node.setAttribute(k, attrs[k]);
            }
        });
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function api(action, body) {
        var opts = {
            method: body === undefined ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': config.csrf }
        };
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(config.endpoint + '?action=' + encodeURIComponent(action), opts).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) {
                    var err = new Error(data && data.error ? data.error : 'http_' + r.status);
                    throw err;
                }
                return data;
            });
        });
    }

    function providerSelect() {
        return document.getElementById('pc_username');
    }

    function currentSelection() {
        var sel = providerSelect();
        return Array.prototype.filter.call(sel.options, function (o) { return o.selected; })
            .map(function (o) { return o.value; }).sort();
    }

    function findView(id) {
        for (var i = 0; i < state.views.length; i++) {
            if (state.views[i].id === id) {
                return state.views[i];
            }
        }
        return null;
    }

    function submitCalendar() {
        var form = document.getElementById('theform');
        if (window.jQuery) {
            window.jQuery(form).trigger('submit');
        } else if (form.requestSubmit) {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function applyView(view) {
        var sel = providerSelect();
        var wanted = view.members.slice();
        Array.prototype.forEach.call(sel.options, function (o) {
            o.selected = wanted.indexOf(o.value) !== -1;
        });
        // A member hidden by the facility filter is not listed; add it so it is still sent.
        wanted.forEach(function (u) {
            var exists = Array.prototype.some.call(sel.options, function (o) { return o.value === u; });
            if (!exists) {
                var opt = el('option', { value: u, hidden: 'hidden' }, u);
                opt.selected = true;
                sel.appendChild(opt);
            }
        });
        api('remember', { id: view.id }).catch(function () {}).then(submitCalendar);
    }

    function render(picker, editBtn, delBtn) {
        while (picker.firstChild) {
            picker.removeChild(picker.firstChild);
        }
        picker.appendChild(el('option', { value: '' }, L.choose));
        var current = currentSelection().join('\n');
        var selectedId = '';
        state.views.forEach(function (v) {
            var label = v.shared ? v.name : v.name + ' *';
            var opt = el('option', { value: String(v.id) }, label);
            opt.title = v.shared ? L.sharedTag : '';
            picker.appendChild(opt);
            if (v.id === state.lastViewId && v.members.slice().sort().join('\n') === current) {
                selectedId = String(v.id);
            }
        });
        picker.value = selectedId;
        updateButtons(picker, editBtn, delBtn);
    }

    function updateButtons(picker, editBtn, delBtn) {
        var v = findView(parseInt(picker.value, 10));
        var can = !!(v && v.canEdit);
        editBtn.disabled = !can;
        delBtn.disabled = !can;
    }

    function openModal(view, onSaved) {
        var backdrop = el('div', { className: 'ecs-modal-backdrop' });
        var modal = el('div', { className: 'ecs-modal', role: 'dialog', 'aria-modal': 'true' });
        modal.appendChild(el('header', {}, view ? L.edit : L.add));
        var body = el('div', { className: 'ecs-body' });

        var nameLabel = el('label', {}, L.name);
        var nameInput = el('input', { type: 'text', className: 'form-control form-control-sm', maxlength: '100' });
        nameInput.value = view ? view.name : '';
        nameLabel.appendChild(nameInput);
        body.appendChild(nameLabel);

        var sharedLabel = el('label', {});
        var sharedBox = el('input', { type: 'checkbox' });
        sharedBox.checked = view ? view.shared : false;
        sharedBox.disabled = !state.isAdmin;
        sharedLabel.appendChild(sharedBox);
        sharedLabel.appendChild(document.createTextNode(' ' + L.shared));
        body.appendChild(sharedLabel);
        if (!state.isAdmin) {
            body.appendChild(el('div', { className: 'ecs-hint' }, L.sharedAdminOnly));
        }

        body.appendChild(el('div', { className: 'mt-2 mb-1' }, L.members));
        var list = el('div', { className: 'ecs-members' });
        var boxes = [];
        state.users.forEach(function (u) {
            var row = el('label', {});
            var cb = el('input', { type: 'checkbox', value: u.username });
            cb.checked = !!(view && view.members.indexOf(u.username) !== -1);
            boxes.push(cb);
            row.appendChild(cb);
            row.appendChild(document.createTextNode(' ' + u.name));
            list.appendChild(row);
        });
        body.appendChild(list);
        var error = el('div', { className: 'ecs-error mt-2' });
        body.appendChild(error);
        modal.appendChild(body);

        var footer = el('footer', {});
        var cancel = el('button', { type: 'button', className: 'btn btn-secondary btn-sm' }, L.cancel);
        var save = el('button', { type: 'button', className: 'btn btn-primary btn-sm' }, L.save);
        footer.appendChild(cancel);
        footer.appendChild(save);
        modal.appendChild(footer);
        backdrop.appendChild(modal);

        function close() {
            document.removeEventListener('keydown', onKey);
            backdrop.remove();
        }
        function onKey(e) {
            if (e.key === 'Escape') {
                close();
            }
        }
        cancel.addEventListener('click', close);
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) {
                close();
            }
        });
        document.addEventListener('keydown', onKey);
        save.addEventListener('click', function () {
            var members = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
            var name = nameInput.value.trim();
            if (!name) {
                error.textContent = L.needName;
                return;
            }
            if (!members.length) {
                error.textContent = L.needMembers;
                return;
            }
            save.disabled = true;
            var payload = { name: name, shared: sharedBox.checked, members: members };
            if (view) {
                payload.id = view.id;
            }
            api(view ? 'update' : 'create', payload).then(function (res) {
                close();
                onSaved(res.id);
            }).catch(function () {
                save.disabled = false;
                error.textContent = L.error;
            });
        });
        document.body.appendChild(backdrop);
        nameInput.focus();
    }

    function init() {
        var sel = providerSelect();
        if (!sel || !document.getElementById('theform')) {
            return;
        }
        var wrap = el('div', { className: 'ecs-views' });
        var label = el('label', { className: 'ecs-views-label', for: 'ecs_view_picker' }, L.title);
        var row = el('div', { className: 'ecs-views-row' });
        var picker = el('select', { id: 'ecs_view_picker', className: 'form-control form-control-sm', 'aria-label': L.title });
        var addBtn = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary', title: L.add, 'aria-label': L.add }, '+');
        var editBtn = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary', title: L.edit, 'aria-label': L.edit }, '\u270E');
        var delBtn = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary', title: L.delete, 'aria-label': L.delete }, '\u2212');
        row.appendChild(picker);
        row.appendChild(addBtn);
        row.appendChild(editBtn);
        row.appendChild(delBtn);
        wrap.appendChild(label);
        wrap.appendChild(row);
        sel.parentNode.insertBefore(wrap, sel);

        function reload(selectId) {
            return api('list').then(function (data) {
                state = data;
                render(picker, editBtn, delBtn);
                if (selectId) {
                    picker.value = String(selectId);
                    updateButtons(picker, editBtn, delBtn);
                }
            });
        }

        picker.addEventListener('change', function () {
            updateButtons(picker, editBtn, delBtn);
            var v = findView(parseInt(picker.value, 10));
            if (v) {
                applyView(v);
            }
        });
        addBtn.addEventListener('click', function () {
            openModal(null, function (id) {
                reload(id).then(function () {
                    var v = findView(id);
                    if (v) {
                        applyView(v);
                    }
                });
            });
        });
        editBtn.addEventListener('click', function () {
            var v = findView(parseInt(picker.value, 10));
            if (v && v.canEdit) {
                openModal(v, function (id) {
                    reload(id).then(function () {
                        var nv = findView(id);
                        if (nv) {
                            applyView(nv);
                        }
                    });
                });
            }
        });
        delBtn.addEventListener('click', function () {
            var v = findView(parseInt(picker.value, 10));
            if (v && v.canEdit && window.confirm(L.confirmDelete + '\n\n' + v.name)) {
                api('delete', { id: v.id }).then(function () { return reload(null); }).catch(function () {
                    window.alert(L.error);
                });
            }
        });
        reload(null).catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
