(function () {

    // --- Reset link: prefill email en sla op in sessionStorage ---
    function attachResetLink() {
        var link = document.querySelector('a[href*="reset-password"]');
        if (!link) return;
        link.addEventListener('click', function () {
            var input = document.querySelector('input[name="username"], input[type="email"], input[autocomplete="username"]');
            if (!input || !input.value) return;
            sessionStorage.setItem('avpvh_reset_email', input.value);
            var url = new URL(link.href, window.location.href);
            url.searchParams.set('username', input.value);
            link.href = url.toString();
        });
    }

    // --- HIBP check op reset-password/step2 ---
    async function sha1hex(str) {
        const buf = await crypto.subtle.digest('SHA-1', new TextEncoder().encode(str));
        return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('').toUpperCase();
    }

    async function isPwned(password) {
        try {
            const hash   = await sha1hex(password);
            const prefix = hash.slice(0, 5);
            const suffix = hash.slice(5);
            const res    = await fetch('https://api.pwnedpasswords.com/range/' + prefix, {
                headers: { 'Add-Padding': 'true' }
            });
            const text = await res.text();
            return text.split('\n').some(function (line) {
                return line.split(':')[0].trim() === suffix;
            });
        } catch (e) {
            return false;
        }
    }

    function flagHIBP() {
        var email = sessionStorage.getItem('avpvh_reset_email') || '';
        fetch('/wp-json/avpvh/v1/hibp-flag', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email }),
        }).catch(function () {});
    }

    var hibpWarning = null;
    var hibpTimer   = null;

    function removeWarning() {
        if (hibpWarning && hibpWarning.parentNode) hibpWarning.parentNode.removeChild(hibpWarning);
        hibpWarning = null;
    }

    function showWarning(input) {
        removeWarning();
        hibpWarning = document.createElement('p');
        hibpWarning.style.cssText = 'color:#c00;font-size:0.85em;margin:4px 0 0';
        hibpWarning.textContent = '⚠ Dit wachtwoord is bekend uit een datalek. Kies een ander wachtwoord.';
        input.parentNode.insertBefore(hibpWarning, input.nextSibling);
    }

    function attachHIBP() {
        if (!window.location.pathname.includes('reset-password/step2')) return;
        document.querySelectorAll('input[type="password"]').forEach(function (input) {
            if (input._hibpAttached) return;
            input._hibpAttached = true;
            input.addEventListener('input', function () {
                clearTimeout(hibpTimer);
                removeWarning();
                var val = input.value;
                if (!val) return;
                hibpTimer = setTimeout(async function () {
                    if (await isPwned(val)) {
                        showWarning(input);
                        flagHIBP();
                    }
                }, 600);
            });
        });
    }

    var observer = new MutationObserver(function () {
        attachResetLink();
        attachHIBP();
    });
    observer.observe(document.body, { childList: true, subtree: true });
    attachResetLink();
    attachHIBP();

})();
