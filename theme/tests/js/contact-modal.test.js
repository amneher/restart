describe('contact-modal.js', () => {
    const setupDom = () => {
        document.body.innerHTML = `
            <a href="#contact" id="contact-link">Contact</a>
            <div id="rr-contact-modal" hidden>
                <div class="rr-modal__overlay"></div>
                <div class="rr-modal__dialog">
                    <button class="rr-modal__close">&times;</button>
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
    });

    afterEach(() => {
        document.body.className = '';
    });

    test('opens modal when contact link clicked', () => {
        loadScript();

        document.getElementById('contact-link').click();

        const modal = document.getElementById('rr-contact-modal');
        expect(modal.hasAttribute('hidden')).toBe(false);
    });

    test('closes modal when overlay clicked', () => {
        loadScript();

        document.getElementById('contact-link').click();
        document.querySelector('.rr-modal__overlay').click();

        const modal = document.getElementById('rr-contact-modal');
        expect(modal.hasAttribute('hidden')).toBe(true);
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
});
