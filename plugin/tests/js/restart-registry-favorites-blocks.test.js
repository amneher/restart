'use strict';

describe('favorites blocks registration', () => {
    let registerBlockType;

    beforeEach(() => {
        jest.resetModules();
        registerBlockType = jest.fn();

        global.wp = {
            blocks: { registerBlockType },
            element: {
                createElement: jest.fn(() => ({})),
                useState: jest.fn((initial) => [initial, jest.fn()]),
            },
            blockEditor: {
                useBlockProps: jest.fn(() => ({})),
                useInnerBlocksProps: jest.fn((props) => props),
                MediaUpload: function MediaUpload() { return null; },
                InspectorControls: function InspectorControls() { return null; },
            },
            components: {
                TextControl: function TextControl() { return null; },
                TextareaControl: function TextareaControl() { return null; },
                SelectControl: function SelectControl() { return null; },
                Button: function Button() { return null; },
                PanelBody: function PanelBody() { return null; },
            },
            i18n: { __: (s) => s },
        };

        require('../../public/blocks/index.js');
    });

    it('registers all four favorites blocks', () => {
        const names = registerBlockType.mock.calls.map(([name]) => name);
        expect(names).toEqual([
            'restart-registry/favorites-item',
            'restart-registry/favorites-row',
            'restart-registry/favorites-room',
            'restart-registry/favorites-filters',
        ]);
    });

    it('every block defines edit() and a save() that returns null (fully server-rendered)', () => {
        registerBlockType.mock.calls.forEach(([, config]) => {
            expect(typeof config.edit).toBe('function');
            expect(config.save()).toBeNull();
        });
    });

    it('restricts favorites-row to only contain favorites-item via allowedBlocks', () => {
        const [, rowConfig] = registerBlockType.mock.calls.find(([name]) => name === 'restart-registry/favorites-row');
        rowConfig.edit({ attributes: { title: '' }, setAttributes: jest.fn() });

        const [, opts] = global.wp.blockEditor.useInnerBlocksProps.mock.calls[0];
        expect(opts.allowedBlocks).toEqual(['restart-registry/favorites-item']);
    });

    it('restricts favorites-room to only contain favorites-row via allowedBlocks', () => {
        const [, roomConfig] = registerBlockType.mock.calls.find(([name]) => name === 'restart-registry/favorites-room');
        roomConfig.edit({ attributes: { title: '' }, setAttributes: jest.fn() });

        const calls = global.wp.blockEditor.useInnerBlocksProps.mock.calls;
        const [, opts] = calls[calls.length - 1];
        expect(opts.allowedBlocks).toEqual(['restart-registry/favorites-row']);
    });
});

describe('favorites-item block: Fetch URL button', () => {
    let itemConfig;
    let setAttributes;
    let setStatus;

    function getFetchButtonProps() {
        const [, props] = global.wp.element.createElement.mock.calls.find(
            ([component]) => component === global.wp.components.Button
        );
        return props;
    }

    beforeEach(() => {
        jest.resetModules();

        global.wp = {
            blocks: { registerBlockType: jest.fn() },
            element: {
                createElement: jest.fn(() => ({})),
                useState: jest.fn((initial) => [initial, (setStatus = setStatus || jest.fn())]),
            },
            blockEditor: {
                useBlockProps: jest.fn(() => ({})),
                useInnerBlocksProps: jest.fn((props) => props),
                MediaUpload: function MediaUpload() { return null; },
                InspectorControls: function InspectorControls() { return null; },
            },
            components: {
                TextControl: function TextControl() { return null; },
                TextareaControl: function TextareaControl() { return null; },
                SelectControl: function SelectControl() { return null; },
                Button: function Button() { return null; },
                PanelBody: function PanelBody() { return null; },
            },
            i18n: { __: (s) => s },
        };

        window.restartRegistryFavoritesBlocks = { ajaxUrl: 'http://example.test/admin-ajax.php', nonce: 'test-nonce' };
        window.fetch = jest.fn();

        require('../../public/blocks/index.js');
        const [, itemConfigArg] = global.wp.blocks.registerBlockType.mock.calls.find(
            ([name]) => name === 'restart-registry/favorites-item'
        );
        itemConfig = itemConfigArg;
        setAttributes = jest.fn();
    });

    afterEach(() => {
        delete window.restartRegistryFavoritesBlocks;
        delete window.fetch;
        setStatus = undefined;
    });

    it('fills only empty attributes from a successful fetch, mapping name/price/description/image_url/retailer', async () => {
        window.fetch.mockResolvedValue({
            json: () => Promise.resolve({
                success: true,
                data: { name: 'Chef Knife', price: 39.99, description: 'A knife', image_url: 'https://img.test/knife.jpg', retailer: 'Amazon' },
            }),
        });

        itemConfig.edit({
            attributes: { title: '', price: '', description: '', images: [], retailer: '', url: 'https://example.test/knife' },
            setAttributes,
        });

        await getFetchButtonProps().onClick();
        await Promise.resolve();
        await Promise.resolve();

        expect(setAttributes).toHaveBeenCalledWith({
            title: 'Chef Knife',
            price: '39.99',
            description: 'A knife',
            images: ['https://img.test/knife.jpg'],
            retailer: 'Amazon',
        });
    });

    it('does not overwrite fields the admin already filled in by hand', async () => {
        window.fetch.mockResolvedValue({
            json: () => Promise.resolve({
                success: true,
                data: { name: 'Chef Knife', price: 39.99, description: 'A knife', image_url: 'https://img.test/knife.jpg', retailer: 'Amazon' },
            }),
        });

        itemConfig.edit({
            attributes: { title: 'My Custom Title', price: '10', description: '', images: ['https://existing.test/img.jpg'], retailer: '', url: 'https://example.test/knife' },
            setAttributes,
        });

        await getFetchButtonProps().onClick();
        await Promise.resolve();
        await Promise.resolve();

        expect(setAttributes).toHaveBeenCalledWith({
            description: 'A knife',
            retailer: 'Amazon',
        });
    });

    it('shows an error and does not call setAttributes when the URL field is empty', async () => {
        itemConfig.edit({
            attributes: { title: '', price: '', description: '', images: [], retailer: '', url: '' },
            setAttributes,
        });

        await getFetchButtonProps().onClick();

        expect(window.fetch).not.toHaveBeenCalled();
        expect(setAttributes).not.toHaveBeenCalled();
        expect(setStatus).toHaveBeenCalledWith(expect.objectContaining({ state: 'error' }));
    });

    it('shows an error when the AJAX call rejects', async () => {
        window.fetch.mockRejectedValue(new Error('network down'));

        itemConfig.edit({
            attributes: { title: '', price: '', description: '', images: [], retailer: '', url: 'https://example.test/knife' },
            setAttributes,
        });

        await getFetchButtonProps().onClick();
        await Promise.resolve();
        await Promise.resolve();

        expect(setAttributes).not.toHaveBeenCalled();
        expect(setStatus).toHaveBeenCalledWith(expect.objectContaining({ state: 'error' }));
    });

    it('shows the server-provided error message when the AJAX call succeeds but reports failure', async () => {
        window.fetch.mockResolvedValue({
            json: () => Promise.resolve({ success: false, data: { message: 'Could not reach that retailer.' } }),
        });

        itemConfig.edit({
            attributes: { title: '', price: '', description: '', images: [], retailer: '', url: 'https://example.test/knife' },
            setAttributes,
        });

        await getFetchButtonProps().onClick();
        await Promise.resolve();
        await Promise.resolve();

        expect(setAttributes).not.toHaveBeenCalled();
        expect(setStatus).toHaveBeenCalledWith({ state: 'error', message: 'Could not reach that retailer.' });
    });
});
