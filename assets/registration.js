(function($) {
    'use strict';

    $(document).ready(function() {
        // Support both registration and member profile forms
        let $form = $('#avpvh-registration-form');
        let formAction = 'avpvh_save_registration';

        if ($form.length === 0) {
            $form = $('#avpvh-profile-form');
            formAction = 'avpvh_save_member_profile';
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

            $submit.prop('disabled', true).text('Saving...');
            $form.addClass('loading');

            const formData = new FormData($form[0]);
            formData.append('action', formAction);

            $.ajax({
                type: 'POST',
                url: avpvhRegistration.ajaxUrl,
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        // The registration handler sends {message: ...}, the
                        // profile-save handler sends a plain string — handle both.
                        var message = typeof response.data === 'string' ? response.data : response.data.message;
                        showMessage('success', message);
                        // Optionally redirect after success
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        showMessage('error', response.data || 'An error occurred');
                    }
                },
                error: function() {
                    showMessage('error', 'Failed to save registration. Please try again.');
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

        // Optional: Auto-save on input change (uncomment if desired)
        /*
        $form.on('change', 'input, textarea, select', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(submitForm, 2000);
        });
        */
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
