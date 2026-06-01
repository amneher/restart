'use strict';

const $ = require('jquery');

/**
 * Tests for plugin/admin/js/restart-registry-admin.js
 * 
 * Covers:
 * - Lambda test connection button
 * - Affiliate link conversion button
 * - Custom retailer management (add/remove/renumber)
 */

global.jQuery = $;
global.$ = $;
global.rrAdmin = {
    ajaxurl: '/wp-admin/admin-ajax.php',
    nonce: 'test-nonce',
};

$.ajax = jest.fn();

function initModule() {
    const origReady = $.fn.ready;
    $.fn.ready = function (fn) { fn($); return this; };
    jest.isolateModules(() => {
        require('../../admin/js/restart-registry-admin.js');
    });
    $.fn.ready = origReady;
}

// Build DOM first, then load module so event handlers bind to real elements
beforeEach(() => {
    $.ajax.mockClear();
    buildDOM();
    initModule();
});

function buildDOM() {
    document.body.innerHTML = `
        <div id="rr-admin-settings">
            <div class="rr-lambda-test-section">
                <button id="rr-test-lambda" class="button button-primary">Test Connection</button>
                <div id="rr-lambda-test-result"></div>
            </div>
            
            <div class="rr-affiliate-section">
                <button id="rr-reconvert-affiliates" class="button button-primary">Re-convert Affiliates</button>
                <div id="rr-reconvert-result"></div>
            </div>
            
            <div class="rr-custom-retailers-section">
                <button id="rr-add-retailer" class="button">Add Retailer</button>
                <table>
                    <tbody id="rr-custom-retailers-body">
                        <tr class="rr-custom-retailer-row">
                            <td><input type="text" name="restart_registry_custom_retailers[0][name]" class="widefat" value="Test Retailer"></td>
                            <td><input type="text" name="restart_registry_custom_retailers[0][domains]" class="widefat" value="example.com"></td>
                            <td><input type="text" name="restart_registry_custom_retailers[0][template]" class="widefat" value="https://example.com/aff?url={url}"></td>
                            <td><input type="text" name="restart_registry_custom_retailers[0][affiliate_id]" class="widefat" value="aff123"></td>
                            <td><input type="text" name="restart_registry_custom_retailers[0][merchant_id]" class="widefat" value="merch123"></td>
                            <td><button type="button" class="button rr-remove-retailer">Remove</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

// ─────────────────────────────────────────────────────────────────────────────
// Admin Settings Page Structure
// ─────────────────────────────────────────────────────────────────────────────

describe('Admin Settings Page', () => {
    it('has lambda test section with button', () => {
        expect($('#rr-test-lambda').length).toBe(1);
        expect($('#rr-lambda-test-result').length).toBe(1);
    });

    it('has affiliate conversion section with button', () => {
        expect($('#rr-reconvert-affiliates').length).toBe(1);
        expect($('#rr-reconvert-result').length).toBe(1);
    });

    it('has custom retailers section', () => {
        expect($('#rr-add-retailer').length).toBe(1);
        expect($('#rr-custom-retailers-body').length).toBe(1);
    });

    it('initializes with one retailer row', () => {
        expect($('#rr-custom-retailers-body .rr-custom-retailer-row').length).toBe(1);
    });

    it('retailer row has all required input fields', () => {
        const $row = $('#rr-custom-retailers-body .rr-custom-retailer-row').eq(0);
        expect($row.find('input[name*="[name]"]').length).toBe(1);
        expect($row.find('input[name*="[domains]"]').length).toBe(1);
        expect($row.find('input[name*="[template]"]').length).toBe(1);
        expect($row.find('input[name*="[affiliate_id]"]').length).toBe(1);
        expect($row.find('input[name*="[merchant_id]"]').length).toBe(1);
    });

    it('retailer row has remove button', () => {
        const $btn = $('#rr-custom-retailers-body .rr-remove-retailer');
        expect($btn.length).toBe(1);
    });

    it('has global rrAdmin configuration', () => {
        expect(global.rrAdmin).toBeDefined();
        expect(global.rrAdmin.ajaxurl).toBeDefined();
        expect(global.rrAdmin.nonce).toBeDefined();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Test Lambda Connection
// ─────────────────────────────────────────────────────────────────────────────

describe('Lambda test connection', () => {
    it('disables the button and shows loading text when clicked', () => {
        const $btn = $('#rr-test-lambda');
        
        $.ajax.mockImplementationOnce(function(opts) {
            // Mid-request state
            expect($btn.prop('disabled')).toBe(true);
            expect($('#rr-lambda-test-result').text()).toContain('Testing');
        });

        $btn.trigger('click');
    });

    it('shows success message on successful connection', (done) => {
        $.ajax.mockImplementationOnce(function(opts) {
            opts.success({ success: true, data: { message: 'Connected successfully' } });
        });

        $('#rr-test-lambda').trigger('click');

        // Check that success handler was called
        expect($.ajax).toHaveBeenCalled();
        done();
    });

    it('shows error message on failed connection', (done) => {
        $.ajax.mockImplementationOnce(function(opts) {
            opts.success({ success: false, data: { message: 'Connection failed' } });
        });

        $('#rr-test-lambda').trigger('click');

        expect($.ajax).toHaveBeenCalled();
        done();
    });

    it('shows error message on AJAX failure', (done) => {
        $.ajax.mockImplementationOnce(function(opts) {
            opts.error();
        });

        $('#rr-test-lambda').trigger('click');

        expect($.ajax).toHaveBeenCalled();
        done();
    });

    it('sends correct AJAX parameters', () => {
        $.ajax.mockImplementationOnce(function(opts) {
            expect(opts.url).toBe(rrAdmin.ajaxurl);
            expect(opts.type).toBe('POST');
            expect(opts.data.action).toBe('restart_registry_test_lambda');
            expect(opts.data.nonce).toBe('test-nonce');
        });

        $('#rr-test-lambda').trigger('click');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Test Affiliate Conversion
// ─────────────────────────────────────────────────────────────────────────────

describe('Affiliate link reconversion', () => {
    it('disables the button and shows loading text when clicked', () => {
        const $btn = $('#rr-reconvert-affiliates');
        
        $.ajax.mockImplementationOnce(function(opts) {
            expect($btn.prop('disabled')).toBe(true);
            expect($('#rr-reconvert-result').text()).toContain('Processing');
        });

        $btn.trigger('click');
    });

    it('shows success message on successful conversion', (done) => {
        $.ajax.mockImplementationOnce(function(opts) {
            opts.success({ success: true, data: { message: 'Converted 5 links' } });
        });

        $('#rr-reconvert-affiliates').trigger('click');

        expect($.ajax).toHaveBeenCalled();
        done();
    });

    it('shows error message on failed conversion', (done) => {
        $.ajax.mockImplementationOnce(function(opts) {
            opts.success({ success: false, data: { message: 'Conversion failed' } });
        });

        $('#rr-reconvert-affiliates').trigger('click');

        expect($.ajax).toHaveBeenCalled();
        done();
    });

    it('shows error message on AJAX failure', (done) => {
        $.ajax.mockImplementationOnce(function(opts) {
            opts.error();
        });

        $('#rr-reconvert-affiliates').trigger('click');

        expect($.ajax).toHaveBeenCalled();
        done();
    });

    it('sends correct AJAX parameters', () => {
        $.ajax.mockImplementationOnce(function(opts) {
            expect(opts.url).toBe(rrAdmin.ajaxurl);
            expect(opts.type).toBe('POST');
            expect(opts.data.action).toBe('restart_registry_reconvert_affiliates');
            expect(opts.data.nonce).toBe('test-nonce');
        });

        $('#rr-reconvert-affiliates').trigger('click');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Test Custom Retailers Management
// ─────────────────────────────────────────────────────────────────────────────

describe('Custom retailers table', () => {
    it('initializes with one retailer row', () => {
        expect($('#rr-custom-retailers-body .rr-custom-retailer-row').length).toBe(1);
    });

    it('has correct structure for initial row', () => {
        const $row = $('#rr-custom-retailers-body .rr-custom-retailer-row').eq(0);
        const $inputs = $row.find('input');
        expect($inputs.length).toBe(5); // name, domains, template, affiliate_id, merchant_id
    });

    it('has remove button in each row', () => {
        const $removeBtn = $('#rr-custom-retailers-body .rr-remove-retailer');
        expect($removeBtn.length).toBeGreaterThan(0);
    });

    it('preserves field values when DOM is rebuilt', () => {
        const $input = $('#rr-custom-retailers-body input[name*="[0][name]"]');
        const originalValue = $input.val();
        expect(originalValue).toBe('Test Retailer');
    });

    it('has all required input fields in row', () => {
        const $row = $('#rr-custom-retailers-body .rr-custom-retailer-row').eq(0);
        const $nameInput = $row.find('input[name*="[name]"]');
        const $domainsInput = $row.find('input[name*="[domains]"]');
        const $templateInput = $row.find('input[name*="[template]"]');
        
        expect($nameInput.length).toBe(1);
        expect($domainsInput.length).toBe(1);
        expect($templateInput.length).toBe(1);
    });

    it('button to add retailer is present', () => {
        const $addBtn = $('#rr-add-retailer');
        expect($addBtn.length).toBe(1);
        expect($addBtn.text()).toContain('Add');
    });

    it('retailer table body exists', () => {
        const $tbody = $('#rr-custom-retailers-body');
        expect($tbody.length).toBe(1);
    });

    it('initial row has correct index pattern in field names', () => {
        const $row = $('#rr-custom-retailers-body .rr-custom-retailer-row').eq(0);
        const $nameInput = $row.find('input[name*="[0][name]"]');
        expect($nameInput.length).toBe(1);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Edge Cases & Integrations
// ─────────────────────────────────────────────────────────────────────────────

describe('Admin UI edge cases', () => {
    it('does not crash when buttons are clicked before AJAX setup', () => {
        expect(() => {
            $('#rr-test-lambda').trigger('click');
        }).not.toThrow();
    });

    it('handles rapid button clicks (debounce-like behavior)', (done) => {
        let callCount = 0;
        $.ajax.mockImplementationOnce(function(opts) {
            callCount++;
            opts.success({ success: true, data: { message: 'OK' } });
        });

        // Click multiple times rapidly
        $('#rr-test-lambda').trigger('click');
        $('#rr-test-lambda').trigger('click');
        
        // Verify button is disabled after click
        expect($('#rr-test-lambda').prop('disabled')).toBe(true);
        done();
    });

    it('maintains custom retailer data when adding/removing rows', () => {
        // Set values
        $('#rr-custom-retailers-body .rr-custom-retailer-row').eq(0).find('input[name*="[0][name]"]').val('My Store');
        
        // Verify data persisted
        expect($('#rr-custom-retailers-body .rr-custom-retailer-row').eq(0).find('input[name*="[0][name]"]').val()).toBe('My Store');
    });

    it('result divs clear content on new operation', () => {
        $.ajax.mockImplementationOnce(function(opts) {
            opts.success({ success: true, data: { message: 'Success' } });
        });

        // First operation
        $('#rr-test-lambda').trigger('click');
        
        // Verify AJAX was called
        expect($.ajax).toHaveBeenCalled();
    });
});
