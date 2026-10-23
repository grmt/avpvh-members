document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('avpvh-auth-config');
    if (!el) return;
    var cfg = JSON.parse(el.textContent);
    if (!cfg) return;

    // --- hide members-only nav items without active membership ---
    // (climbs to the top-level nav item because every item under "Alleen voor
    // Leden" requires active membership — these 2 IDs are anchors to hide
    // that whole dropdown, not a request to hide only these 2 items)
    if (!cfg.isActiveMember) {
        cfg.membersPageIds.forEach(function (id) {
            hideLinksTo(id, { climbToTopLevel: true, navOnly: true, slug: 'leden' });
        });
    }

    // --- hide "Zoeken in documenten" for members without boek-group access ---
    // (this page is gated by Authelia itself, not WordPress — a member without
    // access would just be bounced to a second login they can't complete)
    // Unlike the members-only case above, only this item should disappear —
    // siblings like "Ledenlijst" stay visible, so this does NOT climb to the
    // top-level nav item.
    if (!cfg.hasDocSearchAccess) {
        hideLinksTo(cfg.docSearchPageId, { slug: 'zoeken-in-documenten' });
    }

    // Hides every link to a given page — matched by page_id/p query var and,
    // optionally, its pretty-permalink slug — wherever it appears on the page,
    // not just inside nav menus (e.g. tiles on the "leden" landing page).
    function hideLinksTo(pageId, opts) {
        opts = opts || {};
        var selector = 'a[href*="page_id=' + pageId + '"], a[href*="/?p=' + pageId + '"]';
        if (opts.slug) selector += ', a[href*="/' + opts.slug + '"]';
        document.querySelectorAll(selector).forEach(function (a) {
            if (opts.navOnly && !a.closest('nav.wp-block-navigation')) return;
            var url = new URL(a.href, location.href);
            if (url.searchParams.get('page_id') !== String(pageId)
                && url.searchParams.get('p') !== String(pageId)
                && (!opts.slug || url.pathname.split('/').indexOf(opts.slug) === -1)) return;
            var li = a.closest('li');
            if (opts.climbToTopLevel) {
                // Walk up to the <li> that is a direct child of the nav container
                while (li && li.parentElement && !li.parentElement.matches('nav, .wp-block-navigation__container, .wp-block-navigation__responsive-container-content')) {
                    li = li.parentElement.closest('li');
                }
            }
            if (li) {
                li.style.display = 'none';
            } else {
                a.style.display = 'none';
            }
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

    // --- "Uitgelogd als ..." after logout (and after an identity mismatch) ---
    // The server leaves which account(s) were signed out in a short-lived
    // cookie; show it once and delete it straight away.
    showSignedOutNotice(cfg);

    function showSignedOutNotice(cfg) {
        var name = cfg.signedOutCookie + '=';
        var raw = document.cookie.split('; ').filter(function (c) { return c.indexOf(name) === 0; })[0];
        if (!raw) return;
        document.cookie = name + '; Max-Age=0; Path=/; SameSite=Lax';
        var ids;
        try {
            ids = JSON.parse(decodeURIComponent(raw.slice(name.length)));
        } catch (e) {
            return;
        }
        var lines = [];
        if (ids.wp) lines.push(cfg.labels.signedOutAs.replace('%s', ids.wp));
        if (ids.sso && ids.sso !== ids.wp) lines.push(cfg.labels.signedOutSso.replace('%s', ids.sso));
        if (!lines.length) return;

        var notice = document.createElement('div');
        notice.className = 'avpvh-signed-out-notice';
        notice.setAttribute('role', 'status');
        lines.forEach(function (line) {
            var p = document.createElement('p');
            p.textContent = line;
            notice.appendChild(p);
        });
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'avpvh-signed-out-notice__close';
        close.setAttribute('aria-label', cfg.labels.close);
        close.textContent = '×';
        close.addEventListener('click', function () { notice.remove(); });
        notice.appendChild(close);
        document.body.appendChild(notice);
    }

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

    // Close the account menu when clicking outside it (core's own submenus
    // already handle this for themselves via the Interactivity API).
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.avpvh-auth-status').forEach(function (li) {
            if (li.contains(e.target)) return;
            var toggle = li.querySelector('.wp-block-navigation-submenu__toggle');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });
    });

    // --- keep opened dropdowns/submenus from falling outside the viewport ---
    // Covers both our own account menu and core's "has-child" nav submenus
    // (e.g. the "Alleen voor Leden" submenu in the footer): whenever one of
    // these toggles to aria-expanded="true", scroll its containing <li> into
    // view if expanding it pushed part of it past the bottom edge.
    new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            var target = m.target;
            if (!target.matches('.wp-block-navigation-submenu__toggle')) return;
            if (target.getAttribute('aria-expanded') !== 'true') return;
            var li = target.closest('li');
            if (!li) return;
            requestAnimationFrame(function () {
                if (li.getBoundingClientRect().bottom > window.innerHeight) {
                    li.scrollIntoView({ block: 'end', behavior: 'smooth' });
                }
            });
        });
    }).observe(document.body, { attributes: true, attributeFilter: ['aria-expanded'], subtree: true });

    // Builds the account entry using the same has-child/submenu-toggle
    // markup core's own nav menu items use (e.g. "Welkom!", "De vereniging"),
    // so it automatically gets their existing desktop dropdown positioning
    // and mobile full-screen-overlay behaviour for free — no bespoke CSS.
    function makeAccountItem(cfg) {
        var li = document.createElement('li');
        li.className = 'wp-block-navigation-item has-child open-on-hover-click wp-block-navigation-submenu avpvh-auth-status';

        function toggleOpen(e) {
            e.stopPropagation();
            var isOpen = chevron.getAttribute('aria-expanded') === 'true';
            chevron.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        }

        var labels = cfg.labels || {};
        var loginStatus = cfg.isLoggedIn ? (labels.loggedIn || 'Ingelogd') : (labels.login || 'Inloggen');

        var content = document.createElement('button');
        content.type = 'button';
        content.className = 'wp-block-navigation-item__content avpvh-auth-status__content';
        var accessibleLabel = loginStatus + (cfg.isLoggedIn && cfg.userLabel ? ' · ' + cfg.userLabel : '');
        content.setAttribute('aria-label', accessibleLabel);
        content.innerHTML = '<span class="avpvh-auth-status__icon">'
            + '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
            + '<path fill="currentColor" d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z"/>'
            + '</svg></span>';
        if (cfg.isLoggedIn) {
            // Logged in: a green check on the person icon instead of the
            // word "Ingelogd", to save space; the text stays in aria-label
            // and shows as a tooltip.
            content.title = accessibleLabel;
            content.firstChild.insertAdjacentHTML('beforeend',
                '<svg class="avpvh-auth-status__check" viewBox="0 0 12 12" width="12" height="12" aria-hidden="true" focusable="false">'
                + '<circle cx="6" cy="6" r="6" fill="#1e8e3e"/>'
                + '<path d="M3.2 6.2l1.9 1.9 3.7-3.9" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>'
                + '</svg>');
        } else {
            var statusLabel = document.createElement('span');
            statusLabel.textContent = loginStatus;
            content.appendChild(statusLabel);
        }
        content.addEventListener('click', toggleOpen);

        var chevron = document.createElement('button');
        chevron.type = 'button';
        chevron.className = 'wp-block-navigation__submenu-icon wp-block-navigation-submenu__toggle';
        chevron.setAttribute('aria-expanded', 'false');
        chevron.setAttribute('aria-label', labels.accountMenu || 'Account submenu');
        chevron.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" focusable="false"><path d="M1.50002 4L6.00002 8L10.5 4" stroke-width="1.5"></path></svg>';
        chevron.addEventListener('click', toggleOpen);

        var menu = document.createElement('ul');
        menu.className = 'wp-block-navigation__submenu-container wp-block-navigation-submenu';

        if (cfg.isLoggedIn) {
            var memberStatus = cfg.memberStatusLabel || (cfg.isActiveMember ? (labels.member || 'Lid') : (labels.nonMember || 'Geen lid'));
            var nameLabel = cfg.userLabel + ' · ' + memberStatus;
            menu.appendChild(makeMenuLabel(nameLabel, [cfg.userLabel, cfg.roleLabel, cfg.memberRoleLabel].filter(Boolean).join(' · ')));
            menu.appendChild(makeMenuLink(labels.myProfile || 'Mijn profiel', cfg.profileUrl));
            if (cfg.paymentsUrl) {
                menu.appendChild(makeMenuLink(labels.myPayments || 'Mijn betalingen', cfg.paymentsUrl));
            }
            if (cfg.membershipApplicationUrl) {
                menu.appendChild(makeMenuLink(labels.applyMembership || 'Lidmaatschap aanvragen', cfg.membershipApplicationUrl));
            }
            menu.appendChild(makeMenuLink(labels.logout || 'Uitloggen', cfg.logoutUrl));
        } else {
            var loginUrl = cfg.loginUrl;
            if (window.location.pathname !== '/avpvh-login/') {
                try {
                    var u = new URL(cfg.loginUrl, window.location.origin);
                    u.searchParams.set('redirect_to', window.location.href);
                    loginUrl = u.toString();
                } catch (e) {
                    // keep default cfg.loginUrl
                }
            }
            menu.appendChild(makeMenuLink(labels.login || 'Inloggen', loginUrl));
        }

        li.appendChild(content);
        li.appendChild(chevron);
        li.appendChild(menu);
        return li;
    }

    function makeMenuLabel(text, title) {
        var li = document.createElement('li');
        li.className = 'wp-block-navigation-item wp-block-navigation-link avpvh-auth-status__menu-label';
        var span = document.createElement('span');
        span.className = 'wp-block-navigation-item__content';
        span.textContent = text;
        if (title) span.title = title;
        li.appendChild(span);
        return li;
    }

    function makeMenuLink(label, url) {
        var li = document.createElement('li');
        li.className = 'wp-block-navigation-item wp-block-navigation-link';
        var a = document.createElement('a');
        a.className = 'wp-block-navigation-item__content';
        a.href = url;
        a.textContent = label;
        li.appendChild(a);
        return li;
    }
});
