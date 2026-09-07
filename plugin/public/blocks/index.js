'use strict';

(function (blocks, element, blockEditor, components, i18n) {
    var el = element.createElement;
    var registerBlockType = blocks.registerBlockType;
    var useBlockProps = blockEditor.useBlockProps;
    var useInnerBlocksProps = blockEditor.useInnerBlocksProps;
    var MediaUpload = blockEditor.MediaUpload;
    var InspectorControls = blockEditor.InspectorControls;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var SelectControl = components.SelectControl;
    var Button = components.Button;
    var PanelBody = components.PanelBody;
    var __ = i18n.__;

    function updateField(setAttributes, key) {
        return function (value) {
            var next = {};
            next[key] = value;
            setAttributes(next);
        };
    }

    registerBlockType('restart-registry/favorites-item', {
        edit: function (props) {
            var attributes    = props.attributes;
            var setAttributes = props.setAttributes;
            var images        = attributes.images || [];

            return el(
                'div',
                useBlockProps(),
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        { title: __('Item Details', 'restart-registry') },
                        el(SelectControl, {
                            label: __('Tier', 'restart-registry'),
                            value: attributes.tier,
                            options: [
                                { label: __('None', 'restart-registry'), value: '' },
                                { label: __('Save', 'restart-registry'), value: 'save' },
                                { label: __('Spend', 'restart-registry'), value: 'spend' },
                                { label: __('Splurge', 'restart-registry'), value: 'splurge' },
                            ],
                            onChange: updateField(setAttributes, 'tier'),
                        }),
                        el(TextControl, { label: __('Retailer', 'restart-registry'), value: attributes.retailer, onChange: updateField(setAttributes, 'retailer') }),
                        el(TextControl, { label: __('Notes', 'restart-registry'), value: attributes.notes, onChange: updateField(setAttributes, 'notes') }),
                        el(TextControl, { label: __('Quantity', 'restart-registry'), value: attributes.quantity, onChange: updateField(setAttributes, 'quantity') })
                    )
                ),
                el(TextControl, { label: __('Title', 'restart-registry'), value: attributes.title, onChange: updateField(setAttributes, 'title') }),
                el(TextControl, { label: __('Price', 'restart-registry'), value: attributes.price, onChange: updateField(setAttributes, 'price') }),
                el(TextControl, { label: __('Product URL', 'restart-registry'), value: attributes.url, onChange: updateField(setAttributes, 'url') }),
                el(TextareaControl, { label: __('Description', 'restart-registry'), value: attributes.description, onChange: updateField(setAttributes, 'description') }),
                el(
                    'div',
                    { className: 'rr-block-images' },
                    images.map(function (url, i) {
                        return el('img', { key: i, src: url, style: { maxWidth: '80px', marginRight: '4px' } });
                    }),
                    el(MediaUpload, {
                        multiple: true,
                        gallery: true,
                        allowedTypes: ['image'],
                        onSelect: function (media) {
                            var list = Array.isArray(media) ? media : [media];
                            setAttributes({ images: list.map(function (m) { return m.url; }) });
                        },
                        render: function (obj) {
                            return el(Button, { onClick: obj.open, variant: 'secondary' }, __('Choose Image(s)', 'restart-registry'));
                        },
                    })
                )
            );
        },
        save: function () {
            return null;
        },
    });

    registerBlockType('restart-registry/favorites-row', {
        edit: function (props) {
            var attributes      = props.attributes;
            var setAttributes   = props.setAttributes;
            var innerBlocksProps = useInnerBlocksProps(useBlockProps(), {
                allowedBlocks: ['restart-registry/favorites-item'],
                template: [
                    ['restart-registry/favorites-item', { tier: 'save' }],
                    ['restart-registry/favorites-item', { tier: 'spend' }],
                    ['restart-registry/favorites-item', { tier: 'splurge' }],
                ],
            });

            return el(
                'div',
                {},
                el(TextControl, {
                    label: __('Row Title', 'restart-registry'),
                    value: attributes.title,
                    onChange: updateField(setAttributes, 'title'),
                }),
                el('div', innerBlocksProps)
            );
        },
        save: function () {
            return null;
        },
    });

    registerBlockType('restart-registry/favorites-room', {
        edit: function (props) {
            var attributes      = props.attributes;
            var setAttributes   = props.setAttributes;
            var innerBlocksProps = useInnerBlocksProps(useBlockProps(), {
                allowedBlocks: ['restart-registry/favorites-row'],
                template: [
                    ['restart-registry/favorites-row', {}],
                ],
            });

            return el(
                'div',
                {},
                el(TextControl, {
                    label: __('Room Title', 'restart-registry'),
                    value: attributes.title,
                    onChange: updateField(setAttributes, 'title'),
                }),
                el('div', innerBlocksProps)
            );
        },
        save: function () {
            return null;
        },
    });

    registerBlockType('restart-registry/favorites-filters', {
        edit: function () {
            return el(
                'p',
                { style: { fontStyle: 'italic', opacity: 0.7 } },
                __('Favorites room/tier filter bar (renders on the front end)', 'restart-registry')
            );
        },
        save: function () {
            return null;
        },
    });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
