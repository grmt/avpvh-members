document.addEventListener('DOMContentLoaded', function () {
    const tabel = document.getElementById('avpvh-ledenlijst-tabel');
    if (!tabel) return;

    const zoek = document.getElementById('avpvh-ledenlijst-zoek');
    const plaatsBoxes = Array.prototype.slice.call(document.querySelectorAll('[data-dropdown="plaats"] input[type=checkbox]'));
    const groepBoxes = Array.prototype.slice.call(document.querySelectorAll('[data-dropdown="groep"] input[type=checkbox]'));
    const tbody = tabel.querySelector('tbody');
    const rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));

    function checkedValues(boxes) {
        return boxes.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
    }

    // --- search + city/group filters, all AND'd together; multiple checked
    // values within the same filter are OR'd (e.g. Amsterdam OR Utrecht) ---
    function applyFilters() {
        const q = zoek ? zoek.value.toLowerCase() : '';
        const steden = checkedValues(plaatsBoxes);
        const groepen = checkedValues(groepBoxes);

        rows.forEach(function (row) {
            const matchesSearch = !q || row.textContent.toLowerCase().includes(q);
            const matchesCity = !steden.length || steden.includes(row.dataset.city);
            const rowGroups = (row.dataset.groups || '').split(',');
            const matchesGroup = !groepen.length || groepen.some(function (g) { return rowGroups.includes(g); });
            row.classList.toggle('avpvh-hidden', !(matchesSearch && matchesCity && matchesGroup));
        });
    }
    if (zoek) zoek.addEventListener('input', applyFilters);

    // --- generic dropdown popovers: Plaats, Groep and Kolommen all use the
    // same open/close-on-outside-click behavior, mutually exclusive ---
    document.querySelectorAll('.avpvh-ledenlijst-dropdown').forEach(function (dropdown) {
        const toggle = dropdown.querySelector('.avpvh-ledenlijst-dropdown-toggle');
        const menu = dropdown.querySelector('.avpvh-ledenlijst-dropdown-menu');
        if (!toggle || !menu) return;

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const wasHidden = menu.hidden;
            document.querySelectorAll('.avpvh-ledenlijst-dropdown-menu').forEach(function (m) { m.hidden = true; });
            document.querySelectorAll('.avpvh-ledenlijst-dropdown-toggle').forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
            menu.hidden = !wasHidden;
            toggle.setAttribute('aria-expanded', wasHidden ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.avpvh-ledenlijst-dropdown').forEach(function (dropdown) {
            if (dropdown.contains(e.target)) return;
            const toggle = dropdown.querySelector('.avpvh-ledenlijst-dropdown-toggle');
            const menu = dropdown.querySelector('.avpvh-ledenlijst-dropdown-menu');
            if (menu && !menu.hidden) {
                menu.hidden = true;
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            }
        });
    });

    // --- show a selection count on the Plaats/Groep toggle buttons ---
    function updateDropdownLabel(selector, baseLabel, boxes) {
        const toggle = document.querySelector(selector + ' .avpvh-ledenlijst-dropdown-toggle');
        if (!toggle) return;
        const count = checkedValues(boxes).length;
        toggle.textContent = baseLabel + (count ? ' (' + count + ')' : '') + ' ▾';
    }
    plaatsBoxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            updateDropdownLabel('[data-dropdown="plaats"]', 'Plaats', plaatsBoxes);
            applyFilters();
        });
    });
    groepBoxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            updateDropdownLabel('[data-dropdown="groep"]', 'Groep', groepBoxes);
            applyFilters();
        });
    });

    // --- column visibility, persisted per browser ---
    const STORAGE_KEY = 'avpvh_ledenlijst_kolommen';
    const kolomCheckboxes = Array.prototype.slice.call(document.querySelectorAll('[data-dropdown="kolommen"] input[type=checkbox]'));

    function setColumnVisibility(col, visible) {
        document.querySelectorAll('.col-' + col).forEach(function (cell) {
            cell.classList.toggle('avpvh-col-hidden', !visible);
        });
    }

    let saved = {};
    try {
        saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    } catch (e) { /* ignore malformed storage */ }

    kolomCheckboxes.forEach(function (cb) {
        const col = cb.dataset.col;
        if (Object.prototype.hasOwnProperty.call(saved, col)) {
            cb.checked = saved[col];
            setColumnVisibility(col, saved[col]);
        }
        cb.addEventListener('change', function () {
            setColumnVisibility(col, cb.checked);
            saved[col] = cb.checked;
            localStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
        });
    });

    // --- sorting: click a column header (works the same on touch) ---
    let sortKey = null;
    let sortAsc = true;

    function sortByKey(key, asc) {
        const th = tabel.querySelector('th.is-sortable[data-sort-key="' + key + '"]');
        if (!th) return;
        sortKey = key;
        sortAsc = asc;

        tabel.querySelectorAll('th.is-sortable').forEach(function (h) {
            h.classList.remove('avpvh-sort-asc', 'avpvh-sort-desc');
        });
        th.classList.add(asc ? 'avpvh-sort-asc' : 'avpvh-sort-desc');

        const colIndex = Array.prototype.indexOf.call(th.parentElement.children, th);
        rows.sort(function (a, b) {
            const av = a.children[colIndex].dataset.sortValue || '';
            const bv = b.children[colIndex].dataset.sortValue || '';
            if (av < bv) return asc ? -1 : 1;
            if (av > bv) return asc ? 1 : -1;
            return 0;
        });
        rows.forEach(function (row) { tbody.appendChild(row); });
    }

    tabel.querySelectorAll('th.is-sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            const key = th.dataset.sortKey;
            sortByKey(key, sortKey === key ? !sortAsc : true);
        });
    });

    // --- sticky search box (mobile portrait only — see the media query in
    // ledenlijst.css, which also hides the rest of the controls bar there
    // to leave more room for the list itself). Offset is measured, not
    // hardcoded, because what sits above this page varies: the theme's own
    // sticky nav always, plus WordPress's own wp-admin toolbar on top of
    // that for logged-in editors/admins. Reading the theme header's current
    // bottom edge captures both automatically.
    function updateStickyOffsets() {
        const header = document.querySelector('header.wp-block-template-part, header');
        const topOffset = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0;
        document.documentElement.style.setProperty('--avpvh-sticky-top', topOffset + 'px');
    }
    updateStickyOffsets();
    window.addEventListener('resize', updateStickyOffsets);
    // Sticky nav / admin bar can reflow slightly after webfonts/images load.
    window.addEventListener('load', updateStickyOffsets);

    // --- full-bleed breakout width, measured instead of assumed ---
    // 100vw in CSS doesn't reliably equal the actually-usable width on every
    // mobile browser (confirmed broken on Pixel Chrome and iPad Safari —
    // both showed a much bigger left margin than right). clientWidth is the
    // real visible width free of that discrepancy.
    function updateViewportWidth() {
        document.documentElement.style.setProperty('--avpvh-viewport-width', document.documentElement.clientWidth + 'px');
    }
    updateViewportWidth();
    window.addEventListener('resize', updateViewportWidth);

    // --- vCard download, built entirely from what's already on screen ---
    document.querySelectorAll('.avpvh-vcard-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = btn.dataset;
            const lines = ['BEGIN:VCARD', 'VERSION:3.0', 'FN:' + d.vcardName, 'N:' + d.vcardName];
            if (d.vcardEmail) lines.push('EMAIL:' + d.vcardEmail);
            if (d.vcardCell) lines.push('TEL;TYPE=CELL:' + d.vcardCell);
            if (d.vcardTel) lines.push('TEL;TYPE=HOME:' + d.vcardTel);
            if (d.vcardAdr) lines.push('ADR;TYPE=HOME:;;' + d.vcardAdr);
            lines.push('END:VCARD');

            const blob = new Blob([lines.join('\r\n')], { type: 'text/vcard' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = d.vcardName.replace(/[^\w\-() ]/g, '') + '.vcf';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });
    });
});
