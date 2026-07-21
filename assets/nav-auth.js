document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('avpvh-auth-config');
    if (!el) return;
    var cfg = JSON.parse(el.textContent);
    if (!cfg) return;

    // --- hide members-only nav items for guests ---
    if (!cfg.isLoggedIn) {
        cfg.membersPageIds.forEach(function (id) {
            document.querySelectorAll('a[href*="page_id=' + id + '"], a[href*="/?p=' + id + '"]').forEach(function (a) {
                // Walk up to the <li> that is a direct child of the nav container
                var li = a.closest('li');
                while (li && li.parentElement && !li.parentElement.matches('nav, .wp-block-navigation__container, .wp-block-navigation__responsive-container-content')) {
                    li = li.parentElement.closest('li');
                }
                if (li) li.style.display = 'none';
            });
        });
    }

    // --- inject login / logout button into every nav ---
    var navs = document.querySelectorAll('nav.wp-block-navigation');
    navs.forEach(function (nav) {
        // find the top-level <ul>
        var ul = nav.querySelector('.wp-block-navigation__container');
        if (!ul) ul = nav.querySelector('ul');
        if (!ul) return;

        if (cfg.isLoggedIn) {
            var statusItem = makeStatusItem(cfg.userLabel, cfg.roleLabel, cfg.memberRoleLabel, cfg.isActiveMember);
            ul.appendChild(statusItem);
            ul.appendChild(makeItem('Uitloggen', cfg.logoutUrl, 'avpvh-auth-item'));
        } else {
            ul.appendChild(makeItem('Inloggen', cfg.loginUrl, 'avpvh-auth-item'));
        }
    });

    function makeItem(label, url, cls) {
        var li = document.createElement('li');
        li.className = 'wp-block-navigation-item wp-block-navigation-link ' + cls;
        var a = document.createElement('a');
        a.className = 'wp-block-navigation-item__content';
        a.href = url;
        a.textContent = label;
        li.appendChild(a);
        return li;
    }

    function makeStatusItem(userLabel, roleLabel, memberRoleLabel, isActiveMember) {
        var li = document.createElement('li');
        li.className = 'wp-block-navigation-item avpvh-auth-status';

        var span = document.createElement('span');
        span.className = 'avpvh-auth-status__badge';
        // Keep this compact — just who's logged in and their membership status.
        // roleLabel/memberRoleLabel are still passed in for pages that want them.
        span.textContent = userLabel + ' · ' + (isActiveMember ? 'Lid' : 'Geen lid');
        span.title = [userLabel, roleLabel, memberRoleLabel].filter(Boolean).join(' · ');

        li.appendChild(span);
        return li;
    }
});
