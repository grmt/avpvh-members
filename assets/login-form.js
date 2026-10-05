document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('avpvh-login-config');
    if (!el) return;
    var cfg = JSON.parse(el.textContent);
    if (!cfg) return;

    var options = document.getElementById('avpvh-login-options');
    if (!options) return;

    var labels = cfg.labels || {};

    if (cfg.hasGoogle) {
        options.appendChild(makeLink(labels.google || 'Inloggen met Google', cfg.loginUrls.google, 'avpvh-login-google'));
    }

    if (cfg.hasMicrosoft) {
        options.appendChild(makeLink(labels.microsoft || 'Inloggen met Microsoft', cfg.loginUrls.microsoft, 'avpvh-login-microsoft'));
    }

    options.appendChild(makeLink(labels.password || 'Inloggen met wachtwoord', cfg.autheliaUrl, 'avpvh-login-password'));

    function makeLink(label, url, cls) {
        var a = document.createElement('a');
        a.href = url;
        a.textContent = label;
        a.className = cls;
        return a;
    }
});
