describe('contact-modal.js', () => {
    const setupDom = ({ withForm = false } = {}) => {
        const formHtml = withForm ? `
            <form id="rr-contact-form">
                <input name="name" value="Alex">
                <input type="email" name="email" value="alex@example.com">
                <input name="subject" value="">
                <textarea name="message">Hello there</textarea>
                <input name="website" value="">
                <input type="hidden" name="_nonce" value="testnonce">
                <button type="submit">Send</button>
                <div class="rr-contact-form__status" role="status" aria-live="polite"></div>
            </form>
        ` : '';

        document.body.innerHTML = `
            <a href="#contact" id="contact-link">Contact</a>
            <div id="rr-contact-modal" hidden>
                <div class="rr-modal__overlay"></div>
                <div class="rr-modal__dialog">
                    <button class="rr-modal__close">&times;</button>
                    ${formHtml}
                </div>
            </div>
        `;
    };

    const loadScript = () => {
        jest.resetModules();
        require('../../assets/js/contact-modal.js');
    };

    beforeEach(() => {
        setupDom();
        window.restartContact = { ajaxUrl: '/wp-admin/admin-ajax.php' };
    });

    afterEach(() => {
        document.body.className = '';
        delete global.fetch;
    });

    test('opens modal when contact link clicked', () => {
        loadScript();

        document.getElementById('contact-link').click();

        const modal = document.getElementById('rr-contact-modal');
        expect(modal.hasAttribute('hidden')).toBe(false);
        expect(modal.classList.contains('is-open')).toBe(true);
    });

    test('closes modal when overlay clicked', () => {
        loadScript();

        document.getElementById('contact-link').click();
        document.querySelector('.rr-modal__overlay').click();

        const modal = document.getElementById('rr-contact-modal');
        expect(modal.hasAttribute('hidden')).toBe(true);
        expect(modal.classList.contains('is-open')).toBe(false);
    });

    test('closes modal when close button clicked', () => {
        loadScript();

        document.getElementById('contact-link').click();
        document.querySelector('.rr-modal__close').click();

        const modal = document.getElementById('rr-contact-modal');
        expect(modal.hasAttribute('hidden')).toBe(true);
    });

    test('closes modal on Escape key', () => {
        loadScript();

        document.getElementById('contact-link').click();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        const modal = document.getElementById('rr-contact-modal');
        expect(modal.hasAttribute('hidden')).toBe(true);
    });

    test('does not open modal if element missing', () => {
        document.body.innerHTML = '';

        expect(() => loadScript()).not.toThrow();
    });

    describe('form submission', () => {
        const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0));

        beforeEach(() => {
            setupDom({ withForm: true });
        });

        test('shows success message and auto-closes after 3s', async () => {
            jest.useFakeTimers({ doNotFake: ['setTimeout'] });
            try {
                global.fetch = jest.fn(() => Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({ success: true, data: { message: 'Thanks!' } })
                }));
                loadScript();

                document.getElementById('contact-link').click();
                document.getElementById('rr-contact-form').dispatchEvent(new Event('submit'));

                await flushPromises();

                const status = document.querySelector('.rr-contact-form__status');
                expect(status.textContent).toBe('Thanks!');
                expect(status.classList.contains('is-success')).toBe(true);

                // The setTimeout(closeModal, 3000) still runs on real timers since we exempted it.
                await new Promise((resolve) => setTimeout(resolve, 3050));

                const modal = document.getElementById('rr-contact-modal');
                expect(modal.hasAttribute('hidden')).toBe(true);
            } finally {
                jest.useRealTimers();
            }
        }, 5000);

        test('sends correct payload to admin-ajax', async () => {
            global.fetch = jest.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ success: true, data: {} })
            }));
            loadScript();

            document.getElementById('contact-link').click();
            document.getElementById('rr-contact-form').dispatchEvent(new Event('submit'));

            await flushPromises();

            expect(fetch).toHaveBeenCalledWith('/wp-admin/admin-ajax.php', expect.objectContaining({
                method: 'POST',
                credentials: 'same-origin',
            }));
            const body = fetch.mock.calls[0][1].body;
            expect(body.get('action')).toBe('restart_contact_submit');
            expect(body.get('name')).toBe('Alex');
            expect(body.get('email')).toBe('alex@example.com');
            expect(body.get('_nonce')).toBe('testnonce');
        });

        test('shows field errors and re-enables submit on validation error', async () => {
            global.fetch = jest.fn(() => Promise.resolve({
                ok: false,
                json: () => Promise.resolve({
                    success: false,
                    data: { errors: { email: 'A valid email is required.' } }
                })
            }));
            loadScript();

            document.getElementById('contact-link').click();
            document.getElementById('rr-contact-form').dispatchEvent(new Event('submit'));

            await flushPromises();

            const status = document.querySelector('.rr-contact-form__status');
            expect(status.textContent).toContain('valid email');
            expect(status.classList.contains('is-error')).toBe(true);
            const submit = document.querySelector('button[type="submit"]');
            expect(submit.disabled).toBe(false);
        });

        test('shows network error if fetch rejects', async () => {
            global.fetch = jest.fn(() => Promise.reject(new Error('boom')));
            loadScript();

            document.getElementById('contact-link').click();
            document.getElementById('rr-contact-form').dispatchEvent(new Event('submit'));

            await flushPromises();

            const status = document.querySelector('.rr-contact-form__status');
            expect(status.classList.contains('is-error')).toBe(true);
            expect(status.textContent).toMatch(/network/i);
        });
    });
});
