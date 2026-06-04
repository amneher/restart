'use strict';

(function() {

    function post(url, data) {
        return fetch(url, { method: 'POST', body: new URLSearchParams(data) })
            .then(function(r) { return r.json(); });
    }

    // Test Lambda connection
    var testBtn = document.getElementById('rr-test-lambda');
    if (testBtn) {
        testBtn.addEventListener('click', function() {
            var btn    = this;
            var result = document.getElementById('rr-lambda-test-result');
            btn.disabled = true;
            result.textContent = 'Testing…';
            result.style.color = '';

            post(rrAdmin.ajaxurl, { action: 'restart_registry_test_lambda', nonce: rrAdmin.nonce })
                .then(function(response) {
                    result.textContent = (response.success ? '✓ ' : '✗ ') + response.data.message;
                    result.style.color = response.success ? 'green' : 'red';
                    btn.disabled = false;
                }).catch(function() {
                    result.textContent = '✗ Request failed';
                    result.style.color = 'red';
                    btn.disabled = false;
                });
        });
    }

    // Re-convert affiliate links
    var reconvertBtn = document.getElementById('rr-reconvert-affiliates');
    if (reconvertBtn) {
        reconvertBtn.addEventListener('click', function() {
            var btn    = this;
            var result = document.getElementById('rr-reconvert-result');
            btn.disabled = true;
            result.textContent = 'Processing…';
            result.style.color = '';

            post(rrAdmin.ajaxurl, { action: 'restart_registry_reconvert_affiliates', nonce: rrAdmin.nonce })
                .then(function(response) {
                    result.textContent = (response.success ? '✓ ' : '✗ ') + response.data.message;
                    result.style.color = response.success ? 'green' : 'red';
                    btn.disabled = false;
                }).catch(function() {
                    result.textContent = '✗ Request failed';
                    result.style.color = 'red';
                    btn.disabled = false;
                });
        });
    }

    // Custom retailers — add/remove rows
    function renumberCustomRetailers() {
        document.querySelectorAll('#rr-custom-retailers-body .rr-custom-retailer-row').forEach(function(row, i) {
            row.querySelectorAll('input').forEach(function(input) {
                if (input.name) {
                    input.name = input.name.replace(/\[\d+\]/, '[' + i + ']');
                }
            });
        });
    }

    var addRetailerBtn = document.getElementById('rr-add-retailer');
    if (addRetailerBtn) {
        addRetailerBtn.addEventListener('click', function() {
            var tbody = document.getElementById('rr-custom-retailers-body');
            var idx   = tbody.querySelectorAll('.rr-custom-retailer-row').length;
            tbody.insertAdjacentHTML('beforeend',
                '<tr class="rr-custom-retailer-row">' +
                '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][name]" class="widefat"></td>' +
                '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][domains]" class="widefat" placeholder="example.com, shop.com"></td>' +
                '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][template]" class="widefat" placeholder="https://network.com/r?url={url}&id={affiliate_id}"></td>' +
                '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][affiliate_id]" class="widefat"></td>' +
                '<td><input type="text" name="restart_registry_custom_retailers[' + idx + '][merchant_id]" class="widefat"></td>' +
                '<td><button type="button" class="button rr-remove-retailer">Remove</button></td>' +
                '</tr>'
            );
        });
    }

    var tbody = document.getElementById('rr-custom-retailers-body');
    if (tbody) {
        tbody.addEventListener('click', function(e) {
            var btn = e.target.closest('.rr-remove-retailer');
            if (!btn) return;
            btn.closest('tr').remove();
            renumberCustomRetailers();
        });
    }

}());
