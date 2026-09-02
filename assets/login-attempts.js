(function () {
    'use strict';

    var filters = document.querySelector('[data-avpvh-auto-filter]');
    if (!filters) {
        return;
    }

    var form = filters.closest('form');
    var storageKey = 'avpvh-login-attempts-open-filter';
    var submitTimer;
    var dateFrom = document.getElementById('filter-date-from');
    var dateTo = document.getElementById('filter-date-to');
    var monthNumbers = {
        jan: '01', feb: '02', mrt: '03', apr: '04', mei: '05', jun: '06',
        jul: '07', aug: '08', sep: '09', okt: '10', nov: '11', dec: '12'
    };

    function parseDate(value) {
        var match = value.trim().toLowerCase().match(/^(\d{2})-([a-z]{3})-(\d{4})$/);
        if (!match || !monthNumbers[match[2]]) {
            return '';
        }

        var iso = match[3] + '-' + monthNumbers[match[2]] + '-' + match[1];
        var date = new Date(iso + 'T00:00:00Z');
        return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === iso ? iso : '';
    }

    function localDate(value) {
        var parts = value.split('-');
        return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    }

    function nextDate(value) {
        var date = new Date(value + 'T00:00:00Z');
        date.setUTCDate(date.getUTCDate() + 1);
        return date.toISOString().slice(0, 10);
    }

    function datesAreValid() {
        var from = parseDate(dateFrom.value);
        var to = parseDate(dateTo.value);

        dateFrom.setCustomValidity('');
        dateTo.setCustomValidity('');

        if (dateFrom.value && !from) {
            dateFrom.setCustomValidity('Gebruik een geldige datum als dd-mmm-yyyy.');
        } else if (from && from > dateFrom.dataset.maximumDate) {
            dateFrom.setCustomValidity('Van moet vóór vandaag liggen.');
        }

        if (dateTo.value && !to) {
            dateTo.setCustomValidity('Gebruik een geldige datum als dd-mmm-yyyy.');
        } else if (to && to > dateTo.dataset.maximumDate) {
            dateTo.setCustomValidity('Tot kan niet later zijn dan vandaag.');
        } else if (from && to && to <= from) {
            dateTo.setCustomValidity('Tot moet later zijn dan van.');
        }

        if (window.jQuery && window.jQuery.fn.datepicker) {
            window.jQuery(dateTo).datepicker('option', 'minDate', from ? localDate(nextDate(from)) : null);
        }

        return form.checkValidity();
    }

    if (window.jQuery && window.jQuery.fn.datepicker) {
        var datepickerOptions = {
            dateFormat: 'dd-M-yy',
            firstDay: 1,
            dayNamesMin: ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'],
            monthNames: ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
            monthNamesShort: ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'],
            nextText: 'Volgende',
            prevText: 'Vorige',
            onSelect: function () {
                this.dispatchEvent(new Event('change', {bubbles: true}));
            }
        };
        window.jQuery(dateFrom).datepicker(Object.assign({}, datepickerOptions, {maxDate: -1}));
        window.jQuery(dateTo).datepicker(Object.assign({}, datepickerOptions, {maxDate: 0}));
    }

    datesAreValid();

    try {
        var openFilter = window.sessionStorage.getItem(storageKey);
        filters.querySelectorAll('[data-filter-key]').forEach(function (dropdown) {
            if (dropdown.getAttribute('data-filter-key') === openFilter) {
                dropdown.open = true;
            }
        });
        window.sessionStorage.removeItem(storageKey);
    } catch (error) {
        // Filtering still works when browser storage is unavailable.
    }

    filters.addEventListener('change', function (event) {
        if (!event.target.matches('[data-auto-submit]')) {
            return;
        }

        if (!datesAreValid()) {
            form.reportValidity();
            return;
        }

        var dropdown = event.target.closest('[data-filter-key]');
        if (dropdown) {
            try {
                window.sessionStorage.setItem(storageKey, dropdown.getAttribute('data-filter-key'));
            } catch (error) {
                // Keeping the dropdown open is optional; submitting is not.
            }
        }

        window.clearTimeout(submitTimer);
        submitTimer = window.setTimeout(function () {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }
            form.submit();
        }, 350);
    });
}());
