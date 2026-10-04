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

        // Handle form submission
        $form.on('submit', function(e) {
            e.preventDefault();
            submitForm();
        });

        function submitForm() {
            const $submit = $form.find('button[type="submit"]');
            const originalText = $submit.text();

            $submit.prop('disabled', true).text('Bezig met opslaan...');
            $form.addClass('loading');

            const formData = new FormData($form[0]);
            formData.append('action', 'avpvh_save_member_profile');

            var configEl = document.getElementById('avpvh-registration-config');
            var config = {};
            if (configEl && configEl.textContent) {
                try {
                    config = JSON.parse(configEl.textContent);
                } catch (e) {}
            }
            var ajaxUrl = config.ajaxUrl || (typeof avpvhRegistration !== 'undefined' ? avpvhRegistration.ajaxUrl : '') || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

            $.ajax({
                type: 'POST',
                url: ajaxUrl,
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        var message = typeof response.data === 'string' ? response.data : (response.data && response.data.message ? response.data.message : 'Profiel succesvol bijgewerkt!');
                        showMessage('success', message);
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        showMessage('error', response.data || 'Er is een fout opgetreden.');
                    }
                },
                error: function() {
                    showMessage('error', 'Opslaan van het profiel is mislukt. Probeer het opnieuw.');
                },
                complete: function() {
                    $submit.prop('disabled', false).text(originalText);
                    $form.removeClass('loading');
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
    });

    // Collapsible profile sections (Persoonlijke gegevens, Contact, etc.) —
    // each fieldset's legend toggles its own contents, remembered per
    // section in localStorage so it stays collapsed on the next visit.
    $(document).ready(function() {
        $('.avpvh-member-profile-form fieldset').each(function() {
            var $fieldset = $(this);
            var $legend = $fieldset.children('legend').first();
            if ($legend.length === 0) {
                return;
            }

            var key = 'avpvh-profile-collapsed:' + $legend.text().trim();
            $legend.attr({ role: 'button', tabindex: '0' });

            function setCollapsed(collapsed) {
                $fieldset.toggleClass('avpvh-collapsed', collapsed);
                $legend.attr('aria-expanded', collapsed ? 'false' : 'true');
            }

            try {
                setCollapsed(window.localStorage.getItem(key) === '1');
            } catch (e) {
                setCollapsed(false);
            }

            $legend.on('click', function() {
                var collapsed = !$fieldset.hasClass('avpvh-collapsed');
                setCollapsed(collapsed);
                try {
                    window.localStorage.setItem(key, collapsed ? '1' : '0');
                } catch (e) {
                    // Private browsing / storage disabled — toggling still works, just isn't remembered.
                }
            });

            $legend.on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $legend.trigger('click');
                }
            });
        });
    });

})(jQuery);
