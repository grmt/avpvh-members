document.addEventListener('DOMContentLoaded', function () {
    const zoek = document.getElementById('avpvh-ledenlijst-zoek');
    if (!zoek) return;

    zoek.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.avpvh-ledenlijst-tabel tbody tr').forEach(function (row) {
            row.classList.toggle('avpvh-hidden', !row.textContent.toLowerCase().includes(q));
        });
    });
});
