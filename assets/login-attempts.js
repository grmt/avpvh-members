(function () {
    'use strict';

    var filters = document.querySelector('[data-avpvh-auto-filter]');
    if (!filters) {
        return;
    }

    var form = filters.closest('form');
    var storageKey = 'avpvh-login-attempts-open-filter';
    var submitTimer;

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
