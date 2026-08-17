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

    if (cfg.emailLoginUrl) {
        options.appendChild(makeEmailLoginForm(cfg.emailLoginUrl));
    }

    function makeLink(label, url, cls) {
        var a = document.createElement('a');
        a.href = url;
        a.textContent = label;
        a.className = cls;
        return a;
    }

    function makeEmailLoginForm(url) {
        var wrap = document.createElement('form');
        wrap.className = 'avpvh-login-email';

        var input = document.createElement('input');
        input.type = 'email';
        input.name = 'email';
        input.placeholder = 'jouw@e-mailadres.nl';
        input.required = true;

        var button = document.createElement('button');
        button.type = 'submit';
        button.textContent = 'Inloggen met e-maillink';

        var status = document.createElement('p');
        status.className = 'avpvh-login-email-status';

        wrap.appendChild(input);
        wrap.appendChild(button);
        wrap.appendChild(status);

        wrap.addEventListener('submit', function (e) {
            e.preventDefault();
            button.disabled = true;
            status.textContent = 'Bezig...';

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: input.value }),
            }).then(function () {
                status.textContent = 'Als dit adres bekend is, ontvang je zo een inloglink per e-mail.';
                input.value = '';
            }).catch(function () {
                status.textContent = 'Er ging iets mis. Probeer het opnieuw.';
            }).finally(function () {
                button.disabled = false;
            });
        });

        return wrap;
    }
});
