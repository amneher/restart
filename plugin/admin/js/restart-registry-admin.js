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

    // Custom retailers — add/remove rows
    function renumberCustomRetailers() {
        $('#rr-custom-retailers-body .rr-custom-retailer-row').each(function(i) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(/\[\d+\]/, '[' + i + ']'));
                }
            });
        });
    }

    $('#rr-add-retailer').on('click', function() {
        var idx  = $('#rr-custom-retailers-body .rr-custom-retailer-row').length;
        var row  = '<tr class="rr-custom-retailer-row">' +
            '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][name]" class="widefat"></td>' +
            '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][domains]" class="widefat" placeholder="example.com, shop.com"></td>' +
            '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][template]" class="widefat" placeholder="https://network.com/r?url={url}&id={affiliate_id}"></td>' +
            '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][affiliate_id]" class="widefat"></td>' +
            '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][merchant_id]" class="widefat"></td>' +
            '<td><button type="button" class="button rr-remove-retailer">Remove</button></td>' +
            '</tr>';
        $('#rr-custom-retailers-body').append(row);
    });

    $('#rr-custom-retailers-body').on('click', '.rr-remove-retailer', function() {
        $(this).closest('tr').remove();
        renumberCustomRetailers();
    });

    }); // document.ready
})(jQuery);
