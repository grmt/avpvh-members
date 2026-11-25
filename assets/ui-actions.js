(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var message = event.target.getAttribute('data-avpvh-confirm');
        if (message && !window.confirm(message)) event.preventDefault();
    });
    document.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-avpvh-confirm], a[data-avpvh-confirm]');
        if (button && !window.confirm(button.getAttribute('data-avpvh-confirm'))) event.preventDefault();
    });
    document.addEventListener('change', function (event) {
        if (event.target.matches('select[data-avpvh-navigate]')) {
            var target = new URL(event.target.value, window.location.href);
            if (target.origin === window.location.origin) window.location.assign(target.href);
        }
    });

    document.querySelectorAll('.avpvh-relationship-add').forEach(function (form) {
        var select = form.querySelector('[name="related_member_id"]');
        var input = form.querySelector('#rel_related_member_filter');
        var data = form.querySelector('#rel_related_member_data');
        if (!select || !input || !data) return;
        var members = JSON.parse(data.textContent);
        input.addEventListener('input', function () {
            var term = input.value.trim().toLowerCase();
            var selected = select.value;
            select.querySelectorAll('option[data-avpvh-plain]').forEach(function (option) { option.remove(); });
            members.forEach(function (member) {
                if (term && member.first.toLowerCase().indexOf(term) !== 0 && member.last.toLowerCase().indexOf(term) !== 0) return;
                var option = document.createElement('option');
                option.value = member.id;
                option.textContent = member.label;
                option.setAttribute('data-avpvh-plain', '1');
                select.appendChild(option);
            });
            select.value = selected;
        });
    });
}());
