(function($) {
    'use strict';

    $(document).ready(function() {

    // Test Lambda connection
    $('#rr-test-lambda').on('click', function() {
        var $btn    = $(this);
        var $result = $('#rr-lambda-test-result');
        $btn.prop('disabled', true);
        $result.text('Testing\u2026').css('color', '');

        $.post(rrAdmin.ajaxurl, {
            action: 'restart_registry_test_lambda',
            nonce:  rrAdmin.nonce,
        }, function(response) {
            if (response.success) {
                $result.text('\u2713 ' + response.data.message).css('color', 'green');
            } else {
                $result.text('\u2717 ' + response.data.message).css('color', 'red');
            }
            $btn.prop('disabled', false);
        }).fail(function() {
            $result.text('\u2717 Request failed').css('color', 'red');
            $btn.prop('disabled', false);
        });
    });

    // Re-convert affiliate links
    $('#rr-reconvert-affiliates').on('click', function() {
        var $btn    = $(this);
        var $result = $('#rr-reconvert-result');
        $btn.prop('disabled', true);
        $result.text('Processing\u2026').css('color', '');

        $.post(rrAdmin.ajaxurl, {
            action: 'restart_registry_reconvert_affiliates',
            nonce:  rrAdmin.nonce,
        }, function(response) {
            if (response.success) {
                $result.text('\u2713 ' + response.data.message).css('color', 'green');
            } else {
                $result.text('\u2717 ' + response.data.message).css('color', 'red');
            }
            $btn.prop('disabled', false);
        }).fail(function() {
            $result.text('\u2717 Request failed').css('color', 'red');
            $btn.prop('disabled', false);
        });
    });

    }); // document.ready
})(jQuery);
