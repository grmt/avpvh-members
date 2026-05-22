document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('avpvh-login-config');
    if (!el) return;
    var cfg = JSON.parse(el.textContent);
    if (!cfg) return;

    var options = document.getElementById('avpvh-login-options');
    if (!options) return;

    if (cfg.hasGoogle) {
        options.appendChild(makeLink('Inloggen met Google', cfg.loginUrls.google, 'avpvh-login-google'));
    }

    if (cfg.hasMicrosoft) {
        options.appendChild(makeLink('Inloggen met Microsoft', cfg.loginUrls.microsoft, 'avpvh-login-microsoft'));
    }

    options.appendChild(makeLink('Inloggen met wachtwoord', cfg.autheliaUrl, 'avpvh-login-password'));

    function makeLink(label, url, cls) {
        var a = document.createElement('a');
        a.href = url;
        a.textContent = label;
        a.className = cls;
        return a;
    }
});
