(function () {
    'use strict';

    const STORAGE_KEY = 'avpvh-activity-visible-columns-v1';
    const collator = new Intl.Collator('nl', { numeric: true, sensitivity: 'base' });

    document.querySelectorAll('[data-avpvh-activity-list]').forEach((root) => {
        const tableArea = root.querySelector('.avpvh-activity-list__table');
        const table = tableArea && tableArea.querySelector('.wp-list-table');
        const headerRow = table && table.querySelector('thead tr');
        const body = table && table.tBodies[0];
        if (!table || !headerRow || !body) return;

        const rows = Array.from(body.rows).filter((row) => !row.classList.contains('no-items'));
        if (!rows.length) {
            root.querySelector('.avpvh-activity-list__tools').hidden = true;
            return;
        }
        root.querySelector('.avpvh-activity-list__tools').hidden = false;

        const columns = Array.from(headerRow.cells).map((cell, index) => {
            const className = Array.from(cell.classList).find((name) => name.indexOf('column-') === 0);
            return {
                key: className ? className.slice(7) : '',
                label: cell.textContent.trim(),
                index: index,
                cell: cell
            };
        });
        const searchableColumns = columns.filter((column) => column.key && column.key !== 'actions');
        const filters = new Map();
        const search = root.querySelector('#avpvh-activity-search');
        const reset = root.querySelector('.avpvh-activity-list__reset');
        const count = root.querySelector('.avpvh-activity-list__count');
        const noResults = document.createElement('tr');
        const noResultsCell = document.createElement('td');

        noResults.className = 'avpvh-activity-no-results';
        noResults.hidden = true;
        noResultsCell.colSpan = columns.length;
        noResultsCell.textContent = tableArea.dataset.noResults;
        noResults.appendChild(noResultsCell);
        body.appendChild(noResults);

        function normalized(value) {
            return value.trim().toLocaleLowerCase('nl');
        }

        function cellValue(row, column) {
            const cell = row.cells[column.index];
            if (cell && column.key === 'name') {
                const link = cell.querySelector('a');
                if (link) return link.textContent.trim();
            }
            return cell ? cell.textContent.trim() : '';
        }

        function updateCount(visible) {
            count.textContent = visible + ' / ' + rows.length;
        }

        function applyFilters() {
            const query = normalized(search.value);
            let visible = 0;

            rows.forEach((row) => {
                const globalMatch = !query || searchableColumns.some((column) => normalized(cellValue(row, column)).includes(query));
                const columnMatch = searchableColumns.every((column) => {
                    const control = filters.get(column.key);
                    if (!control || !control.value) return true;
                    const value = normalized(cellValue(row, column));
                    const filter = normalized(control.value);
                    return control.tagName === 'SELECT' ? value === filter : value.includes(filter);
                });
                row.hidden = !(globalMatch && columnMatch);
                if (!row.hidden) visible += 1;
            });

            noResults.hidden = visible !== 0;
            updateCount(visible);
        }

        const filterRow = document.createElement('tr');
        filterRow.className = 'avpvh-activity-filter-row';
        columns.forEach((column) => {
            const cell = document.createElement('th');
            cell.className = column.cell.className;
            if (column.key && column.key !== 'actions') {
                const exact = ['nights', 'days', 'nawacht'].includes(column.key);
                const input = document.createElement(exact ? 'select' : 'input');
                if (exact) {
                    const all = document.createElement('option');
                    all.value = '';
                    all.textContent = tableArea.dataset.allLabel;
                    input.appendChild(all);
                    const values = Array.from(new Set(rows.map((row) => cellValue(row, column))));
                    values.sort(collator.compare).forEach((value) => {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = value;
                        input.appendChild(option);
                    });
                } else {
                    input.type = 'search';
                    input.placeholder = tableArea.dataset.filterLabel;
                }
                input.setAttribute('aria-label', tableArea.dataset.filterLabel + ' ' + column.label);
                input.addEventListener(exact ? 'change' : 'input', applyFilters);
                filters.set(column.key, input);
                cell.appendChild(input);
            }
            filterRow.appendChild(cell);
        });
        headerRow.insertAdjacentElement('afterend', filterRow);

        let sortKey = '';
        let sortDirection = 'ascending';
        searchableColumns.forEach((column) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'avpvh-sort-button';
            button.textContent = column.label;
            column.cell.textContent = '';
            column.cell.appendChild(button);

            button.addEventListener('click', () => {
                sortDirection = sortKey === column.key && sortDirection === 'ascending' ? 'descending' : 'ascending';
                sortKey = column.key;
                columns.forEach((other) => other.cell.removeAttribute('aria-sort'));
                column.cell.setAttribute('aria-sort', sortDirection);

                rows.sort((left, right) => {
                    const result = collator.compare(cellValue(left, column), cellValue(right, column));
                    return sortDirection === 'ascending' ? result : -result;
                });
                rows.forEach((row) => body.insertBefore(row, noResults));
            });
        });

        search.addEventListener('input', applyFilters);
        reset.addEventListener('click', () => {
            search.value = '';
            filters.forEach((control) => { control.value = ''; });
            applyFilters();
            search.focus();
        });

        const columnToggle = root.querySelector('.avpvh-activity-columns__toggle');
        const columnPanel = root.querySelector('.avpvh-activity-columns__panel');
        const columnChecks = Array.from(columnPanel.querySelectorAll('input[type="checkbox"]'));

        function setColumnVisibility(key, visible) {
            table.querySelectorAll('.column-' + key).forEach((cell) => {
                cell.classList.toggle('avpvh-column-hidden', !visible);
            });
            if (!visible && filters.has(key)) filters.get(key).value = '';
            noResultsCell.colSpan = columns.filter((column) => !column.cell.classList.contains('avpvh-column-hidden')).length;
        }

        function saveColumnVisibility() {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(columnChecks.filter((check) => check.checked).map((check) => check.value)));
            } catch (error) {
                // The table still works when browser storage is unavailable.
            }
        }

        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
            if (Array.isArray(saved)) {
                columnChecks.forEach((check) => { check.checked = saved.includes(check.value); });
            }
        } catch (error) {
            // Ignore malformed or unavailable browser storage.
        }

        columnChecks.forEach((check) => {
            setColumnVisibility(check.value, check.checked);
            check.addEventListener('change', () => {
                setColumnVisibility(check.value, check.checked);
                saveColumnVisibility();
                applyFilters();
            });
        });

        function closeColumnPanel() {
            columnPanel.hidden = true;
            columnToggle.setAttribute('aria-expanded', 'false');
        }

        columnToggle.addEventListener('click', () => {
            const open = columnPanel.hidden;
            columnPanel.hidden = !open;
            columnToggle.setAttribute('aria-expanded', String(open));
        });
        document.addEventListener('click', (event) => {
            if (!event.target.closest('.avpvh-activity-columns')) closeColumnPanel();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !columnPanel.hidden) {
                closeColumnPanel();
                columnToggle.focus();
            }
        });

        applyFilters();
    });
}());
