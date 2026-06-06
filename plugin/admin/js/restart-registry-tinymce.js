/* TinyMCE plugin: Insert [restart_item] shortcode */
(function () {
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
                        var fields = ['url', 'title', 'price', 'images', 'description', 'retailer', 'notes', 'quantity'];
                        fields.forEach(function (key) {
                            var val = (d[key] || '').trim();
                            if (!val) return;
                            if (key === 'quantity' && val === '1') return;
                            // Escape double-quotes; strip [ ] which would break the shortcode parser
                            attrs += ' ' + key + '="' + val.replace(/"/g, '&quot;').replace(/[\[\]]/g, '') + '"';
                        });

                        editor.insertContent('[restart_item' + attrs + ']');
                    },
                });
            },
        });
    });
})();
