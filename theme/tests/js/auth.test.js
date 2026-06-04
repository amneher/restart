'use strict';

const flushPromises = () => new Promise(process.nextTick);

// Globals the script expects
global.restartAuth = {
    ajaxUrl: '/ajax',
    registerNonce: 'reg-nonce',
    deactivateAccountNonce: 'deactivate-nonce',
    deleteAccountNonce: 'delete-nonce',
};

delete window.location;
window.location = { href: '' };

// auth.js uses event delegation on document so handlers survive DOM rebuilds.
// Load once; reset DOM + fetch mock in beforeEach.
beforeAll(() => {
    require('../../assets/js/auth.js');
});

beforeEach(() => {
    global.fetch = jest.fn();
    document.body.innerHTML = '';
});

afterEach(() => {
    jest.restoreAllMocks();
    delete global.fetch;
});

// ── Registration form ──────────────────────────────────────────────────────────

describe('register form', () => {
    const setupDom = () => {
        document.body.innerHTML = `
            <form id="rr-register-form">
                <input name="username" value="alex" />
                <input name="email" value="alex@example.com" />
                <input name="password" value="hunter2hunter2" />
                <button id="rr-register-submit">Create Account</button>
            </form>
            <div id="rr-register-error" style="display:none"></div>
        `;
    };

    test('calls fetch with correct data on submit', () => {
        global.fetch.mockResolvedValue({ json: async () => ({ success: true, data: { redirect: '/my-account/' } }) });
        setupDom();

        document.getElementById('rr-register-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe('/ajax');
        const body = Object.fromEntries(opts.body);
        expect(body.action).toBe('restart_register');
        expect(body.username).toBe('alex');
        expect(body.email).toBe('alex@example.com');
        expect(body.password).toBe('hunter2hunter2');
        expect(body.nonce).toBe('reg-nonce');
    });

    test('shows error on failure response', async () => {
        global.fetch.mockResolvedValue({
            json: async () => ({ success: false, data: { message: 'Username taken' } }),
        });
        setupDom();

        document.getElementById('rr-register-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );
        await flushPromises();

        const err = document.getElementById('rr-register-error');
        expect(err.textContent).toBe('Username taken');
        expect(err.style.display).not.toBe('none');
    });

    test('shows generic error on fetch failure', async () => {
        global.fetch.mockRejectedValue(new Error('network'));
        setupDom();

        document.getElementById('rr-register-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );
        await flushPromises();

        const err = document.getElementById('rr-register-error');
        expect(err.textContent).toMatch(/Something went wrong/);
        expect(err.style.display).not.toBe('none');
    });
});

// ── Profile edit toggle ────────────────────────────────────────────────────────

describe('profile toggle', () => {
    test('shows panel when toggle is clicked', () => {
        document.body.innerHTML = `
            <a id="rr-edit-profile-toggle" href="#edit-profile">Edit Profile</a>
            <div id="rr-edit-profile-panel" hidden></div>
        `;

        document.getElementById('rr-edit-profile-toggle').click();

        expect(document.getElementById('rr-edit-profile-panel').hidden).toBe(false);
    });

    test('hides panel when cancel is clicked', () => {
        jest.useFakeTimers();
        document.body.innerHTML = `
            <a id="rr-edit-profile-toggle" href="#">Edit</a>
            <div id="rr-edit-profile-panel" hidden></div>
            <button id="rr-edit-profile-cancel">Cancel</button>
        `;
        // Open it first
        document.getElementById('rr-edit-profile-toggle').click();
        expect(document.getElementById('rr-edit-profile-panel').hidden).toBe(false);
        // Then cancel — hidden is restored inside a setTimeout after the CSS transition
        document.getElementById('rr-edit-profile-cancel').click();
        jest.runAllTimers();
        expect(document.getElementById('rr-edit-profile-panel').hidden).toBe(true);
        jest.useRealTimers();
    });
});

// ── Profile form ───────────────────────────────────────────────────────────────

describe('profile form', () => {
    test('calls fetch on submit with correct data', () => {
        document.body.innerHTML = `
            <form id="rr-profile-form">
                <input id="rr-profile-nonce" value="profile-nonce-val" />
                <input name="display_name" value="Alex" />
                <input name="email" value="alex@example.com" />
                <input name="password" value="" />
                <button id="rr-profile-save">Save Changes</button>
            </form>
            <div id="rr-profile-error" style="display:none"></div>
            <div id="rr-profile-message" style="display:none"></div>
        `;
        global.fetch.mockResolvedValue({ json: async () => ({ success: true, data: { message: 'Saved' } }) });

        document.getElementById('rr-profile-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe('/ajax');
        const body = Object.fromEntries(opts.body);
        expect(body.action).toBe('restart_update_profile');
        expect(body.display_name).toBe('Alex');
        expect(body.email).toBe('alex@example.com');
        expect(body.nonce).toBe('profile-nonce-val');
    });

    test('shows success message after save', async () => {
        document.body.innerHTML = `
            <form id="rr-profile-form">
                <input id="rr-profile-nonce" value="nonce" />
                <input name="display_name" value="Alex" />
                <input name="email" value="alex@example.com" />
                <input name="password" value="" />
                <button id="rr-profile-save">Save</button>
            </form>
            <div id="rr-profile-error" style="display:none"></div>
            <div id="rr-profile-message" style="display:none"></div>
        `;
        global.fetch.mockResolvedValue({ json: async () => ({ success: true, data: { message: 'Profile saved.' } }) });

        document.getElementById('rr-profile-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );
        await flushPromises();

        const msg = document.getElementById('rr-profile-message');
        expect(msg.textContent).toBe('Profile saved.');
        expect(msg.style.display).not.toBe('none');
    });

    test('shows error on failed profile update', async () => {
        document.body.innerHTML = `
            <form id="rr-profile-form">
                <input id="rr-profile-nonce" value="nonce" />
                <input name="display_name" value="Alex" />
                <input name="email" value="alex@example.com" />
                <input name="password" value="" />
                <button id="rr-profile-save">Save</button>
            </form>
            <div id="rr-profile-error" style="display:none"></div>
            <div id="rr-profile-message" style="display:none"></div>
        `;
        global.fetch.mockResolvedValue({
            json: async () => ({ success: false, data: { message: 'Email already in use.' } }),
        });

        document.getElementById('rr-profile-form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );
        await flushPromises();

        const err = document.getElementById('rr-profile-error');
        expect(err.textContent).toBe('Email already in use.');
        expect(err.style.display).not.toBe('none');
    });
});

// ── Deactivate account ─────────────────────────────────────────────────────────

describe('deactivate account flow', () => {
    const setupDom = () => {
        document.body.innerHTML = `
            <button id="rr-deactivate-account-btn">Deactivate</button>
            <div class="restart-modal" id="rr-deactivate-confirm-modal" hidden>
                <button id="rr-deactivate-confirm-btn">Confirm</button>
                <p id="rr-deactivate-error" hidden></p>
            </div>
        `;
    };

    test('opens deactivate modal when button is clicked', () => {
        setupDom();
        document.getElementById('rr-deactivate-account-btn').click();
        expect(document.getElementById('rr-deactivate-confirm-modal').hidden).toBe(false);
    });

    test('calls fetch with correct action on confirm', async () => {
        setupDom();
        global.fetch.mockResolvedValue({
            json: async () => ({ success: true, data: { redirect: '/goodbye/' } }),
        });
        document.getElementById('rr-deactivate-account-btn').click();
        document.getElementById('rr-deactivate-confirm-btn').click();
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledTimes(1);
        const body = Object.fromEntries(global.fetch.mock.calls[0][1].body);
        expect(body.action).toBe('restart_deactivate_account');
        expect(body.nonce).toBe('deactivate-nonce');
    });

    test('shows error when deactivation fails', async () => {
        setupDom();
        global.fetch.mockResolvedValue({
            json: async () => ({ success: false, data: { message: 'Cannot deactivate.' } }),
        });
        document.getElementById('rr-deactivate-account-btn').click();
        document.getElementById('rr-deactivate-confirm-btn').click();
        await flushPromises();
        const err = document.getElementById('rr-deactivate-error');
        expect(err.hidden).toBe(false);
        expect(err.textContent).toBe('Cannot deactivate.');
    });
});

// ── Delete account ─────────────────────────────────────────────────────────────

describe('delete account flow', () => {
    const setupDom = () => {
        document.body.innerHTML = `
            <button id="rr-delete-account-btn">Delete Account</button>
            <div class="restart-modal" id="rr-delete-account-modal" hidden>
                <input type="password" id="rr-delete-account-password" />
                <input type="checkbox" id="rr-delete-account-understand" />
                <button id="rr-delete-account-confirm-btn" disabled>Confirm Delete</button>
                <p id="rr-delete-account-error" hidden></p>
            </div>
        `;
    };

    test('opens delete modal and resets fields', () => {
        setupDom();
        document.getElementById('rr-delete-account-password').value = 'old';
        document.getElementById('rr-delete-account-understand').checked = true;
        document.getElementById('rr-delete-account-btn').click();
        expect(document.getElementById('rr-delete-account-modal').hidden).toBe(false);
        expect(document.getElementById('rr-delete-account-password').value).toBe('');
        expect(document.getElementById('rr-delete-account-understand').checked).toBe(false);
        expect(document.getElementById('rr-delete-account-confirm-btn').disabled).toBe(true);
    });

    test('enables confirm button when understand checkbox is checked', () => {
        setupDom();
        const cb = document.getElementById('rr-delete-account-understand');
        cb.checked = true;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
        expect(document.getElementById('rr-delete-account-confirm-btn').disabled).toBe(false);
    });

    test('calls fetch with correct action on confirm', async () => {
        setupDom();
        global.fetch.mockResolvedValue({
            json: async () => ({ success: true, data: { redirect: '/bye/' } }),
        });
        document.getElementById('rr-delete-account-password').value = 'mypassword';
        document.getElementById('rr-delete-account-confirm-btn').disabled = false;
        document.getElementById('rr-delete-account-confirm-btn').click();
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledTimes(1);
        const body = Object.fromEntries(global.fetch.mock.calls[0][1].body);
        expect(body.action).toBe('restart_delete_account');
        expect(body.nonce).toBe('delete-nonce');
        expect(body.password).toBe('mypassword');
    });
});
