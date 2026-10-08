document.addEventListener('DOMContentLoaded', function () {
    var configEl = document.getElementById('avpvh-activity-picker-config');
    if (!configEl) return;
    var cfg = JSON.parse(configEl.textContent);

    var form = document.getElementById('avpvh-activity-picker-form');
    var yearFilter = document.getElementById('avpvh-activity-year-filter');
    var typeFilter = document.getElementById('avpvh-activity-type-filter');
    var wrapper = form.querySelector('.avpvh-activity-combo');
    var hidden = document.getElementById('avpvh-activity-combo-value');
    var input = wrapper.querySelector('.avpvh-activity-combo-input');
    var list = wrapper.querySelector('.avpvh-activity-combo-list');
    if (!hidden || !input || !list) return;

    var activeIndex = -1;
    var renderedItems = [];

    function labelFor(id) {
        var a = cfg.activities.filter(function (x) { return String(x.id) === String(id); })[0];
        return a ? a.label : '';
    }

    function closeList() {
        list.hidden = true;
        activeIndex = -1;
    }

    function selectActivity(id, label) {
        hidden.value = id;
        input.value = label;
        closeList();
        form.submit();
    }

    function setActive(index) {
        var children = Array.prototype.slice.call(list.querySelectorAll('.avpvh-activity-combo-item'));
        children.forEach(function (el, i) { el.classList.toggle('is-active', i === index); });
        if (children[index]) children[index].scrollIntoView({ block: 'nearest' });
        activeIndex = index;
    }

    function render(forceEmptyTerm) {
        var term = forceEmptyTerm ? '' : input.value.trim().toLowerCase();
        var year = yearFilter.value;
        var type = typeFilter.value;
        list.innerHTML = '';
        renderedItems = [];
        cfg.activities.forEach(function (a) {
            if (year !== '' && String(a.year) !== year) return;
            if (type !== '' && a.type !== type) return;
            if (term !== '' && a.label.toLowerCase().indexOf(term) === -1) return;
            var item = document.createElement('div');
            item.className = 'avpvh-activity-combo-item';
            item.textContent = a.label;
            item.dataset.id = a.id;
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                selectActivity(a.id, a.label);
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
            render(true);
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
                selectActivity(item.dataset.id, item.textContent);
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
    // Kiezen van een jaar/type is zelf geen keuze van activiteit — alleen
    // de kandidatenlijst versmallen, direct zichtbaar als die al open staat.
    yearFilter.addEventListener('change', function () {
        if (!list.hidden) render(false);
    });
    typeFilter.addEventListener('change', function () {
        if (!list.hidden) render(false);
    });
});
