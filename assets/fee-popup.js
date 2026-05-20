jQuery(function ($) {
    var $overlay = $('#avpvh-fee-popup');
    if (!$overlay.length) return;

    $('#avpvh-fee-dismiss').on('click', function () {
        $.post(avpvhPopup.ajaxUrl, {
            action: 'avpvh_dismiss_popup',
            nonce:  avpvhPopup.nonce,
        }).always(function () {
            // 7-day dismiss cookie
            var expires = new Date();
            expires.setDate(expires.getDate() + 7);
            document.cookie = 'avpvh_fee_dismissed=1; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
            $overlay.fadeOut(200);
        });
    });
});
