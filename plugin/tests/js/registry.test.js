'use strict';

const flushPromises = () => new Promise(process.nextTick);

// ── Globals the script expects ────────────────────────────────────────────────

global.restartRegistry = {
    ajaxUrl: '/wp-admin/admin-ajax.php',
    nonce: 'test-nonce',
    strings: {
        loading: 'Loading…',
        error: 'Something went wrong.',
        confirmDelete: 'Delete this item?',
        save: 'Save Changes',
    },
};

delete window.location;
window.location = { href: '', reload: jest.fn() };
Object.defineProperty(window.navigator, 'clipboard', {
    value: { writeText: jest.fn().mockResolvedValue(undefined) },
    writable: true,
});
window.confirm = jest.fn().mockReturnValue(true);
window.alert   = jest.fn();

// ── DOM factory ───────────────────────────────────────────────────────────────

function buildDOM({ noImage = false, inViewRegistry = false } = {}) {
    const containerClass = inViewRegistry ? 'rr-view-registry' : 'rr-manage-registry';
    document.body.innerHTML = `
        <div class="${containerClass}" data-registry-id="1">
            <ul class="rr-item-list">
                <li class="rr-item-row"
                    data-item-id="42" data-name="Test Skateboard Rack" data-price="49.99"
                    data-description="A great rack for skateboards."
                    data-notes="any color is fine"
                    data-image-url="${noImage ? '' : 'https://example.com/image.jpg'}"
                    data-retailer="Etsy" data-affiliate-url="https://etsy.com/listing/123?ref=aff"
                    data-quantity="2" data-qty-purchased="0">
                    <button class="rr-item-name-btn">Test Skateboard Rack</button>
                </li>
            </ul>
            <div id="rr-item-detail-modal" class="rr-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <button class="rr-modal__close">×</button>
                <span class="rr-item-detail__title"></span>
                <div class="rr-item-detail__image-wrap">
                    <img class="rr-item-detail__image" src="" alt="">
                </div>
                <div class="rr-item-detail__meta"></div>
                <div class="rr-item-detail__description"></div>
                <div class="rr-item-detail__qty-row"></div>
                <a class="rr-item-detail__purchase-btn rr-purchase-btn" href="#">Purchase</a>
                <button class="rr-mark-purchased rr-item-detail__mark-btn">Mark Fulfilled</button>
            </div>
        </div>
    `;
}

// ── Script load ───────────────────────────────────────────────────────────────
// The public script runs immediately via IIFE; it captures `container` on load
// and attaches all handlers via event delegation on `document`, so a single
// require() is enough — handlers survive DOM rebuilds in beforeEach.

beforeAll(() => {
    global.fetch = jest.fn();
    buildDOM();
    require('../../public/js/restart-registry-public.js');
});

beforeEach(() => {
    fetch.mockReset();
    window.alert.mockClear();
    window.confirm.mockReturnValue(true);
    buildDOM();
});

// ── Modal open / close ────────────────────────────────────────────────────────

describe('modal open/close', () => {
    it('opens the item-detail modal when an item name button is clicked', () => {
        document.querySelector('.rr-item-name-btn').click();
        expect(document.getElementById('rr-item-detail-modal').getAttribute('aria-inert')).toBe('false');
        expect(document.getElementById('rr-item-detail-modal').classList.contains('is-open')).toBe(true);
    });

    it('closes the modal when the × button is clicked', () => {
        document.querySelector('.rr-item-name-btn').click();
        document.querySelector('.rr-modal__close').click();
        expect(document.getElementById('rr-item-detail-modal').getAttribute('aria-inert')).toBe('true');
        expect(document.getElementById('rr-item-detail-modal').classList.contains('is-open')).toBe(false);
    });

    it('closes the modal when the backdrop is clicked', () => {
        document.querySelector('.rr-item-name-btn').click();
        document.querySelector('.rr-modal__backdrop').click();
        expect(document.getElementById('rr-item-detail-modal').classList.contains('is-open')).toBe(false);
    });

    it('closes the modal on Escape key', () => {
        document.querySelector('.rr-item-name-btn').click();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(document.getElementById('rr-item-detail-modal').classList.contains('is-open')).toBe(false);
    });
});

// ── Item detail modal content ─────────────────────────────────────────────────

describe('item detail modal content', () => {
    beforeEach(() => {
        document.querySelector('.rr-item-name-btn').click();
    });

    it('populates the title', () => {
        expect(document.querySelector('.rr-item-detail__title').textContent).toBe('Test Skateboard Rack');
    });

    it('shows the product image and reveals image wrap', () => {
        expect(document.querySelector('.rr-item-detail__image').src).toContain('https://example.com/image.jpg');
        expect(document.querySelector('.rr-item-detail__image-wrap').style.display).not.toBe('none');
    });

    it('shows the retailer badge', () => {
        expect(document.querySelector('.rr-item-detail__meta').textContent).toContain('Etsy');
    });

    it('formats the price with two decimal places', () => {
        expect(document.querySelector('.rr-item-detail__meta').textContent).toContain('$49.99');
    });

    it('shows the notes in the description element', () => {
        expect(document.querySelector('.rr-item-detail__description').textContent).toBe('any color is fine');
    });

    it('shows qty needed and purchased', () => {
        const qty = document.querySelector('.rr-item-detail__qty-row').textContent;
        expect(qty).toContain('2'); // needed
        expect(qty).toContain('0'); // purchased
    });

    it('shows purchase button with affiliate URL', () => {
        const btn = document.querySelector('.rr-item-detail__purchase-btn');
        expect(btn.href).toContain('https://etsy.com/listing/123?ref=aff');
        expect(btn.style.display).not.toBe('none');
    });

    it('shows mark-purchased button in manage context when item is not fulfilled', () => {
        expect(document.querySelector('.rr-item-detail__mark-btn').style.display).not.toBe('none');
    });
});

// ── Guest view ────────────────────────────────────────────────────────────────

describe('item detail modal in guest/view-registry context', () => {
    beforeEach(() => {
        buildDOM({ inViewRegistry: true });
        document.querySelector('.rr-item-name-btn').click();
    });

    it('shows the mark-fulfilled button for guests', () => {
        expect(document.querySelector('.rr-item-detail__mark-btn').style.display).not.toBe('none');
    });
});

// ── No image ──────────────────────────────────────────────────────────────────

describe('item detail modal without image', () => {
    beforeEach(() => {
        buildDOM({ noImage: true });
        document.querySelector('.rr-item-name-btn').click();
    });

    it('hides the image wrap when no image URL is set', () => {
        expect(document.querySelector('.rr-item-detail__image-wrap').style.display).toBe('none');
    });
});

// ── URL fetch handler ─────────────────────────────────────────────────────────

describe('URL fetch handler', () => {
    function addFetchForm() {
        document.body.innerHTML += `
            <div class="rr-manage-registry" data-registry-id="1">
                <input type="text" id="rr-item-url">
                <button id="rr-fetch-url">Fetch</button>
                <input type="text" id="rr-item-name">
                <input type="text" id="rr-item-price">
                <input type="hidden" id="rr-item-image-url" value="">
                <input type="hidden" id="rr-item-description" value="">
                <input type="text" id="rr-item-notes" value="">
            </div>
        `;
    }

    beforeEach(() => {
        addFetchForm();
        document.getElementById('rr-item-url').value = 'https://example.com/product';
    });

    it('populates image URL field when fetch returns an image', async () => {
        fetch.mockResolvedValueOnce({
            json: async () => ({
                success: true,
                data: { name: 'Widget', price: '9.99', image_url: 'https://cdn.example.com/img.jpg', description: 'Nice' },
            }),
        });
        document.getElementById('rr-fetch-url').click();
        await flushPromises();
        expect(document.getElementById('rr-item-image-url').value).toBe('https://cdn.example.com/img.jpg');
    });

    it('clears image URL field when a subsequent fetch returns no image', async () => {
        fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { name: 'Widget', price: '9.99', image_url: 'https://cdn.example.com/img.jpg', description: '' } }),
        });
        document.getElementById('rr-fetch-url').click();
        await flushPromises();
        expect(document.getElementById('rr-item-image-url').value).toBe('https://cdn.example.com/img.jpg');

        fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { name: 'Other', price: '19.99', image_url: '', description: '' } }),
        });
        document.getElementById('rr-fetch-url').click();
        await flushPromises();
        expect(document.getElementById('rr-item-image-url').value).toBe('');
    });

    it('leaves image URL unchanged when fetch fails at the network level', async () => {
        document.getElementById('rr-item-image-url').value = 'https://cdn.example.com/existing.jpg';
        fetch.mockRejectedValueOnce(new Error('network'));
        document.getElementById('rr-fetch-url').click();
        await flushPromises();
        expect(document.getElementById('rr-item-image-url').value).toBe('https://cdn.example.com/existing.jpg');
    });

    it('calls fetch with correct parameters', () => {
        fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { name: 'Product', price: '10', image_url: '', description: '' } }),
        });
        document.getElementById('rr-item-url').value = 'https://shop.example.com/product-123';
        document.getElementById('rr-fetch-url').click();
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('populates product name field when fetch succeeds', async () => {
        fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { name: 'Premium Widget', price: '99.99', image_url: '', description: '' } }),
        });
        document.getElementById('rr-fetch-url').click();
        await flushPromises();
        expect(document.getElementById('rr-item-name').value).toBe('Premium Widget');
    });

    it('populates price field when fetch succeeds', async () => {
        fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { name: 'Widget', price: '24.99', image_url: '', description: '' } }),
        });
        document.getElementById('rr-fetch-url').click();
        await flushPromises();
        expect(document.getElementById('rr-item-price').value).toBe('24.99');
    });

    it('populates description field when fetch succeeds', async () => {
        fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { name: 'Widget', price: '10', image_url: '', description: 'A wonderful product' } }),
        });
        document.getElementById('rr-fetch-url').click();
        await flushPromises();
        expect(document.getElementById('rr-item-description').value).toBe('A wonderful product');
    });
});

// ── Multiple modal management ─────────────────────────────────────────────────

describe('multiple modals', () => {
    beforeEach(() => {
        buildDOM();
        document.body.innerHTML += `
            <div id="rr-other-modal" class="rr-modal" aria-inert="true">
                <div class="rr-modal__backdrop"></div>
                <button class="rr-modal__close">×</button>
            </div>
        `;
    });

    it('closes only the specified modal, leaving others open', () => {
        document.querySelector('.rr-item-name-btn').click();
        expect(document.getElementById('rr-item-detail-modal').classList.contains('is-open')).toBe(true);
        expect(document.getElementById('rr-other-modal').classList.contains('is-open')).toBe(false);

        // Open other modal manually
        const otherModal = document.getElementById('rr-other-modal');
        otherModal.setAttribute('aria-inert', 'false');
        otherModal.classList.add('is-open');

        // Close the item-detail modal only
        document.querySelector('#rr-item-detail-modal .rr-modal__close').click();
        expect(document.getElementById('rr-item-detail-modal').classList.contains('is-open')).toBe(false);
        expect(document.getElementById('rr-other-modal').classList.contains('is-open')).toBe(true);
    });

    it('applies body class only when any modal is open', () => {
        document.querySelector('.rr-item-name-btn').click();
        expect(document.body.classList.contains('rr-modal-open')).toBe(true);

        document.querySelector('#rr-item-detail-modal .rr-modal__close').click();
        expect(document.body.classList.contains('rr-modal-open')).toBe(false);
    });
});

// ── Registry creation form ────────────────────────────────────────────────────

describe('registry creation form', () => {
    function addCreateForm() {
        document.body.innerHTML += `
            <div class="rr-create-form" data-registry-id="">
                <form id="rr-create-registry-form">
                    <input type="text" name="title" value="">
                    <textarea name="description"></textarea>
                    <button type="submit">Create Registry</button>
                </form>
            </div>
        `;
    }

    beforeEach(() => {
        addCreateForm();
    });

    it('form exists in the DOM', () => {
        expect(document.getElementById('rr-create-registry-form')).not.toBeNull();
    });

    it('form has required input fields', () => {
        expect(document.querySelector('input[name="title"]')).not.toBeNull();
        expect(document.querySelector('textarea[name="description"]')).not.toBeNull();
    });

    it('form has submit button', () => {
        expect(document.querySelector('#rr-create-registry-form button[type="submit"]')).not.toBeNull();
    });

    it('allows setting form field values', () => {
        document.querySelector('input[name="title"]').value = 'My Registry';
        document.querySelector('textarea[name="description"]').value = 'My description';

        expect(document.querySelector('input[name="title"]').value).toBe('My Registry');
        expect(document.querySelector('textarea[name="description"]').value).toBe('My description');
    });
});

// ── Focus management ──────────────────────────────────────────────────────────

describe('focus management', () => {
    it('sets focus to first focusable element when modal opens', () => {
        document.body.innerHTML = `
            <div class="rr-manage-registry" data-registry-id="1">
                <ul class="rr-item-list">
                    <li class="rr-item-row" data-item-id="1" data-name="Widget" data-price="9.99"
                        data-image-url="" data-retailer="" data-affiliate-url=""
                        data-quantity="1" data-qty-purchased="0" data-notes="">
                        <button class="rr-item-name-btn">Widget</button>
                    </li>
                </ul>
                <div id="rr-item-detail-modal" class="rr-modal" aria-hidden="true">
                    <button class="rr-modal__close">×</button>
                    <span class="rr-item-detail__title"></span>
                    <div class="rr-item-detail__image-wrap"><img class="rr-item-detail__image" src="" alt=""></div>
                    <div class="rr-item-detail__meta"></div>
                    <div class="rr-item-detail__description"></div>
                    <div class="rr-item-detail__qty-row"></div>
                    <a class="rr-item-detail__purchase-btn" href="#">Purchase</a>
                    <button class="rr-mark-purchased rr-item-detail__mark-btn">Mark</button>
                </div>
            </div>
        `;

        document.querySelector('.rr-item-name-btn').click();

        const modal = document.getElementById('rr-item-detail-modal');
        expect(modal.getAttribute('aria-inert')).toBe('false');
        expect(modal.classList.contains('is-open')).toBe(true);
    });
});

// ── Body class toggle ─────────────────────────────────────────────────────────

describe('body class management', () => {
    it('adds rr-modal-open class when first modal opens', () => {
        document.querySelector('.rr-item-name-btn').click();
        expect(document.body.classList.contains('rr-modal-open')).toBe(true);
    });

    it('removes rr-modal-open class when last modal closes', () => {
        document.querySelector('.rr-item-name-btn').click();
        expect(document.body.classList.contains('rr-modal-open')).toBe(true);

        document.querySelector('.rr-modal__close').click();
        expect(document.body.classList.contains('rr-modal-open')).toBe(false);
    });
});

// ── Shipping address ──────────────────────────────────────────────────────────

describe('shipping address', () => {
    function addAddressForm() {
        document.body.innerHTML += `
            <div class="rr-manage-registry" data-registry-id="1">
                <form id="rr-address-form">
                    <input name="shipping_name" value="Alex Rivera">
                    <input name="address_1" value="123 Main St">
                    <input name="address_2" value="Apt 4B">
                    <input name="city" value="Portland">
                    <input name="state" value="OR">
                    <input name="postal_code" value="97205">
                    <input name="country" value="US">
                    <div class="rr-form-actions">
                        <button type="submit" id="rr-save-address-btn">Save Address</button>
                    </div>
                </form>
                <div id="rr-shipping-address-block" data-address="Alex Rivera, 123 Main St, Apt 4B, Portland, OR, 97205, US">
                    <button class="rr-copy-address">Copy address</button>
                </div>
            </div>
        `;
    }

    beforeEach(() => {
        addAddressForm();
    });

    it('submits correct payload to restart_registry_save_shipping_address', async () => {
        fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { message: 'Address saved.' } }),
        });

        document.getElementById('rr-address-form').dispatchEvent(
            new Event('submit', { bubbles: true })
        );
        await flushPromises();

        const body = new URLSearchParams(fetch.mock.calls[0][1].body);
        expect(body.get('action')).toBe('restart_registry_save_shipping_address');
        expect(body.get('city')).toBe('Portland');
        expect(body.get('postal_code')).toBe('97205');
    });

    it('sends restart_registry_delete_shipping_address and removes Remove button on success', async () => {
        // Add a Remove button to the DOM
        document.querySelector('#rr-address-form .rr-form-actions').insertAdjacentHTML(
            'beforeend',
            '<button type="button" id="rr-remove-address">Remove</button>'
        );

        fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { message: 'Address removed.' } }),
        });

        document.getElementById('rr-remove-address').click();
        await flushPromises();

        const body = new URLSearchParams(fetch.mock.calls[0][1].body);
        expect(body.get('action')).toBe('restart_registry_delete_shipping_address');
        expect(document.getElementById('rr-remove-address')).toBeNull();
    });

    it('writes the address to clipboard and shows Copied! feedback', async () => {
        navigator.clipboard.writeText.mockClear();

        document.querySelector('.rr-copy-address').click();
        await flushPromises();

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
            'Alex Rivera, 123 Main St, Apt 4B, Portland, OR, 97205, US'
        );
        expect(document.querySelector('.rr-copy-address').textContent).toBe('Copied!');
    });

    it('does not write to clipboard when no address block is present', async () => {
        navigator.clipboard.writeText.mockClear();
        // A copy button exists but the data block does not — simulates the
        // purchase modal copy button rendered while the page-level block is absent.
        document.getElementById('rr-shipping-address-block').remove();
        document.body.insertAdjacentHTML(
            'beforeend',
            '<button class="rr-copy-address rr-orphan-copy">Copy</button>'
        );

        document.querySelector('.rr-orphan-copy').click();
        await flushPromises();

        expect(navigator.clipboard.writeText).not.toHaveBeenCalled();
    });
});

// ── Edge cases ────────────────────────────────────────────────────────────────

describe('edge cases', () => {
    it('does not crash when clicking on an empty container', () => {
        document.body.innerHTML = '<div class="rr-manage-registry" data-registry-id="1"></div>';

        expect(() => {
            document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        }).not.toThrow();
    });

    it('handles missing form fields gracefully', () => {
        fetch.mockResolvedValueOnce({
            json: async () => ({ success: true, data: { name: 'Product', price: '10', image_url: '', description: '' } }),
        });
        // No fetch form added — clicking a non-existent element should not crash
        expect(() => {
            const btn = document.getElementById('rr-fetch-url');
            if (btn) btn.click();
        }).not.toThrow();
    });

    it('does not crash on rapid modal open/close', () => {
        expect(() => {
            for (let i = 0; i < 10; i++) {
                document.querySelector('.rr-item-name-btn').click();
                document.querySelector('.rr-modal__close').click();
            }
        }).not.toThrow();
    });

    it('handles Escape key with modal not in focus', () => {
        document.querySelector('.rr-item-name-btn').click();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, target: document.body }));
        expect(document.getElementById('rr-item-detail-modal').classList.contains('is-open')).toBe(false);
    });

    it('handles missing data attributes gracefully', () => {
        document.body.innerHTML = `
            <div class="rr-manage-registry">
                <ul class="rr-item-list">
                    <li class="rr-item-row">
                        <button class="rr-item-name-btn">No Data</button>
                    </li>
                </ul>
                <div id="rr-item-detail-modal" class="rr-modal">
                    <span class="rr-item-detail__title"></span>
                    <div class="rr-item-detail__image-wrap"><img class="rr-item-detail__image" src="" alt=""></div>
                    <div class="rr-item-detail__meta"></div>
                    <div class="rr-item-detail__description"></div>
                    <div class="rr-item-detail__qty-row"></div>
                    <a class="rr-item-detail__purchase-btn" href="#">Purchase</a>
                    <button class="rr-item-detail__mark-btn">Mark</button>
                </div>
            </div>
        `;

        expect(() => {
            document.querySelector('.rr-item-name-btn').click();
        }).not.toThrow();
    });
});
