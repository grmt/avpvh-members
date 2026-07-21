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

    // --- inject the account menu into the header nav only ---
    // (the footer has its own separate nav.wp-block-navigation blocks —
    // e.g. "Menu" and "Privacy" columns — that shouldn't each get one too)
    var navs = document.querySelectorAll('header.wp-block-template-part nav.wp-block-navigation');
    navs.forEach(function (nav) {
        // find the top-level <ul>
        var ul = nav.querySelector('.wp-block-navigation__container');
        if (!ul) ul = nav.querySelector('ul');
        if (!ul) return;

        ul.appendChild(makeAccountItem(cfg));
    });

    // --- temporary viewport debug badge, only with ?avpvh_debug=1 ---
    if (/[?&]avpvh_debug=1/.test(location.search)) {
        var badge = document.createElement('div');
        badge.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:999999;'
            + 'background:#c00;color:#fff;font:12px monospace;padding:.4em;text-align:center;';
        function updateBadge() {
            badge.textContent = 'innerWidth=' + window.innerWidth
                + ' innerHeight=' + window.innerHeight
                + ' visualViewportHeight=' + (window.visualViewport ? Math.round(window.visualViewport.height) : 'n/a')
                + ' orientation=' + (screen.orientation ? screen.orientation.type : 'n/a');
        }
        updateBadge();
        window.addEventListener('resize', updateBadge);
        document.body.appendChild(badge);
    }

    // Close any open account menu when clicking outside it.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.avpvh-auth-status.is-open').forEach(function (li) {
            if (!li.contains(e.target)) closeMenu(li);
        });
    });

    function openMenu(li, toggle, menu) {
        li.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        menu.hidden = false;
    }

    function closeMenu(li) {
        var toggle = li.querySelector('.avpvh-auth-status__toggle');
        var menu = li.querySelector('.avpvh-auth-status__menu');
        li.classList.remove('is-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        if (menu) menu.hidden = true;
    }

    function makeAccountItem(cfg) {
        var li = document.createElement('li');
        li.className = 'wp-block-navigation-item avpvh-auth-status';

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'avpvh-auth-status__toggle';
        toggle.setAttribute('aria-haspopup', 'true');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Account');
        toggle.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
            + '<path fill="currentColor" d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z"/>'
            + '</svg>';
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = li.classList.contains('is-open');
            document.querySelectorAll('.avpvh-auth-status.is-open').forEach(closeMenu);
            if (!isOpen) openMenu(li, toggle, menu);
        });

        var menu = document.createElement('ul');
        menu.className = 'avpvh-auth-status__menu';
        menu.hidden = true;

        if (cfg.isLoggedIn) {
            var nameLabel = cfg.userLabel + ' · ' + (cfg.isActiveMember ? 'Lid' : 'Geen lid');
            menu.appendChild(makeMenuLabel(nameLabel, [cfg.userLabel, cfg.roleLabel, cfg.memberRoleLabel].filter(Boolean).join(' · ')));
            menu.appendChild(makeMenuLink('Mijn profiel', cfg.profileUrl));
            menu.appendChild(makeMenuLink('Uitloggen', cfg.logoutUrl));
        } else {
            menu.appendChild(makeMenuLink('Inloggen', cfg.loginUrl));
        }

        li.appendChild(toggle);
        li.appendChild(menu);
        return li;
    }

    function makeMenuLabel(text, title) {
        var li = document.createElement('li');
        li.className = 'avpvh-auth-status__menu-label';
        li.textContent = text;
        if (title) li.title = title;
        return li;
    }

    function makeMenuLink(label, url) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = url;
        a.textContent = label;
        li.appendChild(a);
        return li;
    }
});
