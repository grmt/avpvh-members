(function($) {
    'use strict';

    $(document).ready(function() {
        let $form = $('#avpvh-profile-form');
        if ($form.length === 0) {
            $form = $('#avpvh-registration-form');
        }

        if ($form.length === 0) {
            return;
        }

        const $submit = $form.find('button[type="submit"]');
        let initialData = $form.serialize();

        function updateSubmitState() {
            $submit.prop('disabled', $form.hasClass('loading') || $form.serialize() === initialData);
        }

        // A member picked as emergency contact brings their own name, phone
        // and address, so the typed-in fields are hidden meanwhile.
        $form.on('change', '#emergency_member_id', function() {
            $form.find('.avpvh-emergency-manual').prop('hidden', this.value !== '');
        });

        updateSubmitState();
        $form.on('input change', updateSubmitState);

        // Handle form submission
        $form.on('submit', function(e) {
            e.preventDefault();
            if ($form.serialize() === initialData) {
                return;
            }
            submitForm();
        });

        function submitForm() {
            const originalText = $submit.text();

            var configEl = document.getElementById('avpvh-registration-config');
            var config = {};
            if (configEl && configEl.textContent) {
                try {
                    config = JSON.parse(configEl.textContent);
                } catch (e) {}
            }
            var strings = config.strings || {};
            var savingText = strings.saving || 'Bezig met opslaan...';
            var successText = strings.success || 'Profiel succesvol bijgewerkt!';
            var errorText = strings.error || 'Er is een fout opgetreden.';
            var failedText = strings.failed || 'Opslaan van het profiel is mislukt. Probeer het opnieuw.';

            $submit.prop('disabled', true).text(savingText);
            $form.addClass('loading');

            const formData = new FormData($form[0]);
            formData.append('action', 'avpvh_save_member_profile');

            var ajaxUrl = config.ajaxUrl || (typeof avpvhRegistration !== 'undefined' ? avpvhRegistration.ajaxUrl : '') || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

            $.ajax({
                type: 'POST',
                url: ajaxUrl,
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        initialData = $form.serialize();
                        var message = typeof response.data === 'string' ? response.data : (response.data && response.data.message ? response.data.message : successText);
                        showMessage('success', message);
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        showMessage('error', response.data || errorText);
                    }
                },
                error: function() {
                    showMessage('error', failedText);
                },
                complete: function() {
                    $submit.text(originalText);
                    $form.removeClass('loading');
                    updateSubmitState();
                }
            });
        }

        function showMessage(type, message) {
            const className = type === 'success' ? 'success-message' : 'error-message';
            const $message = $('<div>')
                .addClass(className)
                .text(message)
                .prependTo($form);

            setTimeout(function() {
                $message.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }

        // Keep the consent and newsletter save buttons disabled until a
        // checkbox is changed (except when directory consent isn't granted
        // yet, where "Voorkeuren opslaan" itself grants consent).
        $('.avpvh-newsletter-consent form, .avpvh-directory-consent form').each(function() {
            var $f = $(this);
            if ($f.closest('.avpvh-directory-consent').length && $f.find('button[value="declined"]').length === 0) {
                return;
            }
            var $btn = $f.find('button.button-primary');
            var init = $f.serialize();
            var sync = function() {
                $btn.prop('disabled', $f.serialize() === init);
            };
            sync();
            $f.on('change', sync);
        });
    });

    // Profile sections: every top-level fieldset is a section that folds
    // open and shut the same way. The first one starts open, the rest
    // closed, so everyone sees the same page; a section with a save notice
    // or the one named in the URL hash (e.g. #lidmaatschap) opens too.
    // A bar above the sections jumps to one and opens it.
    $(document).ready(function() {
        var $root = $('.avpvh-member-profile-form').first();
        var $sections = $root.find('fieldset').filter(function() {
            return $(this).children('legend').length > 0 && $(this).parent().closest('fieldset').length === 0;
        });
        if ($sections.length === 0) {
            return;
        }

        try {
            Object.keys(window.localStorage).forEach(function(key) {
                if (key.indexOf('avpvh-profile-collapsed:') === 0) {
                    window.localStorage.removeItem(key);
                }
            });
        } catch (e) {
            // Storage disabled — nothing left over to clean up.
        }

        var chevron = '<svg class="avpvh-section-chevron" viewBox="0 0 12 12" width="12" height="12" aria-hidden="true" focusable="false"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        var usedIds = {};

        function slug(text) {
            var base = 'sectie-' + text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            var id = base;
            for (var n = 2; usedIds[id] || (document.getElementById(id) && !$sections.is('#' + id)); n++) {
                id = base + '-' + n;
            }
            usedIds[id] = true;
            return id;
        }

        // Short "value · value · value" line shown in a closed section's bar,
        // from the section's own filled-in fields (only the plain field grids).
        function summary($fieldset) {
            if (!$fieldset.hasClass('avpvh-fields-grid')) {
                return '';
            }
            var parts = [];
            $fieldset.find('input[type=text], input[type=email], input[type=tel], input[type=date], select').each(function() {
                if ($(this).closest('[hidden]').length) {
                    return;
                }
                var value = this.tagName === 'SELECT'
                    ? (this.value ? $(this).find('option:selected').text() : '')
                    : this.value;
                value = $.trim(value || '');
                if (value !== '' && parts.length < 3) {
                    parts.push(value);
                }
            });
            return parts.join(' · ');
        }

        function setOpen($fieldset, open) {
            $fieldset.toggleClass('avpvh-collapsed', !open);
            $fieldset.children('legend').attr('aria-expanded', open ? 'true' : 'false');
            $fieldset.find('> legend .avpvh-section-summary').text(open ? '' : summary($fieldset));
        }

        var $nav = $('<nav class="avpvh-profile-nav"></nav>').attr('aria-label', 'Profielonderdelen');
        var $links = $('<ul class="avpvh-profile-nav__links"></ul>').appendTo($nav);

        $sections.each(function(index) {
            var $fieldset = $(this);
            var $legend = $fieldset.children('legend').first();
            var title = $.trim($legend.text());
            if (!this.id) {
                this.id = slug(title);
            }
            $legend.empty()
                .append($('<span class="avpvh-section-title"></span>').text(title))
                .append('<span class="avpvh-section-summary"></span>')
                .append(chevron)
                .attr({ role: 'button', tabindex: '0', 'aria-controls': this.id });

            $('<li></li>').append($('<a></a>').attr('href', '#' + this.id).text(title)).appendTo($links);

            var open = index === 0
                || $fieldset.find('.avpvh-identity-notice').length > 0
                || window.location.hash === '#' + this.id;
            setOpen($fieldset, open);

            $legend.on('click', function() {
                setOpen($fieldset, $fieldset.hasClass('avpvh-collapsed'));
            });
            $legend.on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $legend.trigger('click');
                }
            });
        });

        var $all = $('<div class="avpvh-profile-nav__all"></div>').appendTo($nav);
        $('<button type="button" class="avpvh-profile-nav__button"></button>').text('Alles open').appendTo($all)
            .on('click', function() { $sections.each(function() { setOpen($(this), true); }); });
        $('<button type="button" class="avpvh-profile-nav__button"></button>').text('Alles dicht').appendTo($all)
            .on('click', function() { $sections.each(function() { setOpen($(this), false); }); });

        if ($sections.length > 1) {
            $nav.insertBefore($sections.first().closest($root.children()));
            var setNavHeight = function() {
                var top = parseFloat(window.getComputedStyle($nav[0]).top) || 0;
                $root[0].style.setProperty('--avpvh-profile-nav-height', ($nav.outerHeight() + top) + 'px');
            };
            setNavHeight();
            $(window).on('resize', setNavHeight);
        }

        $nav.on('click', 'a', function(e) {
            var $target = $sections.filter(this.getAttribute('href'));
            e.preventDefault();
            setOpen($target, true);
            $target[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (window.history.replaceState) {
                window.history.replaceState(null, '', this.getAttribute('href'));
            }
        });

        if (window.location.hash && $sections.filter(window.location.hash.replace(/[^#\w-]/g, '')).length) {
            $sections.filter(window.location.hash.replace(/[^#\w-]/g, ''))[0].scrollIntoView({ block: 'start' });
        }

        // A required field in a closed section would make the browser
        // refuse to submit without showing why — open it first.
        $root[0].addEventListener('invalid', function(e) {
            var $fieldset = $(e.target).closest($sections);
            if ($fieldset.hasClass('avpvh-collapsed')) {
                setOpen($fieldset, true);
            }
        }, true);
    });

})(jQuery);
