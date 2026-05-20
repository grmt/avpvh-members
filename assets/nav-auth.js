document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.avpvhAuth;
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
            ul.appendChild(makeItem('Uitloggen', cfg.logoutUrl, 'avpvh-auth-item'));
        } else {
            cfg.loginButtons.forEach(function (btn) {
                ul.appendChild(makeItem(btn.label, btn.url, 'avpvh-auth-item'));
            });
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
});
