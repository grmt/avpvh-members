(function () {
    'use strict';

    var endpoint = new URL('/wp-admin/admin-ajax.php', window.location.origin);
    endpoint.searchParams.set('action', 'avpvh_media_session_status');

    var loginUrl = new URL('/avpvh-login/', window.location.origin);
    loginUrl.searchParams.set('login_error', 'session_expired');

    var requestInFlight = false;
    var expiresAt = 0;
    var expiryTimer = null;

    function closeProtectedPage() {
        // Remove already-rendered member content before the redirect completes.
        document.documentElement.replaceChildren();
        window.location.replace(loginUrl.toString());
    }

    function armExpiryTimer(expires) {
        expiresAt = expires * 1000;
        window.clearTimeout(expiryTimer);

        var delay = expiresAt - Date.now();
        if (delay <= 0) {
            closeProtectedPage();
            return;
        }

        // Browsers may suspend this timer in a background tab. The wake-up
        // event handlers below compare the absolute expiry as a backstop.
        expiryTimer = window.setTimeout(closeProtectedPage, delay);
    }

    function checkSession() {
        if (requestInFlight) return;
        if (expiresAt && Date.now() >= expiresAt) {
            closeProtectedPage();
            return;
        }

        requestInFlight = true;
        fetch(endpoint.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            if (response.status === 401 || response.status === 403) {
                closeProtectedPage();
                return null;
            }
            if (!response.ok) throw new Error('Session check failed');
            return response.json();
        }).then(function (body) {
            if (!body) return;
            if (!body.success || !body.data || !Number.isFinite(Number(body.data.expires))) {
                closeProtectedPage();
                return;
            }
            armExpiryTimer(Number(body.data.expires));
        }).catch(function () {
            // A transient network error is not proof that the session ended.
            // The next focus, pageshow, or interval will retry.
        }).finally(function () {
            requestInFlight = false;
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') checkSession();
    });
    window.addEventListener('focus', checkSession);
    window.addEventListener('pageshow', checkSession);

    checkSession();
    window.setInterval(function () {
        if (document.visibilityState === 'visible') checkSession();
    }, 60000);
}());
