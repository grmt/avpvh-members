/*
 * Sortable/filterable admin table — sorting and filtering live under the
 * column headers themselves. Ported from avpvh-bookkeeping's balance.js
 * (avbk-balance-table) so both plugins' admin tables behave the same, but
 * generic: any number of tables per page, no bookkeeping-specific totals.
 *
 * Markup (see admin/members-list.php):
 *   <div data-avpvh-sortable data-i18n-*="...">
 *     .avpvh-col-toggle-btn + .avpvh-col-toggle-panel   (column show/hide)
 *     input.avpvh-table-search                           (optional, all columns)
 *     .avpvh-table-count                                 (optional, "x / y")
 *     <table class="avpvh-sortable-table" data-storage-key="...">
 *   <th data-col="key" data-type="number" data-filter="select" data-filter-sep="|">
 *   <td data-sort-value="..." data-filter-value="...">
 *   <tr data-search="...">  (extra text the search box also matches)
 *
 * Deep links: ?f_<col>=<value> pre-fills a column filter (repeat the param
 * for several values of a select column), ?s=<text> the search box.
 *
 * A <tfoot> with one cell per column is hidden/shown along with its column.
 *
 * Event: after every filter change the table dispatches
 * 'avpvh-table-filtered' (detail: {visible, total}); hidden rows have
 * row.hidden set. Use it for page-specific extras such as footer totals.
 * The first one, for the initial (deep-linked) state, is dispatched
 * asynchronously, so a listener added in a later DOMContentLoaded handler
 * still receives it.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var collator = new Intl.Collator('nl', { numeric: true, sensitivity: 'base' });
    var urlParams = new URLSearchParams(window.location.search);
    // Each click on a checklist option cycles none -> include (✓) -> exclude (−) -> none.
    var NEXT_STATE = { none: 'include', include: 'exclude', exclude: 'none' };
    var STATE_ICON = { none: '', include: '✓', exclude: '−' };

    function sortValue(row, index, type) {
        var cell = row.cells[index];
        var text = cell.dataset.sortValue === undefined ? cell.textContent.trim() : cell.dataset.sortValue;
        if (type !== 'number') return text;
        var n = Number.parseFloat(text);
        return Number.isNaN(n) ? -Infinity : n;
    }

    function compareValues(av, bv, type) {
        if (type !== 'number') return collator.compare(av, bv);
        if (av === bv) return 0;
        return av < bv ? -1 : 1;
    }

    // A cell can carry several values (e.g. a member with two flags) —
    // split into individual options instead of one combined "A, B"
    // option, and match a row if ANY of its own values matches.
    function splitValues(cell, sep) {
        var raw = cell.dataset.filterValue === undefined ? cell.textContent : cell.dataset.filterValue;
        return raw.split(sep)
            .map(function (s) { return s.trim(); })
            .filter(function (s) { return s !== ''; });
    }

    function filterText(cell) {
        return (cell.dataset.filterValue === undefined ? cell.textContent : cell.dataset.filterValue).trim().toLowerCase();
    }

    document.querySelectorAll('[data-avpvh-sortable]').forEach(function (root) {
        var table = root.querySelector('table.avpvh-sortable-table');
        if (!table?.tHead || !table.tBodies[0]) return;

        var i18n = {
            all: root.dataset.i18nAll || 'Alle',
            selected: root.dataset.i18nSelected || '%d geselecteerd',
            excluded: root.dataset.i18nExcluded || '%d uitgesloten',
            filter: root.dataset.i18nFilter || 'Filter…',
            count: root.dataset.i18nCount || '%1$d / %2$d'
        };
        var thead = table.tHead;
        var headerRow = thead.rows[0];
        var headerCells = Array.prototype.slice.call(headerRow.cells);
        var tbody = table.tBodies[0];
        var searchInput = root.querySelector('.avpvh-table-search');
        var countEl = root.querySelector('.avpvh-table-count');

        function dataRows() {
            return Array.prototype.slice.call(tbody.rows).filter(function (row) {
                return row.cells.length === headerCells.length;
            });
        }

        var rows = dataRows();
        if (!rows.length) return;

        // --- Sorting ---

        headerCells.forEach(function (th, index) {
            if (!th.dataset.col) return;
            th.classList.add('avpvh-sortable');
            th.tabIndex = 0;
            var indicator = document.createElement('span');
            indicator.className = 'avpvh-sort-indicator';
            th.appendChild(indicator);

            function sortBy() {
                var previousScroll = window.scrollY;
                var type = th.dataset.type || 'text';
                var dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
                headerCells.forEach(function (other) {
                    delete other.dataset.sortDir;
                    other.removeAttribute('aria-sort');
                    var ind = other.querySelector('.avpvh-sort-indicator');
                    if (ind) ind.textContent = '';
                });
                th.dataset.sortDir = dir;
                th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
                indicator.textContent = dir === 'asc' ? ' ▲' : ' ▼';

                dataRows().sort(function (a, b) {
                    var av = sortValue(a, index, type);
                    var bv = sortValue(b, index, type);
                    var cmp = compareValues(av, bv, type);
                    return dir === 'asc' ? cmp : -cmp;
                }).forEach(function (row) { tbody.appendChild(row); });
                window.requestAnimationFrame(function () { window.scrollTo(0, previousScroll); });
            }

            th.addEventListener('click', sortBy);
            th.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    sortBy();
                }
            });
        });

        // --- Filter row ---
        var filterRow = document.createElement('tr');
        filterRow.className = 'avpvh-filter-row';
        var filters = {};

        headerCells.forEach(function (th, index) {
            var col = th.dataset.col;
            var filterTh = document.createElement('td');
            filterRow.appendChild(filterTh);
            if (!col) return;

            var control;
            if (th.dataset.filter === 'select') {
                control = buildChecklist(th, index);
                filterTh.appendChild(control.element);
            } else {
                control = document.createElement('input');
                control.type = 'text';
                control.className = 'avpvh-filter-control';
                control.placeholder = i18n.filter;
                control.setAttribute('aria-label', i18n.filter + ' ' + th.textContent.trim());
                control.addEventListener('input', applyFiltersPreservingScroll);
                filterTh.appendChild(control);
            }
            filters[index] = control;
        });
        thead.appendChild(filterRow);

        function buildChecklist(th, index) {
            var sep = th.dataset.filterSep || ',';
            var wrapper = document.createElement('div');
            wrapper.className = 'avpvh-checklist-filter';
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'avpvh-checklist-toggle';
            toggle.textContent = i18n.all;
            toggle.setAttribute('aria-label', th.textContent.trim());
            var menu = document.createElement('div');
            menu.className = 'avpvh-checklist-menu';
            menu.hidden = true;
            // value (lowercased) -> 'include' | 'exclude'; absent = no filter on it.
            var states = {};
            var values = [];
            dataRows().forEach(function (row) {
                splitValues(row.cells[index], sep).forEach(function (v) {
                    if (!values.includes(v)) values.push(v);
                });
            });
            values.sort(collator.compare);

            function updateToggleLabel() {
                var included = 0, excluded = 0;
                Object.keys(states).forEach(function (v) {
                    if (states[v] === 'include') included++;
                    else excluded++;
                });
                var parts = [];
                if (included) parts.push(i18n.selected.replace('%d', included));
                if (excluded) parts.push(i18n.excluded.replace('%d', excluded));
                toggle.textContent = parts.length ? parts.join(', ') : i18n.all;
            }

            var optionsByKey = {};
            values.forEach(function (v) {
                var key = v.toLowerCase();
                var option = document.createElement('button');
                option.type = 'button';
                option.className = 'avpvh-tristate-option';
                option.dataset.state = 'none';
                var icon = document.createElement('span');
                icon.className = 'avpvh-tristate-icon';
                option.appendChild(icon);
                option.appendChild(document.createTextNode(' ' + v));
                optionsByKey[key] = option;
                option.addEventListener('click', function () {
                    var next = NEXT_STATE[option.dataset.state];
                    option.dataset.state = next;
                    icon.textContent = STATE_ICON[next];
                    if (next === 'none') delete states[key];
                    else states[key] = next;
                    updateToggleLabel();
                    applyFiltersPreservingScroll();
                });
                menu.appendChild(option);
            });

            // The menu lives on <body> with fixed coordinates rather than
            // inside the table: .avpvh-table-wrap needs overflow-x:auto for
            // a wide table, which forces overflow-y to clip too, and would
            // cut the menu off whenever a filter leaves only a few rows.
            document.body.appendChild(menu);
            menu.style.position = 'fixed';

            function positionMenu() {
                var rect = toggle.getBoundingClientRect();
                menu.style.top = rect.bottom + 'px';
                menu.style.left = rect.left + 'px';
                menu.style.minWidth = rect.width + 'px';
            }

            toggle.addEventListener('click', function (event) {
                event.stopPropagation();
                if (menu.hidden) positionMenu();
                menu.hidden = !menu.hidden;
            });
            document.addEventListener('click', function (event) {
                if (!wrapper.contains(event.target) && !menu.contains(event.target)) {
                    menu.hidden = true;
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') menu.hidden = true;
            });
            window.addEventListener('scroll', function () {
                if (!menu.hidden) positionMenu();
            }, true);
            window.addEventListener('resize', function () {
                if (!menu.hidden) positionMenu();
            });
            wrapper.appendChild(toggle);

            return {
                element: wrapper,
                options: optionsByKey,
                // Not named "matches": every <input> already has the native
                // Element.matches(), which would route text filters here too.
                // Hidden if any value is excluded; when at least one value is
                // included anywhere, the row must match one of those.
                filterMatches: function (cell) {
                    var hasInclude = Object.keys(states).some(function (k) { return states[k] === 'include'; });
                    var matchesInclude = !hasInclude;
                    for (var value of splitValues(cell, sep)) {
                        var state = states[value.toLowerCase()];
                        if (state === 'exclude') return false;
                        if (state === 'include') matchesInclude = true;
                    }
                    return matchesInclude;
                }
            };
        }

        function applyFilters() {
            var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
            var visibleCount = 0;
            dataRows().forEach(function (row) {
                var visible = !query
                    || (row.textContent + ' ' + (row.dataset.search || '')).toLowerCase().includes(query);
                Object.keys(filters).forEach(function (idxStr) {
                    if (!visible) return;
                    var control = filters[idxStr];
                    var cell = row.cells[Number.parseInt(idxStr, 10)];
                    if (control.filterMatches) {
                        visible = control.filterMatches(cell);
                    } else {
                        var filterVal = control.value.trim().toLowerCase();
                        if (filterVal && !filterText(cell).includes(filterVal)) visible = false;
                    }
                });
                row.hidden = !visible;
                if (visible) visibleCount++;
            });
            if (countEl) {
                countEl.textContent = i18n.count.replace('%1$d', visibleCount).replace('%2$d', rows.length);
            }
            return visibleCount;
        }

        function notifyFiltered(visibleCount) {
            table.dispatchEvent(new CustomEvent('avpvh-table-filtered', {
                bubbles: true,
                detail: { visible: visibleCount, total: rows.length }
            }));
        }

        function applyFiltersPreservingScroll() {
            var previousScroll = window.scrollY;
            notifyFiltered(applyFilters());
            window.requestAnimationFrame(function () { window.scrollTo(0, previousScroll); });
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFiltersPreservingScroll);
        }

        // --- Deep-linked filters, e.g. ?f_status=Bezoeker&f_last_name=jansen ---
        headerCells.forEach(function (th, index) {
            var col = th.dataset.col;
            var control = col ? filters[index] : null;
            if (!control) return;
            urlParams.getAll('f_' + col).forEach(function (wanted) {
                if (control.options) {
                    var option = control.options[wanted.toLowerCase()];
                    if (option?.dataset.state === 'none') option.click();
                } else {
                    control.value = wanted;
                }
            });
        });
        if (searchInput && urlParams.get('s')) {
            searchInput.value = urlParams.get('s');
        }
        var initialVisible = applyFilters();
        window.setTimeout(function () { notifyFiltered(initialVisible); }, 0);

        // --- Column show/hide, remembered per browser ---
        var storageKey = table.dataset.storageKey || 'avpvh_hidden_cols';
        var panel = root.querySelector('.avpvh-col-toggle-panel');
        var toggleBtn = root.querySelector('.avpvh-col-toggle-btn');
        if (!panel || !toggleBtn) return;

        function storedHidden() {
            try {
                var raw = window.localStorage.getItem(storageKey);
                return raw ? JSON.parse(raw) : null;
            } catch {
                // Storage blocked (private window, cleared site data) or a
                // corrupt value: fall back to the default columns.
                return null;
            }
        }

        function saveHidden(list) {
            try {
                window.localStorage.setItem(storageKey, JSON.stringify(list));
            } catch { /* private window etc. — just not remembered */ }
        }

        function setColumnVisibility(index, visible) {
            // Explicit 'table-cell' (not '') so this inline style wins over
            // the .avpvh-col-optional{display:none} mobile rule when a
            // column is switched back on by hand. A <tfoot> (e.g. totals)
            // follows along when it has one cell per column, no colspan.
            var footRows = table.tFoot ? Array.prototype.slice.call(table.tFoot.rows) : [];
            [headerRow, filterRow].concat(dataRows(), footRows).forEach(function (row) {
                if (row.cells.length !== headerCells.length) return;
                row.cells[index].style.display = visible ? 'table-cell' : 'none';
            });
        }

        var hidden = storedHidden();
        if (hidden === null) {
            // Nothing chosen yet: on a narrow screen start without the optional columns.
            var narrow = window.matchMedia?.('(max-width: 782px)').matches;
            hidden = narrow
                ? headerCells.filter(function (th) { return th.classList.contains('avpvh-col-optional'); }).map(function (th) { return th.dataset.col; })
                : [];
        }

        headerCells.forEach(function (th, index) {
            var col = th.dataset.col;
            if (!col) return;
            var isHidden = hidden.includes(col);
            if (isHidden) setColumnVisibility(index, false);

            var label = document.createElement('label');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = !isHidden;
            cb.addEventListener('change', function () {
                setColumnVisibility(index, cb.checked);
                var current = (storedHidden() || hidden).filter(function (c) { return c !== col; });
                if (!cb.checked) current.push(col);
                hidden = current;
                saveHidden(current);
            });
            label.appendChild(cb);
            label.appendChild(document.createTextNode(' ' + th.textContent.replace(/[▲▼]/g, '').trim()));
            panel.appendChild(label);
        });

        toggleBtn.setAttribute('aria-expanded', 'false');
        toggleBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            panel.hidden = !panel.hidden;
            toggleBtn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        });
        document.addEventListener('click', function (event) {
            if (!panel.hidden && !panel.contains(event.target)) {
                panel.hidden = true;
                toggleBtn.setAttribute('aria-expanded', 'false');
            }
        });
    });
});
