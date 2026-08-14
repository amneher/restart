/* TinyMCE plugin: Insert [restart_item] and [restart_favorites_row] shortcodes */
(function () {
    /** Escapes a field value for use inside a shortcode attribute. */
    function attrValue(raw) {
        return (raw || '').trim().replace(/"/g, '&quot;').replace(/[\[\]]/g, '');
    }

    /** Builds ' key="value"' pairs for every non-empty field in `data`, in `fields` order. */
    function buildAttrs(data, fields) {
        var attrs = '';
        fields.forEach(function (key) {
            var val = attrValue(data[key]);
            if (!val) return;
            attrs += ' ' + key + '="' + val + '"';
        });
        return attrs;
    }

    /**
     * Calls the existing restart_registry_fetch_url AJAX action (same endpoint
     * the public registry-builder "fetch URL" flow uses) to scrape product
     * data from a URL. onDone receives the response data object, or null.
     */
    function fetchProductData(url, onDone) {
        if (!url || !url.trim() || typeof rrAdmin === 'undefined' || !rrAdmin.ajaxurl) {
            onDone(null);
            return;
        }
        fetch(rrAdmin.ajaxurl, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'restart_registry_fetch_url',
                nonce:  rrAdmin.fetchNonce,
                url:    url.trim(),
            }),
        })
            .then(function (r) { return r.json(); })
            .then(function (res) { onDone(res && res.success ? res.data : null); })
            .catch(function () { onDone(null); });
    }

    tinymce.PluginManager.add('restart_item', function (editor) {
        editor.addButton('restart_item', {
            text: 'Insert Item',
            icon: false,
            tooltip: 'Insert [restart_item] shortcode',
            onclick: function () {
                editor.windowManager.open({
                    title: 'Insert Item Card',
                    minWidth: 480,
                    body: [
                        {
                            type: 'textbox',
                            name: 'url',
                            label: 'Product URL',
                            value: '',
                        },
                        {
                            type: 'textbox',
                            name: 'title',
                            label: 'Title *',
                            value: '',
                        },
                        {
                            type: 'textbox',
                            name: 'price',
                            label: 'Price (e.g. 49.99)',
                            value: '',
                        },
                        {
                            type: 'textbox',
                            name: 'images',
                            label: 'Image URL(s) — comma-separated for carousel',
                            value: '',
                        },
                        {
                            type: 'textbox',
                            name: 'description',
                            label: 'Description',
                            multiline: true,
                            minHeight: 60,
                            value: '',
                        },
                        {
                            type: 'textbox',
                            name: 'retailer',
                            label: 'Retailer',
                            value: '',
                        },
                        {
                            type: 'textbox',
                            name: 'notes',
                            label: 'Notes',
                            value: '',
                        },
                        {
                            type: 'textbox',
                            name: 'quantity',
                            label: 'Quantity',
                            value: '1',
                        },
                    ],
                    onsubmit: function (e) {
                        var d = e.data;

                        if (!d.title || !d.title.trim()) {
                            editor.windowManager.alert('Title is required.');
                            return false;
                        }

                        var attrs = '';
                        ['url', 'title', 'price', 'images', 'description', 'retailer', 'notes', 'quantity'].forEach(function (key) {
                            var val = attrValue(d[key]);
                            if (!val) return;
                            if (key === 'quantity' && val === '1') return;
                            attrs += ' ' + key + '="' + val + '"';
                        });

                        editor.insertContent('[restart_item' + attrs + ']');
                    },
                });
            },
        });

        editor.addButton('restart_favorites_row', {
            text: 'Insert Favorites Row',
            icon: false,
            tooltip: 'Insert a Good/Better/Best product row for the Our Favorites page',
            onclick: function () {
                var win; // assigned below; referenced by the Fetch button closures, which only run after the modal is open

                function tierSection(tier, label) {
                    return [
                        { type: 'label', text: label, style: 'font-weight:bold; margin-top: 14px; display: block;' },
                        { type: 'textbox', name: tier + '_url', label: 'Product URL' },
                        {
                            type: 'button',
                            text: 'Fetch product info',
                            onclick: function () {
                                var btn      = this;
                                var urlField = win.find('#' + tier + '_url')[0];
                                var url      = urlField ? urlField.value() : '';

                                if (!url || !url.trim()) {
                                    editor.windowManager.alert('Enter a product URL first.');
                                    return;
                                }

                                btn.disabled(true).text('Fetching…');
                                fetchProductData(url, function (data) {
                                    btn.disabled(false).text('Fetch product info');
                                    if (!data) {
                                        editor.windowManager.alert('Could not fetch product data for that URL.');
                                        return;
                                    }
                                    if (data.name)        win.find('#' + tier + '_title')[0].value(data.name);
                                    if (data.price)       win.find('#' + tier + '_price')[0].value(data.price);
                                    if (data.image_url)   win.find('#' + tier + '_image')[0].value(data.image_url);
                                    if (data.retailer)    win.find('#' + tier + '_retailer')[0].value(data.retailer);
                                    if (data.description) win.find('#' + tier + '_description')[0].value(data.description);
                                });
                            },
                        },
                        { type: 'textbox', name: tier + '_title', label: 'Title *' },
                        { type: 'textbox', name: tier + '_price', label: 'Price (e.g. 49.99)' },
                        { type: 'textbox', name: tier + '_image', label: 'Image URL' },
                        { type: 'textbox', name: tier + '_retailer', label: 'Retailer' },
                        { type: 'textbox', name: tier + '_description', label: 'Description', multiline: true, minHeight: 40 },
                    ];
                }

                var body = [
                    { type: 'textbox', name: 'item_title', label: 'General Item Title * (e.g. "Sofa")' },
                ].concat(
                    tierSection('good', 'GOOD'),
                    tierSection('better', 'BETTER'),
                    tierSection('best', 'BEST')
                );

                win = editor.windowManager.open({
                    title: 'Insert Favorites Row (Good / Better / Best)',
                    minWidth: 520,
                    minHeight: 560,
                    body: body,
                    onsubmit: function (e) {
                        var d = e.data;
                        var itemTitle = (d.item_title || '').trim();

                        if (!itemTitle) {
                            editor.windowManager.alert('General Item Title is required.');
                            return false;
                        }

                        var tiers = ['good', 'better', 'best'];
                        var cards = '';
                        var any   = false;

                        tiers.forEach(function (tier) {
                            var title = (d[tier + '_title'] || '').trim();
                            if (!title) return;
                            any = true;

                            var tierData = {
                                tier:        tier,
                                title:       d[tier + '_title'],
                                price:       d[tier + '_price'],
                                image:       d[tier + '_image'],
                                retailer:    d[tier + '_retailer'],
                                description: d[tier + '_description'],
                                url:         d[tier + '_url'],
                            };
                            var attrs = buildAttrs(tierData, ['tier', 'title', 'price', 'image', 'retailer', 'description', 'url']);
                            cards += '[restart_item' + attrs + ']';
                        });

                        if (!any) {
                            editor.windowManager.alert('Add at least one product (Good, Better, or Best).');
                            return false;
                        }

                        var rowAttrs = ' title="' + attrValue(itemTitle) + '"';
                        editor.insertContent('[restart_favorites_row' + rowAttrs + ']' + cards + '[/restart_favorites_row]');
                    },
                });
            },
        });
    });
})();
