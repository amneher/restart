'use strict';

// ── TinyMCE stub ─────────────────────────────────────────────────────────────
// Must be defined before require() so the plugin can call editor.addButton.

let capturedOnclick    = null;
let lastInserted       = null;
let lastWindowConfig   = null;

const mockEditor = {
    insertContent: jest.fn((sc) => { lastInserted = sc; }),
    windowManager: {
        open:  jest.fn((config) => { lastWindowConfig = config; }),
        alert: jest.fn(),
    },
    addButton: jest.fn((name, config) => {
        capturedOnclick = config.onclick;
    }),
};

global.tinymce = {
    PluginManager: {
        add: jest.fn((name, factory) => {
            factory(mockEditor);
        }),
    },
};

// Load plugin — triggers PluginManager.add → factory → addButton (captures onclick)
require('../../admin/js/restart-registry-tinymce.js');

// ── Helpers ───────────────────────────────────────────────────────────────────

function openModal() {
    capturedOnclick();
}

function submit(data) {
    lastWindowConfig.onsubmit({ data });
}

beforeEach(() => {
    jest.clearAllMocks();
    lastInserted   = null;
    lastWindowConfig = null;
    openModal();
});

// ── Registration ──────────────────────────────────────────────────────────────

test('plugin registers a button onclick for restart_item', () => {
    // capturedOnclick is set when addButton('restart_item', {onclick}) is called
    // during plugin load — confirms the plugin registered correctly.
    expect(typeof capturedOnclick).toBe('function');
});

test('clicking the button opens windowManager with a form', () => {
    expect(mockEditor.windowManager.open).toHaveBeenCalled();
    expect(lastWindowConfig.title).toBe('Insert Item Card');
    expect(Array.isArray(lastWindowConfig.body)).toBe(true);
});

// ── Form fields ───────────────────────────────────────────────────────────────

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

// ── Shortcode output ──────────────────────────────────────────────────────────

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

// ── Validation ────────────────────────────────────────────────────────────────

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
