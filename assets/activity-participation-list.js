document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const config = document.getElementById('avpvh-activity-picker-config');
    const form = document.getElementById('avpvh-activity-picker-form');
    if (!config || !form) return;

    const activities = JSON.parse(config.textContent).activities;
    const year = document.getElementById('avpvh-activity-year-filter');
    const type = document.getElementById('avpvh-activity-type-filter');
    const search = document.getElementById('avpvh-activity-name-filter');
    const select = document.getElementById('avpvh-activity-select');
    const view = form.querySelector('button[type="submit"]');
    if (!year || !type || !search || !select) return;

    search.closest('label').hidden = false;

    function render() {
        const selected = select.value;
        const term = search.value.trim().toLocaleLowerCase('nl');
        const matches = activities.filter((activity) => {
            return (year.value === '0' || year.value === '' || String(activity.year) === year.value)
                && (type.value === '' || (type.value === '__none__' ? activity.type === '' : activity.type === type.value))
                && (!term || activity.label.toLocaleLowerCase('nl').includes(term));
        });

        select.replaceChildren(new Option(matches.length ? select.dataset.placeholder : select.dataset.empty, '0'));
        matches.forEach((activity) => {
            select.add(new Option(activity.label, String(activity.id)));
        });
        select.value = matches.some((activity) => String(activity.id) === selected) ? selected : '0';
        if (view) view.disabled = select.value === '0';
        return matches;
    }

    function openSelection() {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function changeFilters() {
        search.value = '';
        const matches = render();
        if (matches.length === 1) select.value = String(matches[0].id);
        openSelection();
    }

    year.addEventListener('change', changeFilters);
    type.addEventListener('change', changeFilters);
    search.addEventListener('input', render);
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (select.options.length === 2) select.value = select.options[1].value;
            if (select.value !== '0') openSelection();
        }
    });
    select.addEventListener('change', () => {
        if (view) view.disabled = select.value === '0';
        openSelection();
    });
    render();
});
