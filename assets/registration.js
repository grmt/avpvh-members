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
                        showMessage('success', response.data.message);
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

})(jQuery);
