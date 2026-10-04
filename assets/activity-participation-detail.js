document.addEventListener('DOMContentLoaded', function () {
    var display = document.getElementById('nights-computed');
    var dayInputs = document.querySelectorAll('input[name^="day["]');
    if (!display || !dayInputs.length) return;
    function recompute() {
        var count = 0;
        dayInputs.forEach(function (input) {
            if (input.value.trim() === 'n') count++;
        });
        display.textContent = count;
    }
    dayInputs.forEach(function (input) { input.addEventListener('input', recompute); });
});
