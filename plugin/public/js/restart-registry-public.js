(function($) {
    'use strict';

    $(document).ready(function() {
        var $container = $('.rr-manage-registry, .rr-view-registry, .rr-create-form');
        if (!$container.length) return;

        var registryId = $container.data('registry-id');

        // ── Modal helpers ────────────────────────────────────────────────────
        function openModal(id) {
            var $modal = $(id);
            $modal.attr('aria-hidden', 'false').addClass('is-open');
            $('body').addClass('rr-modal-open');
            $modal.find('.rr-modal__close, .rr-modal-cancel').first().focus();
        }

        function closeModal(id) {
            var $modal = id ? $(id) : $('.rr-modal.is-open');
            $modal.attr('aria-hidden', 'true').removeClass('is-open');
            if (!$('.rr-modal.is-open').length) {
                $('body').removeClass('rr-modal-open');
            }
        }

        // Close on backdrop click or × button
        $(document).on('click', '.rr-modal__backdrop, .rr-modal__close, .rr-modal-cancel', function() {
            closeModal('#' + $(this).closest('.rr-modal').attr('id'));
        });

        // Close on ESC
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        // ── Create registry ──────────────────────────────────────────────────
        $('#rr-create-registry-form').on('submit', function(e) {
            e.preventDefault();
            var $form   = $(this);
            var $button = $form.find('button[type="submit"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_create',
                    nonce:       restartRegistry.nonce,
                    title:       $form.find('[name="title"]').val(),
                    description: $form.find('[name="description"]').val(),
                    event_type:  $form.find('[name="event_type"]').val(),
                    event_date:  $form.find('[name="event_date"]').val(),
                    is_public:   $form.find('[name="is_public"]').is(':checked') ? '1' : '0'
                },
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.data.redirect || window.location.href;
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text('Create Registry');
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Create Registry');
                }
            });
        });

        // ── Public / private toggle ──────────────────────────────────────────
        $('#rr-public-toggle').on('change', function() {
            var $toggle    = $(this);
            var $label     = $toggle.closest('.rr-toggle').find('.rr-toggle__label');
            var isPublic   = $toggle.is(':checked');

            $label.text(isPublic ? $label.data('on') : $label.data('off'));

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_update',
                    nonce:       restartRegistry.nonce,
                    registry_id: registryId,
                    is_public:   isPublic ? '1' : '0'
                },
                success: function(response) {
                    if (!response.success) {
                        // Revert on failure
                        $toggle.prop('checked', !isPublic);
                        $label.text(!isPublic ? $label.data('on') : $label.data('off'));
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                },
                error: function() {
                    $toggle.prop('checked', !isPublic);
                    $label.text(!isPublic ? $label.data('on') : $label.data('off'));
                    alert(restartRegistry.strings.error);
                }
            });
        });

        // ── Share modal ──────────────────────────────────────────────────────
        $('#rr-share-toggle').on('click', function() {
            openModal('#rr-share-modal');
        });

        $('#rr-copy-link').on('click', function() {
            var $btn = $(this);
            navigator.clipboard.writeText($('#rr-share-url').val()).then(function() {
                $btn.text('Copied!');
                setTimeout(function() { $btn.text('Copy'); }, 2000);
            });
        });

        // ── Settings modal ───────────────────────────────────────────────────
        $('#rr-edit-registry').on('click', function() {
            openModal('#rr-settings-modal');
        });

        // Recipient toggle: a UX-only checkbox the user sees ("This registry
        // is for someone else") drives a hidden is_for_self field server-side.
        // Keeping the visible label phrased positively while the data field
        // stores the inverse means default-true is_for_self stays simple.
        $(document).on('change', '#rr-edit-not-for-self', function() {
            var notForSelf = $(this).is(':checked');
            $('#rr-edit-is-for-self').val(notForSelf ? '0' : '1');
            $('#rr-edit-recipient-fields').prop('hidden', !notForSelf);
        });

        // Hero image picker — opens the WP media library, captures the
        // attachment id into a hidden input. Server applies it via
        // set_post_thumbnail() on save.
        var heroFrame = null;
        $(document).on('click', '#rr-hero-pick', function(e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) {
                alert('Media library not available — refresh the page and try again.');
                return;
            }
            if (heroFrame) { heroFrame.open(); return; }
            heroFrame = wp.media({
                title:    restartRegistry.strings.heroPickerTitle,
                button:   { text: restartRegistry.strings.heroPickerCta },
                library:  { type: 'image' },
                multiple: false
            });
            heroFrame.on('select', function() {
                var attachment = heroFrame.state().get('selection').first().toJSON();
                $('#rr-edit-hero-image-id').val(attachment.id);
                var $preview = $('#rr-hero-preview');
                $preview.removeClass('is-empty').empty().append(
                    $('<img>').attr('src', attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url)
                );
                $('#rr-hero-clear').prop('hidden', false);
            });
            heroFrame.open();
        });
        $(document).on('click', '#rr-hero-clear', function() {
            $('#rr-edit-hero-image-id').val('');
            $('#rr-hero-preview').addClass('is-empty').empty().append(
                $('<span class="rr-hero-picker__empty">No image set</span>')
            );
            $(this).prop('hidden', true);
        });

        $('#rr-edit-registry-form').on('submit', function(e) {
            e.preventDefault();
            var $form   = $(this);
            var $button = $form.find('button[type="submit"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:                 'restart_registry_update',
                    nonce:                  restartRegistry.nonce,
                    registry_id:            registryId,
                    title:                  $form.find('[name="title"]').val(),
                    description:            $form.find('[name="description"]').val(),
                    event_type:             $form.find('[name="event_type"]').val(),
                    event_date:             $form.find('[name="event_date"]').val(),
                    is_public:              $form.find('[name="is_public"]').is(':checked') ? '1' : '0',
                    is_for_self:            $form.find('[name="is_for_self"]').val(),
                    recipient_name:         $form.find('[name="recipient_name"]').val() || '',
                    recipient_relationship: $form.find('[name="recipient_relationship"]').val() || '',
                    recipient_email:        $form.find('[name="recipient_email"]').val() || '',
                    hero_image_id:          $form.find('[name="hero_image_id"]').val() || ''
                },
                success: function(response) {
                    if (response.success) {
                        closeModal('#rr-settings-modal');
                        showNotice(response.data.message, 'success');
                        setTimeout(function() { window.location.reload(); }, 1200);
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text('Save Changes');
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Save Changes');
                }
            });
        });

        // ── Add item panel toggle ────────────────────────────────────────────
        $(document).on('click', '#rr-add-item-toggle', function() {
            $('#rr-add-item-panel').slideDown(200);
            $(this).hide();
            $('#rr-item-url').focus();
        });

        $(document).on('click', '#rr-add-item-cancel', function() {
            $('#rr-add-item-panel').slideUp(200);
            $('#rr-add-item-toggle').show();
            $('#rr-add-item-form')[0].reset();
        });

        // ── Fetch URL details ────────────────────────────────────────────────
        $(document).on('click', '#rr-fetch-url', function() {
            var $button = $(this);
            var url = $('#rr-item-url').val();
            if (!url) return;

            $button.prop('disabled', true).text('…');

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: { action: 'restart_registry_fetch_url', nonce: restartRegistry.nonce, url: url },
                success: function(response) {
                    if (response.success) {
                        if (response.data.name)        $('#rr-item-name').val(response.data.name);
                        if (response.data.price)       $('#rr-item-price').val(response.data.price);
                        $('#rr-item-image-url').val(response.data.image_url || '');
                        if (response.data.description) $('#rr-item-description').val(response.data.description);
                        $('#rr-item-name').focus();
                    }
                    $button.prop('disabled', false).text('Fetch');
                },
                error: function() { $button.prop('disabled', false).text('Fetch'); }
            });
        });

        $(document).on('blur', '#rr-item-url', function() {
            if ($(this).val() && !$('#rr-item-name').val()) {
                $('#rr-fetch-url').trigger('click');
            }
        });

        // ── Add item ─────────────────────────────────────────────────────────
        $('#rr-add-item-form').on('submit', function(e) {
            e.preventDefault();
            var $form   = $(this);
            var $button = $form.find('button[type="submit"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_add_item',
                    nonce:       restartRegistry.nonce,
                    registry_id: registryId,
                    name:        $form.find('[name="name"]').val(),
                    url:         $form.find('[name="url"]').val(),
                    description: $form.find('[name="description"]').val(),
                    price:       $form.find('[name="price"]').val(),
                    quantity:    $form.find('[name="quantity"]').val(),
                    image_url:   $form.find('[name="image_url"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        var $list = $('#rr-items-container');
                        $list.find('.rr-no-items').remove();
                        if (!$list.find('.rr-item-list').length) {
                            $list.html('<ul class="rr-item-list"></ul>');
                        }
                        $list.find('.rr-item-list').append(response.data.html);
                        $form[0].reset();
                        $('#rr-add-item-panel').slideUp(200);
                        $('#rr-add-item-toggle').show();
                        updateItemCount();
                        showNotice(response.data.is_affiliate
                            ? 'Added! Affiliate link from ' + response.data.retailer + '.'
                            : 'Item added.', 'success');
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                    $button.prop('disabled', false).text('Add');
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Add');
                }
            });
        });

        // ── Delete item ──────────────────────────────────────────────────────
        $(document).on('click', '.rr-delete-item', function() {
            if (!confirm(restartRegistry.strings.confirmDelete)) return;

            var $row   = $(this).closest('.rr-item-row, .rr-item-card');
            var itemId = $row.data('item-id');

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_delete_item',
                    nonce:       restartRegistry.nonce,
                    item_id:     itemId,
                    registry_id: registryId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(250, function() {
                            $(this).remove();
                            updateItemCount();
                            if (!$('#rr-items-container .rr-item-row, #rr-items-container .rr-item-card').length) {
                                $('#rr-items-container').html('<p class="rr-no-items">No items yet — add something you need to restart.</p>');
                            }
                        });
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                },
                error: function() { alert(restartRegistry.strings.error); }
            });
        });

        // ── Edit item (modal) ────────────────────────────────────────────────
        $(document).on('click', '.rr-edit-item', function() {
            var $row = $(this).closest('.rr-item-row, .rr-item-card');
            $('#rr-edit-item-id').val($row.data('item-id'));
            $('#rr-edit-item-name').val($row.data('name'));
            $('#rr-edit-item-url').val($row.data('url'));
            $('#rr-edit-item-price').val($row.data('price'));
            $('#rr-edit-item-quantity').val($row.data('quantity') || 1);
            $('#rr-edit-item-description').val($row.data('description'));
            $('#rr-edit-item-image-url').val($row.data('image-url'));
            openModal('#rr-item-edit-modal');
        });

        $('#rr-edit-item-form').on('submit', function(e) {
            e.preventDefault();
            var $form   = $(this);
            var $button = $form.find('button[type="submit"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_update_item',
                    nonce:       restartRegistry.nonce,
                    item_id:     $('#rr-edit-item-id').val(),
                    registry_id: registryId,
                    name:        $('#rr-edit-item-name').val(),
                    url:         $('#rr-edit-item-url').val(),
                    price:       $('#rr-edit-item-price').val(),
                    quantity:    $('#rr-edit-item-quantity').val(),
                    description: $('#rr-edit-item-description').val(),
                    image_url:   $('#rr-edit-item-image-url').val()
                },
                success: function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text(restartRegistry.strings.save || 'Save Changes');
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text(restartRegistry.strings.save || 'Save Changes');
                }
            });
        });

        // ── Item detail modal ────────────────────────────────────────────────
        $(document).on('click', '.rr-item-name-btn', function() {
            var $row         = $(this).closest('.rr-item-row, .rr-item-card');
            var name         = $row.data('name');
            var imageUrl     = $row.data('image-url');
            var retailer     = $row.data('retailer');
            var price        = $row.data('price');
            var description  = $row.data('description');
            var qtyNeeded    = $row.data('quantity') || 1;
            var qtyPurchased = $row.data('qty-purchased') || 0;
            var affiliateUrl = $row.data('affiliate-url');
            var isFulfilled  = $row.hasClass('rr-item-row--fulfilled') || $row.hasClass('rr-item-fulfilled');
            var isGuestView  = $row.closest('.rr-view-registry').length > 0;
            var $modal       = $('#rr-item-detail-modal');

            $modal.find('.rr-item-detail__title').text(name);

            if (imageUrl) {
                $modal.find('.rr-item-detail__image').attr({ src: imageUrl, alt: name });
                $modal.find('.rr-item-detail__image-wrap').show();
            } else {
                $modal.find('.rr-item-detail__image-wrap').hide();
            }

            var meta = '';
            if (retailer) meta += '<span class="rr-item-retailer">' + $('<span>').text(retailer).html() + '</span>';
            if (price)    meta += '<span class="rr-item-price rr-item-detail__price">$' + parseFloat(price).toFixed(2) + '</span>';
            $modal.find('.rr-item-detail__meta').html(meta);

            if (description) {
                $modal.find('.rr-item-detail__description').text(description).show();
            } else {
                $modal.find('.rr-item-detail__description').hide();
            }

            var remaining = parseInt(qtyNeeded) - parseInt(qtyPurchased);
            $modal.find('.rr-item-detail__qty-row').html(
                '<span class="rr-item-detail__qty-label">Needed</span> <strong>' + qtyNeeded + '</strong>'
                + ' &nbsp;&middot;&nbsp; '
                + '<span class="rr-item-detail__qty-label">Purchased</span> <strong>' + qtyPurchased + '</strong>'
                + (remaining > 0 ? ' &nbsp;&middot;&nbsp; <span class="rr-item-detail__qty-label">Remaining</span> <strong>' + remaining + '</strong>' : '')
            );

            if (affiliateUrl && !isFulfilled) {
                $modal.find('.rr-item-detail__purchase-btn').attr('href', affiliateUrl).show();
            } else {
                $modal.find('.rr-item-detail__purchase-btn').hide();
            }

            if (isGuestView && !isFulfilled) {
                $modal.find('.rr-item-detail__mark-btn').data('item-id', $row.data('item-id')).show();
            } else {
                $modal.find('.rr-item-detail__mark-btn').hide();
            }

            openModal('#rr-item-detail-modal');
        });

        // ── Mark as purchased (guest view) ───────────────────────────────────
        $(document).on('click', '.rr-mark-purchased', function() {
            var $btn  = $(this);
            var $card = $btn.closest('.rr-item-card, .rr-item-row');
            var itemId = $card.length ? $card.data('item-id') : $btn.data('item-id');
            var name   = $card.length ? $card.data('name') : '';

            closeModal('#rr-item-detail-modal');
            $('#rr-purchase-item-id').val(itemId);
            $('#rr-purchase-modal .rr-purchase-modal__item-name').text(name || '');
            $('#rr-purchase-form')[0].reset();
            openModal('#rr-purchase-modal');
        });

        $('#rr-purchase-form').on('submit', function(e) {
            e.preventDefault();
            var $form      = $(this);
            var $button    = $form.find('button[type="submit"]');
            var buyerName  = $.trim($form.find('[name="purchaser_name"]').val());
            var buyerNote  = $.trim($form.find('[name="purchaser_note"]').val());

            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:          'restart_registry_mark_purchased',
                    nonce:           restartRegistry.nonce,
                    item_id:         $('#rr-purchase-item-id').val(),
                    quantity:        1,
                    purchaser_name:  buyerName,
                    purchaser_note:  buyerNote,
                    is_anonymous:    buyerName ? '0' : '1'
                },
                success: function(response) {
                    if (response.success) {
                        closeModal('#rr-purchase-modal');
                        showNotice(response.data.message, 'success');
                        setTimeout(function() { window.location.reload(); }, 1500);
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text('Confirm Purchase');
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Confirm Purchase');
                }
            });
        });

        // ── Notification preferences ─────────────────────────────────────────
        $(document).on('change', '#rr-notify-purchase', function() {
            var $checkbox = $(this);
            var $status   = $('#rr-notify-prefs-status');

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:             'restart_registry_update_notification_prefs',
                    nonce:              restartRegistry.nonce,
                    notify_on_purchase: $checkbox.is(':checked') ? '1' : '0'
                },
                success: function(response) {
                    $status.text(response.success
                        ? restartRegistry.strings.prefsSaved
                        : (response.data.message || restartRegistry.strings.error));
                    setTimeout(function() { $status.text(''); }, 3000);
                },
                error: function() {
                    $status.text(restartRegistry.strings.error);
                    setTimeout(function() { $status.text(''); }, 3000);
                }
            });
        });

        // ── Send invite ──────────────────────────────────────────────────────
        $('#rr-send-invite-form').on('submit', function(e) {
            e.preventDefault();
            var $form    = $(this);
            var $button  = $form.find('button[type="submit"]');
            var $invitee = $form.find('[name="invitee"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_send_invite',
                    nonce:       restartRegistry.nonce,
                    registry_id: registryId,
                    invitee:     $invitee.val()
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        $invitee.val('');
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                    $button.prop('disabled', false).text('Send Invite');
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Send Invite');
                }
            });
        });

        // ── Helpers ──────────────────────────────────────────────────────────
        function updateItemCount() {
            var count = $('#rr-items-container .rr-item-row, #rr-items-container .rr-item-card').length;
            $('.rr-item-count').text('(' + count + ')');
        }

        function showNotice(message, type) {
            var $notice = $('<div class="rr-notice rr-notice-' + type + '">' + message + '</div>');
            $container.prepend($notice);
            setTimeout(function() {
                $notice.fadeOut(300, function() { $(this).remove(); });
            }, 4000);
        }
    });

})(jQuery);
