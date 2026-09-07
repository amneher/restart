'use strict';

describe('favorites blocks registration', () => {
    let registerBlockType;

    beforeEach(() => {
        jest.resetModules();
        registerBlockType = jest.fn();

        global.wp = {
            blocks: { registerBlockType },
            element: { createElement: jest.fn(() => ({})) },
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
