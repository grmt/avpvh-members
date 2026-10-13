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
});
