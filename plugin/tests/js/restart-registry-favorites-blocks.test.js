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
                useBlockProps: Object.assign(jest.fn(() => ({})), { save: jest.fn(() => ({})) }),
                useInnerBlocksProps: Object.assign(jest.fn((props) => props), { save: jest.fn((blockProps) => blockProps) }),
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

    it('leaf blocks (item, filters) define edit() and a save() that returns null (fully server-rendered)', () => {
        ['restart-registry/favorites-item', 'restart-registry/favorites-filters'].forEach((name) => {
            const [, config] = registerBlockType.mock.calls.find(([n]) => n === name);
            expect(typeof config.edit).toBe('function');
            expect(config.save()).toBeNull();
        });
    });

    it('container blocks (row, room) define edit() and a save() that serializes InnerBlocks content, not null', () => {
        ['restart-registry/favorites-row', 'restart-registry/favorites-room'].forEach((name) => {
            const [, config] = registerBlockType.mock.calls.find(([n]) => n === name);
            expect(typeof config.edit).toBe('function');
            expect(config.save()).not.toBeNull();
            expect(global.wp.blockEditor.useInnerBlocksProps.save).toHaveBeenCalled();
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
