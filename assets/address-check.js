// Checks an address against the government register while it is typed
// (see AVPVH_Address_Entry_Check). Works on any container marked
// data-avpvh-address whose inputs are named street, house_number,
// house_number_addition, postal_code, city and country. Never blocks
// saving: it only fills in or offers the official spelling.
document.addEventListener('DOMContentLoaded', function () {
    var configEl = document.getElementById('avpvh-address-check-config');
    if (!configEl) return;
    var cfg = JSON.parse(configEl.textContent);
    var names = ['street', 'house_number', 'house_number_addition', 'postal_code', 'city', 'country'];

    function format(text, values) {
        var i = 0;
        return text.replace(/%(\d\$)?s/g, function () { return values[i++]; });
    }

    document.querySelectorAll('[data-avpvh-address]').forEach(function (box) {
        var inputs = {};
        names.forEach(function (name) {
            inputs[name] = box.querySelector('[name="' + name + '"]');
        });
        if (!inputs.postal_code || !inputs.house_number) return;

        var hint = document.createElement('div');
        hint.className = 'avpvh-address-check';
        hint.setAttribute('aria-live', 'polite');
        hint.hidden = true;
        box.appendChild(hint);

        var timer = null;
        var lastQuery = '';

        function value(name) {
            return inputs[name] ? inputs[name].value.trim() : '';
        }
        function show(text, button) {
            hint.textContent = text;
            if (button) {
                hint.appendChild(document.createTextNode(' '));
                hint.appendChild(button);
            }
            hint.hidden = !text;
        }
        // A form with one house number field (the order forms) gets the
        // addition in it, written as the plugin shows it: 19A, 40-1, 12 bus 3.
        function forForm(fields) {
            var out = {};
            Object.keys(fields).forEach(function (name) { out[name] = fields[name]; });
            if (!inputs.house_number_addition && out.house_number_addition) {
                var add = out.house_number_addition;
                var bus = add.match(/^BUS(.+)$/);
                out.house_number += bus ? ' bus ' + bus[1] : (/^\p{L}$/u.test(add) ? add : '-' + add);
            }
            delete out.house_number_addition;
            if (inputs.house_number_addition) out.house_number_addition = fields.house_number_addition;
            return out;
        }
        function fill(fields) {
            fields = forForm(fields);
            Object.keys(fields).forEach(function (name) {
                if (inputs[name]) inputs[name].value = fields[name];
            });
        }
        function sameAs(fields) {
            fields = forForm(fields);
            return Object.keys(fields).every(function (name) {
                return !inputs[name] || value(name).toLowerCase().replace(/\s+/g, '') === String(fields[name]).toLowerCase().replace(/\s+/g, '');
            });
        }

        function check() {
            var data = new FormData();
            names.forEach(function (name) { data.append(name, value(name)); });
            var query = names.map(value).join('|');
            if (!value('house_number') || query === lastQuery) return;
            if (!value('postal_code') && !(value('street') && value('city'))) return;
            lastQuery = query;
            data.append('action', cfg.action);
            data.append('nonce', cfg.nonce);
            fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (json) {
                    if (!json || !json.success) return show('');
                    var r = json.data;
                    if (!r.found) return show(r.checked ? cfg.strings.unknown : '');
                    if (sameAs(r.fields)) return show(format(cfg.strings.ok, [r.source]));
                    // An empty street and town (Dutch postcode + number
                    // typed first) are simply filled in.
                    if (!value('street') && !value('city')) {
                        fill(r.fields);
                        lastQuery = names.map(value).join('|');
                        return show(format(cfg.strings.filled, [r.source]));
                    }
                    var take = document.createElement('button');
                    take.type = 'button';
                    take.className = 'button button-small';
                    take.textContent = cfg.strings.take;
                    take.addEventListener('click', function () {
                        fill(r.fields);
                        lastQuery = names.map(value).join('|');
                        show(format(cfg.strings.ok, [r.source]));
                    });
                    show(format(cfg.strings.official, [r.source, r.display]), take);
                })
                .catch(function () { show(''); });
        }

        names.forEach(function (name) {
            if (!inputs[name]) return;
            inputs[name].addEventListener('change', function () {
                clearTimeout(timer);
                timer = setTimeout(check, 300);
            });
        });
    });
});
