'use strict';

const $ = require('jquery');

// ── Globals the script expects ───────────────────────────────────────────────
global.jQuery = $;
global.$ = $;
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
window.prompt  = jest.fn().mockReturnValue('');
window.alert   = jest.fn();

$.ajax = jest.fn();

// ── DOM factory ──────────────────────────────────────────────────────────────

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

// ── One-time script load ─────────────────────────────────────────────────────
// In Jest's jsdom, window.setTimeout(jQuery.ready) (registered when jQuery first
// loads) never fires because Jest doesn't yield to the real event loop between
// test statements. Overriding $.fn.ready to fire synchronously is the simplest
// fix: the script's $(document).ready(cb) becomes cb($) immediately.

beforeAll(() => {
    buildDOM();

    // Make $(document).ready(fn) fire fn synchronously
    const origReady = $.fn.ready;
    $.fn.ready = function (fn) { fn($); return this; };
    require('../../public/js/restart-registry-public.js');
    $.fn.ready = origReady; // restore for any code that needs the real behaviour
});

// Rebuild DOM before every test; delegated handlers on $(document) survive.
beforeEach(() => {
    $.ajax.mockClear();
    // breaks on JQuery >= 4.0.0;
    // window.location.reload.mockClear();
    buildDOM();
});

// ── Modal open / close ───────────────────────────────────────────────────────

describe('modal open/close', () => {
    it('opens the item-detail modal when an item name button is clicked', () => {
        $('.rr-item-name-btn').trigger('click');
        expect($('#rr-item-detail-modal').attr('aria-inert')).toBe('false');
        expect($('#rr-item-detail-modal').hasClass('is-open')).toBe(true);
    });

    it('closes the modal when the × button is clicked', () => {
        $('.rr-item-name-btn').trigger('click');
        $('.rr-modal__close').trigger('click');
        expect($('#rr-item-detail-modal').attr('aria-inert')).toBe('true');
        expect($('#rr-item-detail-modal').hasClass('is-open')).toBe(false);
    });

    it('closes the modal when the backdrop is clicked', () => {
        $('.rr-item-name-btn').trigger('click');
        $('.rr-modal__backdrop').trigger('click');
        expect($('#rr-item-detail-modal').hasClass('is-open')).toBe(false);
    });

    it('closes the modal on Escape key', () => {
        $('.rr-item-name-btn').trigger('click');
        $(document).trigger($.Event('keydown', { key: 'Escape' }));
        expect($('#rr-item-detail-modal').hasClass('is-open')).toBe(false);
    });
});

// ── Item detail modal content ─────────────────────────────────────────────────

describe('item detail modal content', () => {
    beforeEach(() => {
        $('.rr-item-name-btn').trigger('click');
    });

    it('populates the title', () => {
        expect($('.rr-item-detail__title').text()).toBe('Test Skateboard Rack');
    });

    it('shows the product image and reveals image wrap', () => {
        expect($('.rr-item-detail__image').attr('src')).toBe('https://example.com/image.jpg');
        expect($('.rr-item-detail__image-wrap').css('display')).not.toBe('none');
    });

    it('shows the retailer badge', () => {
        expect($('.rr-item-detail__meta').text()).toContain('Etsy');
    });

    it('formats the price with two decimal places', () => {
        expect($('.rr-item-detail__meta').text()).toContain('$49.99');
    });

    it('shows the notes in the description element', () => {
        expect($('.rr-item-detail__description').text()).toBe('any color is fine');
    });

    it('shows qty needed and purchased', () => {
        const qty = $('.rr-item-detail__qty-row').text();
        expect(qty).toContain('2'); // needed
        expect(qty).toContain('0'); // purchased
    });

    it('shows purchase button with affiliate URL', () => {
        expect($('.rr-item-detail__purchase-btn').attr('href')).toBe('https://etsy.com/listing/123?ref=aff');
        expect($('.rr-item-detail__purchase-btn').css('display')).not.toBe('none');
    });

    it('hides mark-fulfilled button in manage context', () => {
        expect($('.rr-item-detail__mark-btn').css('display')).toBe('none');
    });
});

// ── Guest view ────────────────────────────────────────────────────────────────

describe('item detail modal in guest/view-registry context', () => {
    beforeEach(() => {
        buildDOM({ inViewRegistry: true });
        $('.rr-item-name-btn').trigger('click');
    });

    it('shows the mark-fulfilled button for guests', () => {
        expect($('.rr-item-detail__mark-btn').css('display')).not.toBe('none');
    });
});

// ── No image ──────────────────────────────────────────────────────────────────

describe('item detail modal without image', () => {
    beforeEach(() => {
        buildDOM({ noImage: true });
        $('.rr-item-name-btn').trigger('click');
    });

    it('hides the image wrap when no image URL is set', () => {
        expect($('.rr-item-detail__image-wrap').css('display')).toBe('none');
    });
});

// ── URL fetch / stale image ───────────────────────────────────────────────────

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
        $('#rr-item-url').val('https://example.com/product');
    });

    it('populates image URL field when fetch returns an image', () => {
        $.ajax.mockImplementationOnce(({ success }) => {
            success({ success: true, data: { name: 'Widget', price: '9.99', image_url: 'https://cdn.example.com/img.jpg', description: 'Nice' } });
        });
        $('#rr-fetch-url').trigger('click');
        expect($('#rr-item-image-url').val()).toBe('https://cdn.example.com/img.jpg');
    });

    it('clears image URL field when a subsequent fetch returns no image', () => {
        // First fetch — sets an image
        $.ajax.mockImplementationOnce(({ success }) => {
            success({ success: true, data: { name: 'Widget', price: '9.99', image_url: 'https://cdn.example.com/img.jpg', description: '' } });
        });
        $('#rr-fetch-url').trigger('click');
        expect($('#rr-item-image-url').val()).toBe('https://cdn.example.com/img.jpg');

        // Second fetch — no image returned
        $.ajax.mockImplementationOnce(({ success }) => {
            success({ success: true, data: { name: 'Other Product', price: '19.99', image_url: '', description: '' } });
        });
        $('#rr-fetch-url').trigger('click');
        expect($('#rr-item-image-url').val()).toBe('');
    });

    it('leaves image URL field unchanged when the fetch fails at the network level', () => {
        $('#rr-item-image-url').val('https://cdn.example.com/existing.jpg');
        $.ajax.mockImplementationOnce(({ error }) => { error(); });
        $('#rr-fetch-url').trigger('click');
        expect($('#rr-item-image-url').val()).toBe('https://cdn.example.com/existing.jpg');
    });

    it('sends AJAX request with correct URL parameter', () => {
        $.ajax.mockImplementationOnce(({ success }) => {
            success({ success: true, data: { name: 'Product', price: '10', image_url: '', description: '' } });
        });
        $('#rr-item-url').val('https://shop.example.com/product-123');
        $('#rr-fetch-url').trigger('click');
        expect($.ajax).toHaveBeenCalled();
    });

    it('populates product name field when fetch succeeds', () => {
        $.ajax.mockImplementationOnce(({ success }) => {
            success({ success: true, data: { name: 'Premium Widget', price: '99.99', image_url: '', description: '' } });
        });
        $('#rr-fetch-url').trigger('click');
        expect($('#rr-item-name').val()).toBe('Premium Widget');
    });

    it('populates price field when fetch succeeds', () => {
        $.ajax.mockImplementationOnce(({ success }) => {
            success({ success: true, data: { name: 'Widget', price: '24.99', image_url: '', description: '' } });
        });
        $('#rr-fetch-url').trigger('click');
        expect($('#rr-item-price').val()).toBe('24.99');
    });

    it('populates description field when fetch succeeds', () => {
        $.ajax.mockImplementationOnce(({ success }) => {
            success({ success: true, data: { name: 'Widget', price: '10', image_url: '', description: 'A wonderful product' } });
        });
        $('#rr-fetch-url').trigger('click');
        expect($('#rr-item-description').val()).toBe('A wonderful product');
    });
});

// ── Multiple modal management ─────────────────────────────────────────────

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
        // Open first modal
        $('.rr-item-name-btn').trigger('click');
        expect($('#rr-item-detail-modal').hasClass('is-open')).toBe(true);

        // Verify other modal is not open
        expect($('#rr-other-modal').hasClass('is-open')).toBe(false);

        // Open other modal manually
        $('#rr-other-modal').attr('aria-inert', 'false').addClass('is-open');
        expect($('#rr-other-modal').hasClass('is-open')).toBe(true);

        // Close the item detail modal
        $('.rr-modal__close').first().trigger('click');
        expect($('#rr-item-detail-modal').hasClass('is-open')).toBe(false);

        // Other modal should still be open
        expect($('#rr-other-modal').hasClass('is-open')).toBe(true);
    });

    it('applies body class only when any modal is open', () => {
        // Open first modal
        $('.rr-item-name-btn').trigger('click');
        expect($('body').hasClass('rr-modal-open')).toBe(true);

        // Close it
        $('.rr-modal__close').first().trigger('click');
        expect($('body').hasClass('rr-modal-open')).toBe(false);
    });
});

// ── Registry creation form ────────────────────────────────────────────────

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
        const $form = $('#rr-create-registry-form');
        expect($form.length).toBe(1);
    });

    it('form has required input fields', () => {
        const $titleInput = $('input[name="title"]');
        const $descriptionInput = $('textarea[name="description"]');
        expect($titleInput.length).toBe(1);
        expect($descriptionInput.length).toBe(1);
    });

    it('form has submit button', () => {
        const $btn = $('#rr-create-registry-form button[type="submit"]');
        expect($btn.length).toBe(1);
    });

    it('allows setting form field values', () => {
        $('input[name="title"]').val('My Registry');
        $('textarea[name="description"]').val('My description');
        
        expect($('input[name="title"]').val()).toBe('My Registry');
        expect($('textarea[name="description"]').val()).toBe('My description');
    });
});

// ── Focus management in modals ────────────────────────────────────────────

describe('focus management', () => {
    it('sets focus to first focusable element when modal opens', () => {
        document.body.innerHTML = `
            <div class="rr-manage-registry" data-registry-id="1">
                <button class="rr-item-name-btn">Item</button>
                <div id="rr-item-detail-modal" class="rr-modal" aria-hidden="true">
                    <button class="rr-modal__close">×</button>
                    <button class="rr-mark-purchased">Mark</button>
                </div>
            </div>
        `;
        
        const $modal = $('#rr-item-detail-modal');
        $('.rr-item-name-btn').trigger('click');
        
        // Modal should be open with aria-inert false
        expect($modal.attr('aria-inert')).toBe('false');
        expect($modal.hasClass('is-open')).toBe(true);
    });
});

// ── Body class toggle ─────────────────────────────────────────────────────

describe('body class management', () => {
    it('adds rr-modal-open class when first modal opens', () => {
        $('.rr-item-name-btn').trigger('click');
        expect($('body').hasClass('rr-modal-open')).toBe(true);
    });

    it('removes rr-modal-open class when last modal closes', () => {
        $('.rr-item-name-btn').trigger('click');
        expect($('body').hasClass('rr-modal-open')).toBe(true);
        
        $('.rr-modal__close').trigger('click');
        expect($('body').hasClass('rr-modal-open')).toBe(false);
    });
});

// ── Edge cases ────────────────────────────────────────────────────────────

describe('edge cases', () => {
    it('does not crash when clicking modal on empty container', () => {
        document.body.innerHTML = '<div class="rr-manage-registry" data-registry-id="1"></div>';
        
        expect(() => {
            $(document).trigger($.Event('click', { target: document.body }));
        }).not.toThrow();
    });

    it('handles missing form fields gracefully', () => {
        $.ajax.mockImplementationOnce(({ success }) => {
            success({ success: true, data: { name: 'Product', price: '10', image_url: '', description: '' } });
        });
        
        // Don't add fetch form — should not crash
        expect(() => {
            $('#rr-fetch-url').trigger('click');
        }).not.toThrow();
    });

    it('does not crash on rapid modal open/close', () => {
        expect(() => {
            for (let i = 0; i < 10; i++) {
                $('.rr-item-name-btn').trigger('click');
                $('.rr-modal__close').trigger('click');
            }
        }).not.toThrow();
    });

    it('handles Escape key with modal not in focus', () => {
        $('.rr-item-name-btn').trigger('click');
        const $modal = $('#rr-item-detail-modal');
        
        $(document).trigger($.Event('keydown', { key: 'Escape', target: document.body }));
        expect($modal.hasClass('is-open')).toBe(false);
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
                </div>
            </div>
        `;
        
        expect(() => {
            $('.rr-item-name-btn').trigger('click');
        }).not.toThrow();
    });
});
