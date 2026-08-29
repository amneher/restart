'use strict';

// ── TinyMCE stub ─────────────────────────────────────────────────────────────
// Must be defined before require() so the plugin can call editor.addButton.

let buttons          = {};
let lastInserted      = null;
let lastWindowConfig  = null;
let lastWin           = null;

/** Builds a fake TinyMCE `win` control: `.find('#name')[0].value()/.value(v)` per body field. */
function makeWinFromBody(body) {
    const values   = {};
    const controls = {};

    (function walk(arr) {
        arr.forEach((f) => {
            if (!f.name) return;
            values[f.name] = f.value || '';
            controls[f.name] = {
                value: jest.fn((v) => {
                    if (v === undefined) return values[f.name];
                    values[f.name] = v;
                    return controls[f.name];
                }),
            };
        });
    })(body);

    return {
        find: (selector) => {
            const name = selector.replace('#', '');
            return controls[name] ? [controls[name]] : [];
        },
    };
}

const mockEditor = {
    insertContent: jest.fn((sc) => { lastInserted = sc; }),
    windowManager: {
        open: jest.fn((config) => {
            lastWindowConfig = config;
            lastWin = makeWinFromBody(config.body);
            return lastWin;
        }),
        alert: jest.fn(),
    },
    addButton: jest.fn((name, config) => { buttons[name] = config; }),
};

let pluginFactory = null;

global.tinymce = {
    PluginManager: {
        add: jest.fn((name, factory) => {
            pluginFactory = factory;
            factory(mockEditor);
        }),
    },
};

// Load plugin — triggers PluginManager.add → factory → addButton (captures both buttons)
require('../../admin/js/restart-registry-tinymce.js');

// ── Helpers ───────────────────────────────────────────────────────────────────

function openItemModal() {
    buttons.restart_item.onclick();
}

function openFavoritesModal() {
    buttons.restart_favorites_row.onclick();
}

function submit(data) {
    lastWindowConfig.onsubmit({ data });
}

const flushPromises = () => Promise.resolve().then(() => Promise.resolve()).then(() => Promise.resolve());

function fakeButtonControl() {
    return {
        disabled: jest.fn(function () { return this; }),
        text:     jest.fn(function () { return this; }),
    };
}

function findFetchButtonAfter(body, urlFieldName) {
    const idx = body.findIndex((f) => f.name === urlFieldName);
    return body[idx + 1];
}

beforeEach(() => {
    jest.clearAllMocks();
    buttons          = {};
    lastInserted      = null;
    lastWindowConfig  = null;
    lastWin           = null;
    global.rrAdmin = { ajaxurl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'admin-nonce', fetchNonce: 'fetch-nonce' };
    // Re-run the plugin's init factory so addButton is called again against
    // the fresh mocks (the module itself was only require()'d once).
    pluginFactory(mockEditor);
});

afterEach(() => {
    delete global.rrAdmin;
    delete global.fetch;
});

// ── Registration ──────────────────────────────────────────────────────────────

test('plugin registers all four buttons', () => {
    expect(typeof buttons.restart_item.onclick).toBe('function');
    expect(typeof buttons.restart_favorites_row.onclick).toBe('function');
    expect(typeof buttons.restart_favorites_room.onclick).toBe('function');
    expect(typeof buttons.restart_favorites_filters.onclick).toBe('function');
});

// ── [restart_item] button (existing behavior, unchanged) ──────────────────────

describe('Insert Item button', () => {
    beforeEach(() => {
        openItemModal();
    });

    test('clicking the button opens windowManager with a form', () => {
        expect(mockEditor.windowManager.open).toHaveBeenCalled();
        expect(lastWindowConfig.title).toBe('Insert Item Card');
        expect(Array.isArray(lastWindowConfig.body)).toBe(true);
    });

    test('form contains all expected fields', () => {
        const names = lastWindowConfig.body.map((f) => f.name);
        expect(names).toEqual(expect.arrayContaining([
            'url', 'title', 'price', 'images', 'description', 'retailer', 'notes', 'quantity',
        ]));
    });

    test('quantity field defaults to 1', () => {
        const qty = lastWindowConfig.body.find((f) => f.name === 'quantity');
        expect(qty.value).toBe('1');
    });

    test('all fields default to empty string except quantity', () => {
        const nonQty = lastWindowConfig.body.filter((f) => f.name !== 'quantity');
        nonQty.forEach((f) => expect(f.value).toBe(''));
    });

    test('inserts shortcode with all provided fields', () => {
        submit({
            url:         'https://example.com/product',
            title:       'Chef Knife',
            price:       '49.99',
            images:      'https://example.com/img.jpg',
            description: 'A great knife',
            retailer:    'Example Shop',
            notes:       'Any color',
            quantity:    '2',
        });

        expect(lastInserted).toContain('[restart_item');
        expect(lastInserted).toContain('title="Chef Knife"');
        expect(lastInserted).toContain('url="https://example.com/product"');
        expect(lastInserted).toContain('price="49.99"');
        expect(lastInserted).toContain('images="https://example.com/img.jpg"');
        expect(lastInserted).toContain('description="A great knife"');
        expect(lastInserted).toContain('retailer="Example Shop"');
        expect(lastInserted).toContain('notes="Any color"');
        expect(lastInserted).toContain('quantity="2"');
        expect(lastInserted).toMatch(/\]$/);
    });

    test('omits empty optional fields', () => {
        submit({ title: 'Minimal', url: '', price: '', images: '', description: '', retailer: '', notes: '', quantity: '1' });

        expect(lastInserted).toBe('[restart_item title="Minimal"]');
    });

    test('omits quantity when it equals the default of 1', () => {
        submit({ title: 'Item', url: 'https://example.com', price: '', images: '', description: '', retailer: '', notes: '', quantity: '1' });

        expect(lastInserted).not.toContain('quantity=');
    });

    test('includes quantity when not default', () => {
        submit({ title: 'Item', url: '', price: '', images: '', description: '', retailer: '', notes: '', quantity: '3' });

        expect(lastInserted).toContain('quantity="3"');
    });

    test('escapes double-quotes in attribute values', () => {
        submit({ title: 'Say "Hello"', url: '', price: '', images: '', description: '', retailer: '', notes: '', quantity: '1' });

        expect(lastInserted).toContain('title="Say &quot;Hello&quot;"');
    });

    test('strips square brackets from attribute values to prevent shortcode parser breakout', () => {
        submit({ title: 'A [special] item', url: '', price: '', images: '', description: '', retailer: '', notes: '', quantity: '1' });

        expect(lastInserted).toContain('title="A special item"');
        expect(lastInserted).not.toContain('[special]');
    });

    test('trims whitespace from field values', () => {
        submit({ title: '  Trimmed Title  ', url: '', price: '', images: '', description: '', retailer: '', notes: '', quantity: '1' });

        expect(lastInserted).toContain('title="Trimmed Title"');
    });

    test('shows alert and does not insert when title is empty', () => {
        submit({ title: '', url: 'https://example.com', price: '', images: '', description: '', retailer: '', notes: '', quantity: '1' });

        expect(mockEditor.windowManager.alert).toHaveBeenCalled();
        expect(mockEditor.insertContent).not.toHaveBeenCalled();
    });

    test('shows alert when title is only whitespace', () => {
        submit({ title: '   ', url: '', price: '', images: '', description: '', retailer: '', notes: '', quantity: '1' });

        expect(mockEditor.windowManager.alert).toHaveBeenCalled();
        expect(mockEditor.insertContent).not.toHaveBeenCalled();
    });
});

// ── [restart_favorites_row] button ─────────────────────────────────────────────

describe('Insert Favorites Row button', () => {
    beforeEach(() => {
        openFavoritesModal();
    });

    test('opens windowManager with the favorites row form', () => {
        expect(mockEditor.windowManager.open).toHaveBeenCalled();
        expect(lastWindowConfig.title).toBe('Insert Favorites Row (Save / Spend / Splurge)');
        expect(Array.isArray(lastWindowConfig.body)).toBe(true);
    });

    test('form contains item_title plus save/spend/splurge field sets', () => {
        const names = lastWindowConfig.body.map((f) => f.name).filter(Boolean);
        expect(names).toContain('item_title');
        ['save', 'spend', 'splurge'].forEach((tier) => {
            ['url', 'title', 'price', 'image', 'retailer', 'description'].forEach((field) => {
                expect(names).toContain(`${tier}_${field}`);
            });
        });
    });

    test('shows alert and does not insert when General Item Title is empty', () => {
        submit({ item_title: '', save_title: 'Sofa A' });

        expect(mockEditor.windowManager.alert).toHaveBeenCalled();
        expect(mockEditor.insertContent).not.toHaveBeenCalled();
    });

    test('shows alert and does not insert when no tier has a title', () => {
        submit({ item_title: 'Sofa', save_title: '', spend_title: '', splurge_title: '' });

        expect(mockEditor.windowManager.alert).toHaveBeenCalled();
        expect(mockEditor.insertContent).not.toHaveBeenCalled();
    });

    test('inserts a restart_favorites_row wrapping one restart_item per filled tier', () => {
        submit({
            item_title:    'Sofa',
            save_title:    'Budget Sofa',
            save_price:    '299',
            save_url:      'https://example.com/budget-sofa',
            spend_title:   'Mid Sofa',
            spend_price:   '599',
            splurge_title: 'Luxury Sofa',
            splurge_price: '1299',
        });

        expect(lastInserted).toContain('[restart_favorites_row title="Sofa"]');
        expect(lastInserted).toContain('[restart_item tier="save" title="Budget Sofa" price="299" url="https://example.com/budget-sofa"]');
        expect(lastInserted).toContain('[restart_item tier="spend" title="Mid Sofa" price="599"]');
        expect(lastInserted).toContain('[restart_item tier="splurge" title="Luxury Sofa" price="1299"]');
        expect(lastInserted).toMatch(/\[\/restart_favorites_row\]$/);
    });

    test('omits tiers with no title', () => {
        submit({ item_title: 'Sofa', save_title: 'Only Save Option' });

        expect(lastInserted).toContain('tier="save"');
        expect(lastInserted).not.toContain('tier="spend"');
        expect(lastInserted).not.toContain('tier="splurge"');
    });

    test('escapes double-quotes and strips brackets in the row title', () => {
        submit({ item_title: 'Say "Hi" [now]', save_title: 'Option' });

        expect(lastInserted).toContain('[restart_favorites_row title="Say &quot;Hi&quot; now"]');
    });

    describe('Fetch product info button', () => {
        test('shows alert and does not call fetch when URL is empty', () => {
            const fetchBtn = findFetchButtonAfter(lastWindowConfig.body, 'save_url');
            global.fetch = jest.fn();

            fetchBtn.onclick.call(fakeButtonControl());

            expect(mockEditor.windowManager.alert).toHaveBeenCalled();
            expect(global.fetch).not.toHaveBeenCalled();
        });

        test('calls the restart_registry_fetch_url AJAX action with the field URL', () => {
            const fetchBtn = findFetchButtonAfter(lastWindowConfig.body, 'save_url');
            lastWin.find('#save_url')[0].value('https://example.com/sofa');
            global.fetch = jest.fn(() => new Promise(() => {})); // never resolves — just inspect the call

            fetchBtn.onclick.call(fakeButtonControl());

            expect(global.fetch).toHaveBeenCalledTimes(1);
            const [url, opts] = global.fetch.mock.calls[0];
            expect(url).toBe('https://example.test/wp-admin/admin-ajax.php');
            const body = new URLSearchParams(opts.body);
            expect(body.get('action')).toBe('restart_registry_fetch_url');
            expect(body.get('nonce')).toBe('fetch-nonce');
            expect(body.get('url')).toBe('https://example.com/sofa');
        });

        test('populates sibling fields on a successful fetch', async () => {
            const fetchBtn = findFetchButtonAfter(lastWindowConfig.body, 'save_url');
            lastWin.find('#save_url')[0].value('https://example.com/sofa');
            global.fetch = jest.fn().mockResolvedValue({
                json: () => Promise.resolve({
                    success: true,
                    data: {
                        name:        'Comfy Sofa',
                        price:       '399.00',
                        image_url:   'https://example.com/sofa.jpg',
                        retailer:    'Example Shop',
                        description: 'A comfy sofa',
                    },
                }),
            });

            const btn = fakeButtonControl();
            fetchBtn.onclick.call(btn);
            await flushPromises();

            expect(lastWin.find('#save_title')[0].value()).toBe('Comfy Sofa');
            expect(lastWin.find('#save_price')[0].value()).toBe('399.00');
            expect(lastWin.find('#save_image')[0].value()).toBe('https://example.com/sofa.jpg');
            expect(lastWin.find('#save_retailer')[0].value()).toBe('Example Shop');
            expect(lastWin.find('#save_description')[0].value()).toBe('A comfy sofa');
            expect(btn.disabled).toHaveBeenCalledWith(false);
        });

        test('shows alert and re-enables the button when the fetch fails', async () => {
            const fetchBtn = findFetchButtonAfter(lastWindowConfig.body, 'save_url');
            lastWin.find('#save_url')[0].value('https://example.com/sofa');
            global.fetch = jest.fn().mockRejectedValue(new Error('network'));

            const btn = fakeButtonControl();
            fetchBtn.onclick.call(btn);
            await flushPromises();

            expect(mockEditor.windowManager.alert).toHaveBeenCalled();
            expect(btn.disabled).toHaveBeenCalledWith(false);
        });

        test('fetching for one tier does not touch another tier\'s fields', async () => {
            const saveFetchBtn = findFetchButtonAfter(lastWindowConfig.body, 'save_url');
            lastWin.find('#save_url')[0].value('https://example.com/sofa');
            global.fetch = jest.fn().mockResolvedValue({
                json: () => Promise.resolve({ success: true, data: { name: 'Save Sofa' } }),
            });

            saveFetchBtn.onclick.call(fakeButtonControl());
            await flushPromises();

            expect(lastWin.find('#save_title')[0].value()).toBe('Save Sofa');
            expect(lastWin.find('#spend_title')[0].value()).toBe('');
            expect(lastWin.find('#splurge_title')[0].value()).toBe('');
        });
    });
});

// ── [restart_favorites_room] button ────────────────────────────────────────────

describe('Insert Favorites Room button', () => {
    beforeEach(() => {
        buttons.restart_favorites_room.onclick();
    });

    test('opens windowManager with a room title form', () => {
        expect(mockEditor.windowManager.open).toHaveBeenCalled();
        expect(lastWindowConfig.title).toBe('Insert Favorites Room');
        const names = lastWindowConfig.body.map((f) => f.name);
        expect(names).toEqual(['room_title']);
    });

    test('shows alert and does not insert when Room Title is empty', () => {
        submit({ room_title: '' });

        expect(mockEditor.windowManager.alert).toHaveBeenCalled();
        expect(mockEditor.insertContent).not.toHaveBeenCalled();
    });

    test('shows alert when Room Title is only whitespace', () => {
        submit({ room_title: '   ' });

        expect(mockEditor.windowManager.alert).toHaveBeenCalled();
        expect(mockEditor.insertContent).not.toHaveBeenCalled();
    });

    test('inserts an empty restart_favorites_room shell with the given title', () => {
        submit({ room_title: 'Living Room' });

        expect(lastInserted).toContain('[restart_favorites_room title="Living Room"]');
        expect(lastInserted).toContain('[/restart_favorites_room]');
    });

    test('escapes double-quotes and strips brackets in the room title', () => {
        submit({ room_title: 'Say "Hi" [now]' });

        expect(lastInserted).toContain('[restart_favorites_room title="Say &quot;Hi&quot; now"]');
    });

    test('trims whitespace from the room title', () => {
        submit({ room_title: '  Living Room  ' });

        expect(lastInserted).toContain('title="Living Room"');
    });
});

// ── [restart_favorites_filters] button ─────────────────────────────────────────

describe('Insert Favorites Filters button', () => {
    test('inserts the bare shortcode with no modal', () => {
        buttons.restart_favorites_filters.onclick();

        expect(mockEditor.windowManager.open).not.toHaveBeenCalled();
        expect(lastInserted).toBe('[restart_favorites_filters]');
    });
});
