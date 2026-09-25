'use strict';

(function (blocks, element, blockEditor, components, i18n) {
    var el = element.createElement;
    var useState = element.useState;
    var registerBlockType = blocks.registerBlockType;
    var useBlockProps = blockEditor.useBlockProps;
    var useInnerBlocksProps = blockEditor.useInnerBlocksProps;
    var InnerBlocks = blockEditor.InnerBlocks;
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

    // Fetches product data for the given URL and fills in only the
    // attributes that are still empty — mirrors the registry page's
    // "paste a link → Fetch → autofill" flow without clobbering fields
    // an admin has already typed in by hand.
    function fetchProductData(url, attributes, setAttributes, setStatus) {
        var settings = window.restartRegistryFavoritesBlocks || {};
        if (!url) {
            setStatus({ state: 'error', message: __('Enter a product URL first.', 'restart-registry') });
            return;
        }
        if (!settings.ajaxUrl) {
            setStatus({ state: 'error', message: __('Fetch is unavailable right now.', 'restart-registry') });
            return;
        }

        setStatus({ state: 'loading', message: '' });

        return window.fetch(settings.ajaxUrl, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'restart_registry_fetch_url',
                nonce:  settings.nonce || '',
                url:    url,
            }),
        })
            .then(function (r) { return r.json(); })
            .then(function (response) {
                if (!response || !response.success) {
                    setStatus({
                        state: 'error',
                        message: (response && response.data && response.data.message) || __('Could not fetch that URL.', 'restart-registry'),
                    });
                    return;
                }

                var data = response.data || {};
                var next = {};
                if (!attributes.title && data.name) next.title = data.name;
                if (!attributes.price && data.price) next.price = String(data.price);
                if (!attributes.description && data.description) next.description = data.description;
                if ((!attributes.images || !attributes.images.length) && data.image_url) next.images = [data.image_url];
                if (!attributes.retailer && data.retailer) next.retailer = data.retailer;

                setAttributes(next);
                setStatus({ state: 'idle', message: '' });
            })
            .catch(function () {
                setStatus({ state: 'error', message: __('Could not fetch that URL.', 'restart-registry') });
            });
    }

    registerBlockType('restart-registry/favorites-item', {
        edit: function (props) {
            var attributes    = props.attributes;
            var setAttributes = props.setAttributes;
            var images        = attributes.images || [];
            var fetchStatus   = useState({ state: 'idle', message: '' });
            var status        = fetchStatus[0];
            var setStatus     = fetchStatus[1];

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
                el(
                    'div',
                    { className: 'rr-block-fetch-url' },
                    el(TextControl, { label: __('Product URL', 'restart-registry'), value: attributes.url, onChange: updateField(setAttributes, 'url') }),
                    el(Button, {
                        variant: 'secondary',
                        isBusy: status.state === 'loading',
                        disabled: status.state === 'loading',
                        onClick: function () {
                            return fetchProductData(attributes.url, attributes, setAttributes, setStatus);
                        },
                    }, status.state === 'loading' ? __('Fetching…', 'restart-registry') : __('Fetch', 'restart-registry')),
                    status.state === 'error' ? el('p', { className: 'rr-block-fetch-url__error' }, status.message) : null
                ),
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
            var innerBlocksProps = useInnerBlocksProps({}, {
                allowedBlocks: ['restart-registry/favorites-item'],
                template: [
                    ['restart-registry/favorites-item', { tier: 'save' }],
                    ['restart-registry/favorites-item', { tier: 'spend' }],
                    ['restart-registry/favorites-item', { tier: 'splurge' }],
                ],
            });

            return el(
                'div',
                useBlockProps(),
                el(TextControl, {
                    label: __('Row Title', 'restart-registry'),
                    value: attributes.title,
                    onChange: updateField(setAttributes, 'title'),
                }),
                el('div', innerBlocksProps)
            );
        },
        // Row nests favorites-item children, so save() must include InnerBlocks
        // content — otherwise the block serializer has nowhere to write the
        // children's markup and they're silently dropped from post_content.
        // No wrapper element here: render.php builds its own wrapper and
        // inserts this block's rendered $content directly into
        // .rr-favorites-row__cards, so a wrapper here would become the grid's
        // only child instead of the 3 item cards being direct grid children.
        save: function () {
            return el(InnerBlocks.Content);
        },
    });

    registerBlockType('restart-registry/favorites-room', {
        edit: function (props) {
            var attributes      = props.attributes;
            var setAttributes   = props.setAttributes;
            var innerBlocksProps = useInnerBlocksProps({}, {
                allowedBlocks: ['restart-registry/favorites-row'],
                template: [
                    ['restart-registry/favorites-row', {}],
                ],
            });

            return el(
                'div',
                useBlockProps(),
                el(TextControl, {
                    label: __('Room Title', 'restart-registry'),
                    value: attributes.title,
                    onChange: updateField(setAttributes, 'title'),
                }),
                el('div', innerBlocksProps)
            );
        },
        // Room nests favorites-row children — same InnerBlocks-serialization
        // requirement and no-wrapper reasoning as favorites-row above.
        save: function () {
            return el(InnerBlocks.Content);
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
