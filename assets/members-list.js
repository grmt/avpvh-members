document.addEventListener('DOMContentLoaded', function () {
    var openPanels = function () {
        return document.querySelectorAll('.avpvh-multiselect__panel.is-open');
    };

    document.querySelectorAll('.avpvh-multiselect').forEach(function (ms) {
        var toggle = ms.querySelector('.avpvh-multiselect__toggle');
        var panel = ms.querySelector('.avpvh-multiselect__panel');
        if (!toggle || !panel) return;
        var defaultLabel = ms.getAttribute('data-default-label') || '';

        var updateLabel = function () {
            var checked = panel.querySelectorAll('input[type=checkbox]:checked');
            if (checked.length === 0) {
                toggle.textContent = defaultLabel;
            } else if (checked.length === 1) {
                toggle.textContent = checked[0].parentElement ? checked[0].parentElement.textContent.trim() : defaultLabel;
            } else {
                toggle.textContent = checked.length + ' geselecteerd';
            }
        };

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var wasOpen = panel.classList.contains('is-open');
            openPanels().forEach(function (p) { p.classList.remove('is-open'); });
            if (!wasOpen) {
                panel.classList.add('is-open');
            }
        });

        panel.addEventListener('change', updateLabel);
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
        updateLabel();
    });

    document.addEventListener('click', function () {
        openPanels().forEach(function (p) { p.classList.remove('is-open'); });
    });
});
