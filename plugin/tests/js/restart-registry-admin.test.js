'use strict';

const flushPromises = () => new Promise(process.nextTick);

global.rrAdmin = {
    ajaxurl: '/wp-admin/admin-ajax.php',
    nonce: 'test-nonce',
};

delete window.location;
window.location = { href: '' };

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

function loadModule() {
    jest.isolateModules(() => {
        require('../../admin/js/restart-registry-admin.js');
    });
}

beforeEach(() => {
    global.fetch = jest.fn();
    buildDOM();
    loadModule();
});

afterEach(() => {
    jest.restoreAllMocks();
    delete global.fetch;
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin Settings Page Structure
// ─────────────────────────────────────────────────────────────────────────────

describe('Admin Settings Page', () => {
    it('has lambda test section with button', () => {
        expect(document.getElementById('rr-test-lambda')).not.toBeNull();
        expect(document.getElementById('rr-lambda-test-result')).not.toBeNull();
    });

    it('has affiliate conversion section with button', () => {
        expect(document.getElementById('rr-reconvert-affiliates')).not.toBeNull();
        expect(document.getElementById('rr-reconvert-result')).not.toBeNull();
    });

    it('has custom retailers section', () => {
        expect(document.getElementById('rr-add-retailer')).not.toBeNull();
        expect(document.getElementById('rr-custom-retailers-body')).not.toBeNull();
    });

    it('initializes with one retailer row', () => {
        expect(document.querySelectorAll('#rr-custom-retailers-body .rr-custom-retailer-row').length).toBe(1);
    });

    it('retailer row has all required input fields', () => {
        const row = document.querySelector('#rr-custom-retailers-body .rr-custom-retailer-row');
        expect(row.querySelector('input[name*="[name]"]')).not.toBeNull();
        expect(row.querySelector('input[name*="[domains]"]')).not.toBeNull();
        expect(row.querySelector('input[name*="[template]"]')).not.toBeNull();
        expect(row.querySelector('input[name*="[affiliate_id]"]')).not.toBeNull();
        expect(row.querySelector('input[name*="[merchant_id]"]')).not.toBeNull();
    });

    it('retailer row has remove button', () => {
        expect(document.querySelector('#rr-custom-retailers-body .rr-remove-retailer')).not.toBeNull();
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
        global.fetch = jest.fn(() => new Promise(() => {})); // never resolves
        document.getElementById('rr-test-lambda').click();
        expect(document.getElementById('rr-test-lambda').disabled).toBe(true);
        expect(document.getElementById('rr-lambda-test-result').textContent).toContain('Testing');
    });

    it('shows success message on successful connection', async () => {
        global.fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { message: 'Connected successfully' } }),
        });
        document.getElementById('rr-test-lambda').click();
        await flushPromises();
        const result = document.getElementById('rr-lambda-test-result');
        expect(result.textContent).toContain('✓');
        expect(result.textContent).toContain('Connected successfully');
        expect(result.style.color).toBe('green');
    });

    it('shows error message on failed connection', async () => {
        global.fetch.mockResolvedValueOnce({
            json: async () => ({ success: false, data: { message: 'Connection failed' } }),
        });
        document.getElementById('rr-test-lambda').click();
        await flushPromises();
        const result = document.getElementById('rr-lambda-test-result');
        expect(result.textContent).toContain('✗');
        expect(result.textContent).toContain('Connection failed');
        expect(result.style.color).toBe('red');
    });

    it('shows error message on fetch failure', async () => {
        global.fetch.mockRejectedValueOnce(new Error('network'));
        document.getElementById('rr-test-lambda').click();
        await flushPromises();
        const result = document.getElementById('rr-lambda-test-result');
        expect(result.textContent).toContain('✗');
        expect(result.style.color).toBe('red');
    });

    it('sends correct parameters to fetch', () => {
        global.fetch = jest.fn(() => new Promise(() => {}));
        document.getElementById('rr-test-lambda').click();
        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe(rrAdmin.ajaxurl);
        expect(opts.method).toBe('POST');
        const body = Object.fromEntries(opts.body);
        expect(body.action).toBe('restart_registry_test_lambda');
        expect(body.nonce).toBe('test-nonce');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Test Affiliate Conversion
// ─────────────────────────────────────────────────────────────────────────────

describe('Affiliate link reconversion', () => {
    it('disables the button and shows loading text when clicked', () => {
        global.fetch = jest.fn(() => new Promise(() => {}));
        document.getElementById('rr-reconvert-affiliates').click();
        expect(document.getElementById('rr-reconvert-affiliates').disabled).toBe(true);
        expect(document.getElementById('rr-reconvert-result').textContent).toContain('Processing');
    });

    it('shows success message on successful conversion', async () => {
        global.fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { message: 'Converted 5 links' } }),
        });
        document.getElementById('rr-reconvert-affiliates').click();
        await flushPromises();
        const result = document.getElementById('rr-reconvert-result');
        expect(result.textContent).toContain('✓');
        expect(result.textContent).toContain('Converted 5 links');
        expect(result.style.color).toBe('green');
    });

    it('shows error message on failed conversion', async () => {
        global.fetch.mockResolvedValueOnce({
            json: async () => ({ success: false, data: { message: 'Conversion failed' } }),
        });
        document.getElementById('rr-reconvert-affiliates').click();
        await flushPromises();
        const result = document.getElementById('rr-reconvert-result');
        expect(result.textContent).toContain('✗');
        expect(result.style.color).toBe('red');
    });

    it('shows error message on fetch failure', async () => {
        global.fetch.mockRejectedValueOnce(new Error('network'));
        document.getElementById('rr-reconvert-affiliates').click();
        await flushPromises();
        const result = document.getElementById('rr-reconvert-result');
        expect(result.textContent).toContain('✗');
        expect(result.style.color).toBe('red');
    });

    it('sends correct parameters to fetch', () => {
        global.fetch = jest.fn(() => new Promise(() => {}));
        document.getElementById('rr-reconvert-affiliates').click();
        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe(rrAdmin.ajaxurl);
        expect(opts.method).toBe('POST');
        const body = Object.fromEntries(opts.body);
        expect(body.action).toBe('restart_registry_reconvert_affiliates');
        expect(body.nonce).toBe('test-nonce');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Custom Retailers Management
// ─────────────────────────────────────────────────────────────────────────────

describe('Custom retailers table', () => {
    it('initializes with one retailer row', () => {
        expect(document.querySelectorAll('#rr-custom-retailers-body .rr-custom-retailer-row').length).toBe(1);
    });

    it('has correct structure for initial row', () => {
        const row = document.querySelector('#rr-custom-retailers-body .rr-custom-retailer-row');
        expect(row.querySelectorAll('input').length).toBe(5);
    });

    it('has remove button in each row', () => {
        expect(document.querySelectorAll('#rr-custom-retailers-body .rr-remove-retailer').length).toBeGreaterThan(0);
    });

    it('preserves field values in initial row', () => {
        const input = document.querySelector('#rr-custom-retailers-body input[name*="[0][name]"]');
        expect(input.value).toBe('Test Retailer');
    });

    it('has all required input fields in row', () => {
        const row = document.querySelector('#rr-custom-retailers-body .rr-custom-retailer-row');
        expect(row.querySelector('input[name*="[name]"]')).not.toBeNull();
        expect(row.querySelector('input[name*="[domains]"]')).not.toBeNull();
        expect(row.querySelector('input[name*="[template]"]')).not.toBeNull();
    });

    it('add retailer button is present', () => {
        const btn = document.getElementById('rr-add-retailer');
        expect(btn).not.toBeNull();
        expect(btn.textContent).toContain('Add');
    });

    it('retailer table body exists', () => {
        expect(document.getElementById('rr-custom-retailers-body')).not.toBeNull();
    });

    it('initial row has correct index pattern in field names', () => {
        expect(document.querySelector('#rr-custom-retailers-body input[name*="[0][name]"]')).not.toBeNull();
    });

    it('adds a new row when add button is clicked', () => {
        document.getElementById('rr-add-retailer').click();
        expect(document.querySelectorAll('#rr-custom-retailers-body .rr-custom-retailer-row').length).toBe(2);
    });

    it('new row uses the correct index', () => {
        document.getElementById('rr-add-retailer').click();
        expect(document.querySelector('#rr-custom-retailers-body input[name*="[1][name]"]')).not.toBeNull();
    });

    it('removes a row when remove button is clicked', () => {
        document.getElementById('rr-add-retailer').click();
        expect(document.querySelectorAll('#rr-custom-retailers-body .rr-custom-retailer-row').length).toBe(2);
        document.querySelector('#rr-custom-retailers-body .rr-remove-retailer').click();
        expect(document.querySelectorAll('#rr-custom-retailers-body .rr-custom-retailer-row').length).toBe(1);
    });

    it('renumbers rows after removal', () => {
        document.getElementById('rr-add-retailer').click();
        document.getElementById('rr-add-retailer').click();
        // Remove the first row
        document.querySelector('#rr-custom-retailers-body .rr-remove-retailer').click();
        // Remaining rows should be renumbered starting at 0
        const inputs = document.querySelectorAll('#rr-custom-retailers-body .rr-custom-retailer-row input');
        inputs.forEach(input => {
            expect(input.name).toMatch(/\[0\]|\[1\]/);
        });
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Edge Cases
// ─────────────────────────────────────────────────────────────────────────────

describe('Admin UI edge cases', () => {
    it('does not crash when test lambda button is clicked', () => {
        global.fetch = jest.fn(() => new Promise(() => {}));
        expect(() => {
            document.getElementById('rr-test-lambda').click();
        }).not.toThrow();
    });

    it('disables button during in-flight request (debounce-like)', () => {
        global.fetch = jest.fn(() => new Promise(() => {}));
        document.getElementById('rr-test-lambda').click();
        document.getElementById('rr-test-lambda').click(); // second click on disabled btn
        expect(global.fetch).toHaveBeenCalledTimes(1); // second click ignored
    });

    it('maintains custom retailer data when adding rows', () => {
        const input = document.querySelector('#rr-custom-retailers-body input[name*="[0][name]"]');
        input.value = 'My Store';
        document.getElementById('rr-add-retailer').click();
        expect(document.querySelector('#rr-custom-retailers-body input[name*="[0][name]"]').value).toBe('My Store');
    });

    it('re-enables the button after a completed request', async () => {
        global.fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { message: 'OK' } }),
        });
        document.getElementById('rr-test-lambda').click();
        await flushPromises();
        expect(document.getElementById('rr-test-lambda').disabled).toBe(false);
    });
});
