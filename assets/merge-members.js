document.addEventListener('DOMContentLoaded', function () {
    var configEl = document.getElementById('avpvh-merge-members-config');
    if (!configEl) return;
    var cfg = JSON.parse(configEl.textContent);

    // Flat filtered-list combobox — same idea as avpvh-bookkeeping's
    // review-queue duplicate-transaction picker: hidden input holds the
    // actual submitted value, visible text input is the typed/displayed
    // label, and a floating list below it shows the filtered matches.
    function wireMemberCombo(wrapper) {
        var hidden = wrapper.querySelector('.avpvh-member-combo-value');
        var input = wrapper.querySelector('.avpvh-member-combo-input');
        var list = wrapper.querySelector('.avpvh-member-combo-list');
        if (!hidden || !input || !list) return;

        var activeIndex = -1;
        var renderedItems = [];

        function labelFor(id) {
            var m = cfg.members.filter(function (x) { return String(x.id) === String(id); })[0];
            return m ? m.label : '';
        }

        function closeList() {
            list.hidden = true;
            activeIndex = -1;
        }

        function selectMember(id, label) {
            hidden.value = id;
            input.value = label;
            closeList();
        }

        function setActive(index) {
            var children = Array.prototype.slice.call(list.querySelectorAll('.avpvh-member-combo-item'));
            children.forEach(function (el, i) {
                el.classList.toggle('is-active', i === index);
            });
            if (children[index]) children[index].scrollIntoView({ block: 'nearest' });
            activeIndex = index;
        }

        function render(forceEmptyTerm) {
            var term = forceEmptyTerm ? '' : input.value.trim().toLowerCase();
            list.innerHTML = '';
            renderedItems = [];
            cfg.members.forEach(function (m) {
                if (term !== '' && m.label.toLowerCase().indexOf(term) === -1) return;
                var item = document.createElement('div');
                item.className = 'avpvh-member-combo-item';
                item.textContent = m.label;
                item.dataset.id = m.id;
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectMember(m.id, m.label);
                });
                list.appendChild(item);
                renderedItems.push(item);
            });
            list.hidden = renderedItems.length === 0;
            activeIndex = -1;
        }

        input.addEventListener('input', function () { render(false); });
        input.addEventListener('focus', function () {
            input.select();
            render(true);
        });
        input.addEventListener('keydown', function (e) {
            if (list.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                render();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(Math.min(activeIndex + 1, renderedItems.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(Math.max(activeIndex - 1, 0));
            } else if (e.key === 'Enter') {
                if (!list.hidden && activeIndex >= 0 && renderedItems[activeIndex]) {
                    e.preventDefault();
                    var item = renderedItems[activeIndex];
                    selectMember(item.dataset.id, item.textContent);
                }
            } else if (e.key === 'Escape') {
                closeList();
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () {
                closeList();
                input.value = hidden.value ? labelFor(hidden.value) : '';
            }, 150);
        });

        // Hidden inputs are excluded from native constraint validation
        // (the <select required> this replaced would have blocked an
        // empty submit on its own), so enforce it by hand here instead.
        var form = wrapper.closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!hidden.value) {
                    e.preventDefault();
                    input.focus();
                }
            });
        }
    }

    document.querySelectorAll('.avpvh-member-combo').forEach(wireMemberCombo);

    // Typing in the own-name fields selects "Eigen naam".
    var nameCustom = document.querySelector('.avpvh-merge-name-custom');
    if (nameCustom) {
        document.querySelectorAll('.avpvh-merge-name-fields input').forEach(function (input) {
            input.addEventListener('focus', function () { nameCustom.checked = true; });
        });
    }

    // Typing a corrected e-mail address selects that option.
    var customEmail = document.getElementById('avpvh-merge-email-custom');
    var customChoice = document.getElementById('avpvh-merge-email-custom-choice');
    if (customEmail && customChoice) {
        customEmail.addEventListener('focus', function () { customChoice.checked = true; });
    }
});

// An address field of a card: named plainly (Adressen opschonen) or under
// the card's data-prefix (address groups on the merge page).
function fieldInput(form, name) {
    var prefix = form.dataset.prefix;
    return form.querySelector('input[name="' + (prefix ? prefix + '[' + name + ']' : name) + '"]');
}

// Adressen opschonen: picking a spelling fills that address's fields,
// which stay editable for a correction none of the variants has.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.avpvh-address-cleanup').forEach(function (form) {
        form.querySelectorAll('.avpvh-address-variant').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var fields = JSON.parse(radio.dataset.fields);
                Object.keys(fields).forEach(function (name) {
                    var input = fieldInput(form, name);
                    if (input) input.value = fields[name];
                });
                showResult();
            });
        });

        // "Wordt:" row: the address as it will be stored, kept in step
        // with the chosen spelling and any correction typed in.
        var results = Array.prototype.slice.call(form.querySelectorAll('.avpvh-result-address'));
        if (form.dataset.result && document.getElementById(form.dataset.result)) {
            results.push(document.getElementById(form.dataset.result));
        }
        function value(name) {
            var input = fieldInput(form, name);
            return input ? input.value.trim() : '';
        }
        function houseNumber(number, addition) {
            addition = addition.toUpperCase().replace(/\s+/g, '');
            if (!addition) return number;
            var bus = addition.match(/^BUS(.+)$/);
            if (bus) return number + ' bus ' + bus[1];
            return /^\p{L}$/u.test(addition) ? number + addition : number + '-' + addition;
        }
        function showResult() {
            var line = (value('street') + ' ' + houseNumber(value('house_number'), value('house_number_addition'))).trim();
            var place = (value('postal_code') + ' ' + value('city')).trim();
            results.forEach(function (el) { el.textContent = [line, place].filter(Boolean).join(', '); });
        }
        form.querySelectorAll('input[type="text"]').forEach(function (input) {
            input.addEventListener('input', showResult);
        });

        // "Dubbele regels samenvoegen" switches the Na toepassen column
        // between the folded and the unfolded outcome.
        var mergeRuns = form.querySelector('input[name="merge_runs"]');
        function showMerge() {
            var on = !mergeRuns || mergeRuns.checked;
            form.querySelectorAll('.avpvh-if-merge').forEach(function (el) { el.hidden = !on; });
            form.querySelectorAll('.avpvh-if-no-merge').forEach(function (el) { el.hidden = on; });
        }
        if (mergeRuns) {
            mergeRuns.addEventListener('change', showMerge);
            showMerge();
        }
    });
});

// Back from Toepassen/Afsluiten at the next item (#anchor): show what
// was done right there instead of only at the top of the page.
document.addEventListener('DOMContentLoaded', function () {
    var notice = document.querySelector('.avpvh-cleanup-notice');
    var target = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
    if (!notice || !target || !notice.textContent.trim()) return;
    if (target.tagName === 'TR') {
        var row = document.createElement('tr');
        var cell = document.createElement('td');
        cell.colSpan = target.children.length;
        cell.appendChild(notice);
        row.appendChild(cell);
        target.parentNode.insertBefore(row, target);
        row.id = target.id;
        target.removeAttribute('id');
        target = row;
    } else {
        target.parentNode.insertBefore(notice, target);
        target = notice;
    }
    target.scrollIntoView();
});
