const flushPromises = () => new Promise(process.nextTick);

describe('start-registry.js', () => {
    const setupDom = () => {
        document.body.innerHTML = `
            <form id="restart-registry-form">
                <input id="registry-title" name="title" />
                <select id="event-type" name="event_type">
                    <option value=""></option>
                    <option value="divorce">Divorce</option>
                </select>
                <input id="event-date" name="event_date" />
                <textarea id="registry-story" name="story"></textarea>
                <input type="checkbox" id="is-private" name="is_private" />
                <div id="invitees-group" hidden>
                    <input id="invitees" name="invitees" />
                </div>
                <div id="restart-form-error" hidden></div>
                <button type="submit">Create Registry</button>
            </form>
        `;
        window.restartRegistry = {
            lambdaUrl: 'http://localhost:5000',
            username: 'testuser',
            apiKey: 'test-key',
            myAccountUrl: '/my-account/',
            navigate: jest.fn(),
        };
    };

    const loadScript = () => {
        jest.resetModules();
        require('../../assets/js/start-registry.js');
    };

    beforeEach(() => {
        setupDom();
        global.fetch = jest.fn();
    });

    afterEach(() => {
        jest.restoreAllMocks();
        delete global.fetch;
    });

    test('shows error when title is empty', () => {
        loadScript();

        const form = document.getElementById('restart-registry-form');
        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        const errorBox = document.getElementById('restart-form-error');
        expect(errorBox.hidden).toBe(false);
        expect(errorBox.textContent).toMatch(/Registry Name is required/);
    });

    test('hides invitees group by default', () => {
        loadScript();
        expect(document.getElementById('invitees-group').hidden).toBe(true);
    });

    test('shows invitees group when private is checked', () => {
        loadScript();

        const toggle = document.getElementById('is-private');
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));

        expect(document.getElementById('invitees-group').hidden).toBe(false);
    });

    test('clears invitees when private unchecked', () => {
        loadScript();

        const toggle = document.getElementById('is-private');
        const invitees = document.getElementById('invitees');
        invitees.value = 'someone';
        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));

        expect(invitees.value).toBe('');
        expect(document.getElementById('invitees-group').hidden).toBe(true);
    });

    test('calls fetch with correct payload on valid submit', async () => {
        global.fetch.mockResolvedValue({ ok: true });
        loadScript();

        document.getElementById('registry-title').value = 'My Registry';

        const form = document.getElementById('restart-registry-form');
        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        await flushPromises();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe(restartRegistry.ajaxUrl + '?action=restart_create_registry');
        expect(opts.method).toBe('POST');
        // expect(opts.headers.Authorization).toMatch(/^Basic /);
    });

    test('redirects to URL returned by server on success', async () => {
        const serverUrl = '/registry/my-registry/';
        global.fetch.mockResolvedValue({
            ok: true,
            status: 201,
            json: async () => ({ data: { url: serverUrl } }),
        });
        loadScript();

        document.getElementById('registry-title').value = 'My Registry';
        const form = document.getElementById('restart-registry-form');
        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        await flushPromises();

        expect(window.restartRegistry.navigate).toHaveBeenCalledWith(serverUrl);
    });

    test('shows error on non-ok response', async () => {
        global.fetch.mockResolvedValue({
            ok: false,
            status: 500,
            json: async () => ({ detail: 'Server error' }),
        });
        loadScript();

        document.getElementById('registry-title').value = 'My Registry';
        const form = document.getElementById('restart-registry-form');
        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        await flushPromises();

        const errorBox = document.getElementById('restart-form-error');
        expect(errorBox.hidden).toBe(false);
        expect(errorBox.textContent).toMatch(/Server error/);
    });

    test('shows generic error on fetch failure', async () => {
        global.fetch.mockRejectedValue(new Error('network down'));
        loadScript();

        document.getElementById('registry-title').value = 'My Registry';
        const form = document.getElementById('restart-registry-form');
        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        await flushPromises();

        const errorBox = document.getElementById('restart-form-error');
        expect(errorBox.hidden).toBe(false);
        expect(errorBox.textContent).toMatch(/Could not reach the server/);
    });
});
