'use strict';

(function() {

    var container = document.querySelector('.rr-manage-registry, .rr-view-registry, .rr-create-form');
    if (!container) return;

    var registryId = container.dataset.registryId;

    // ── Helpers ──────────────────────────────────────────────────────────────

    function post(url, data) {
        return fetch(url, { method: 'POST', body: new URLSearchParams(data) })
            .then(function(r) { return r.json(); });
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function slideDown(el, duration) {
        el.style.overflow = 'hidden';
        el.style.maxHeight = '0';
        el.removeAttribute('hidden');
        el.style.display = '';
        var h = el.scrollHeight;
        void el.offsetHeight;
        el.style.transition = 'max-height ' + duration + 'ms ease';
        el.style.maxHeight = h + 'px';
        setTimeout(function() {
            el.style.maxHeight = '';
            el.style.overflow = '';
            el.style.transition = '';
        }, duration);
    }

    function slideUp(el, duration, callback) {
        el.style.overflow = 'hidden';
        el.style.maxHeight = el.scrollHeight + 'px';
        void el.offsetHeight;
        el.style.transition = 'max-height ' + duration + 'ms ease';
        el.style.maxHeight = '0';
        setTimeout(function() {
            el.style.display = 'none';
            el.style.maxHeight = '';
            el.style.overflow = '';
            el.style.transition = '';
            if (callback) callback();
        }, duration);
    }

    function updateItemCount() {
        var count = document.querySelectorAll('#rr-items-container .rr-item-row, #rr-items-container .rr-item-card').length;
        document.querySelectorAll('.rr-item-count').forEach(function(el) {
            el.textContent = '(' + count + ')';
        });
    }

    function showNotice(message, type) {
        var notice = document.createElement('div');
        notice.className = 'rr-notice rr-notice-' + type;
        notice.innerHTML = message;
        container.insertAdjacentElement('afterbegin', notice);
        setTimeout(function() {
            notice.style.transition = 'opacity 300ms';
            notice.style.opacity = '0';
            setTimeout(function() { notice.remove(); }, 300);
        }, 4000);
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────

    function openModal(id) {
        var modal = document.querySelector(id);
        modal.setAttribute('aria-inert', 'false');
        modal.classList.add('is-open');
        document.body.classList.add('rr-modal-open');
        var first = modal.querySelector('.rr-modal__close, .rr-modal-cancel');
        if (first) first.focus();
    }

    function closeModal(id) {
        var modals = id
            ? [document.querySelector(id)]
            : Array.from(document.querySelectorAll('.rr-modal.is-open'));
        modals.forEach(function(modal) {
            if (!modal) return;
            modal.setAttribute('aria-inert', 'true');
            modal.classList.remove('is-open');
        });
        if (!document.querySelector('.rr-modal.is-open')) {
            document.body.classList.remove('rr-modal-open');
        }
    }

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.rr-modal__backdrop, .rr-modal__close, .rr-modal-cancel');
        if (!trigger) return;
        var modal = trigger.closest('.rr-modal');
        if (modal) closeModal('#' + modal.id);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    // ── Create registry ───────────────────────────────────────────────────────

    var createForm = document.getElementById('rr-create-registry-form');
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = createForm.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = restartRegistry.strings.loading;

            post(restartRegistry.ajaxUrl, {
                action:      'restart_registry_create',
                nonce:       restartRegistry.nonce,
                title:       createForm.querySelector('[name="title"]').value,
                description: createForm.querySelector('[name="description"]').value,
                event_type:  createForm.querySelector('[name="event_type"]').value,
                event_date:  createForm.querySelector('[name="event_date"]').value,
                is_public:   createForm.querySelector('[name="is_public"]').checked ? '1' : '0',
            }).then(function(response) {
                if (response.success) {
                    window.location.href = response.data.redirect || window.location.href;
                } else {
                    alert(response.data.message || restartRegistry.strings.error);
                    btn.disabled = false;
                    btn.textContent = 'Create Registry';
                }
            }).catch(function() {
                alert(restartRegistry.strings.error);
                btn.disabled = false;
                btn.textContent = 'Create Registry';
            });
        });
    }

    // ── Public / private toggle ───────────────────────────────────────────────

    var publicToggle = document.getElementById('rr-public-toggle');
    if (publicToggle) {
        publicToggle.addEventListener('change', function() {
            var toggle   = this;
            var label    = toggle.closest('.rr-toggle').querySelector('.rr-toggle__label');
            var isPublic = toggle.checked;

            label.textContent = isPublic ? label.dataset.on : label.dataset.off;

            post(restartRegistry.ajaxUrl, {
                action:      'restart_registry_update',
                nonce:       restartRegistry.nonce,
                registry_id: registryId,
                is_public:   isPublic ? '1' : '0',
            }).then(function(response) {
                if (!response.success) {
                    toggle.checked = !isPublic;
                    label.textContent = !isPublic ? label.dataset.on : label.dataset.off;
                    alert(response.data.message || restartRegistry.strings.error);
                }
            }).catch(function() {
                toggle.checked = !isPublic;
                label.textContent = !isPublic ? label.dataset.on : label.dataset.off;
                alert(restartRegistry.strings.error);
            });
        });
    }

    // ── Public-toggle help modal ──────────────────────────────────────────────

    var publicHelpToggle = document.getElementById('rr-public-help-toggle');
    if (publicHelpToggle) {
        publicHelpToggle.addEventListener('click', function() { openModal('#rr-public-help-modal'); });
    }

    // ── Share modal ───────────────────────────────────────────────────────────

    var shareToggle = document.getElementById('rr-share-toggle');
    if (shareToggle) {
        shareToggle.addEventListener('click', function() { openModal('#rr-share-modal'); });
    }

    var copyLink = document.getElementById('rr-copy-link');
    if (copyLink) {
        copyLink.addEventListener('click', function() {
            var btn = this;
            navigator.clipboard.writeText(document.getElementById('rr-share-url').value).then(function() {
                btn.textContent = 'Copied!';
                setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
            });
        });
    }

    // ── Settings modal ────────────────────────────────────────────────────────

    var editRegistry = document.getElementById('rr-edit-registry');
    if (editRegistry) {
        editRegistry.addEventListener('click', function() { openModal('#rr-settings-modal'); });
    }

    // Archive flow
    var archiveBtn = document.getElementById('rr-archive-registry-btn');
    if (archiveBtn) {
        archiveBtn.addEventListener('click', function() {
            closeModal('#rr-settings-modal');
            openModal('#rr-archive-confirm-modal');
        });
    }

    var archiveConfirmBtn = document.getElementById('rr-archive-confirm-btn');
    if (archiveConfirmBtn) {
        archiveConfirmBtn.addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Archiving…';
            post(restartRegistry.ajaxUrl, {
                action:      'restart_registry_archive',
                nonce:       restartRegistry.nonce,
                registry_id: registryId,
            }).then(function() {
                window.location.href = restartRegistry.myRegistriesUrl || '/my-registries/';
            }).catch(function() {
                btn.disabled = false;
                btn.textContent = 'Archive Registry';
                showNotice('Could not archive the registry. Please try again.', 'error');
            });
        });
    }

    // Delete flow
    var deleteRegistryBtn = document.getElementById('rr-delete-registry-btn');
    if (deleteRegistryBtn) {
        deleteRegistryBtn.addEventListener('click', function() {
            closeModal('#rr-settings-modal');
            document.getElementById('rr-delete-understand').checked = false;
            document.getElementById('rr-delete-confirm-btn').disabled = true;
            openModal('#rr-delete-confirm-modal');
        });
    }

    var deleteUnderstand = document.getElementById('rr-delete-understand');
    if (deleteUnderstand) {
        deleteUnderstand.addEventListener('change', function() {
            document.getElementById('rr-delete-confirm-btn').disabled = !this.checked;
        });
    }

    var deleteConfirmBtn = document.getElementById('rr-delete-confirm-btn');
    if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Deleting…';
            post(restartRegistry.ajaxUrl, {
                action:      'restart_registry_delete',
                nonce:       restartRegistry.nonce,
                registry_id: registryId,
                confirm:     '1',
            }).then(function(res) {
                window.location.href = (res.data && res.data.redirect) ? res.data.redirect : (restartRegistry.myRegistriesUrl || '/my-registries/');
            }).catch(function() {
                btn.disabled = false;
                btn.textContent = 'Permanently Delete';
                showNotice('Could not delete the registry. Please try again.', 'error');
            });
        });
    }

    // Recipient toggle
    document.addEventListener('change', function(e) {
        var cb = e.target.closest('#rr-edit-not-for-self');
        if (!cb) return;
        var notForSelf = cb.checked;
        document.getElementById('rr-edit-is-for-self').value = notForSelf ? '0' : '1';
        document.getElementById('rr-edit-recipient-fields').hidden = !notForSelf;
    });

    // Hero image picker
    var heroFrame = null;
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-hero-pick')) return;
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
            multiple: false,
        });
        heroFrame.on('select', function() {
            var attachment = heroFrame.state().get('selection').first().toJSON();
            document.getElementById('rr-edit-hero-image-id').value = attachment.id;
            var preview = document.getElementById('rr-hero-preview');
            preview.classList.remove('is-empty');
            preview.innerHTML = '';
            var img = document.createElement('img');
            img.src = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
            preview.appendChild(img);
            document.getElementById('rr-hero-clear').hidden = false;
        });
        heroFrame.open();
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-hero-clear')) return;
        document.getElementById('rr-edit-hero-image-id').value = '';
        var preview = document.getElementById('rr-hero-preview');
        preview.classList.add('is-empty');
        preview.innerHTML = '<span class="rr-hero-picker__empty">No image set</span>';
        e.target.closest('#rr-hero-clear').hidden = true;
    });

    var editRegistryForm = document.getElementById('rr-edit-registry-form');
    if (editRegistryForm) {
        editRegistryForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = editRegistryForm.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = restartRegistry.strings.loading;

            post(restartRegistry.ajaxUrl, {
                action:                 'restart_registry_update',
                nonce:                  restartRegistry.nonce,
                registry_id:            registryId,
                title:                  editRegistryForm.querySelector('[name="title"]').value,
                description:            editRegistryForm.querySelector('[name="description"]').value,
                event_type:             editRegistryForm.querySelector('[name="event_type"]').value,
                event_date:             editRegistryForm.querySelector('[name="event_date"]').value,
                is_public:              editRegistryForm.querySelector('[name="is_public"]').checked ? '1' : '0',
                is_for_self:            editRegistryForm.querySelector('[name="is_for_self"]').value,
                recipient_name:         editRegistryForm.querySelector('[name="recipient_name"]').value || '',
                recipient_relationship: editRegistryForm.querySelector('[name="recipient_relationship"]').value || '',
                recipient_email:        editRegistryForm.querySelector('[name="recipient_email"]').value || '',
                hero_image_id:          editRegistryForm.querySelector('[name="hero_image_id"]').value || '',
            }).then(function(response) {
                if (response.success) {
                    closeModal('#rr-settings-modal');
                    showNotice(response.data.message, 'success');
                    setTimeout(function() { window.location.reload(); }, 1200);
                } else {
                    alert(response.data.message || restartRegistry.strings.error);
                    btn.disabled = false;
                    btn.textContent = 'Save Changes';
                }
            }).catch(function() {
                alert(restartRegistry.strings.error);
                btn.disabled = false;
                btn.textContent = 'Save Changes';
            });
        });
    }

    // ── Add item panel toggle ─────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-add-item-toggle')) return;
        var panel  = document.getElementById('rr-add-item-panel');
        var toggle = document.getElementById('rr-add-item-toggle');
        slideDown(panel, 200);
        toggle.style.display = 'none';
        document.getElementById('rr-item-url').focus();
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-add-item-cancel')) return;
        var panel  = document.getElementById('rr-add-item-panel');
        var toggle = document.getElementById('rr-add-item-toggle');
        slideUp(panel, 200);
        toggle.style.display = '';
        document.getElementById('rr-add-item-form').reset();
    });

    // ── Fetch URL details ─────────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-fetch-url')) return;
        var btn = e.target.closest('#rr-fetch-url');
        var url = document.getElementById('rr-item-url').value;
        if (!url) return;

        btn.disabled = true;
        btn.textContent = '…';

        post(restartRegistry.ajaxUrl, {
            action: 'restart_registry_fetch_url',
            nonce:  restartRegistry.nonce,
            url:    url,
        }).then(function(response) {
            if (response.success) {
                if (response.data.name)        document.getElementById('rr-item-name').value = response.data.name;
                if (response.data.price)       document.getElementById('rr-item-price').value = response.data.price;
                document.getElementById('rr-item-image-url').value = response.data.image_url || '';
                if (response.data.description) document.getElementById('rr-item-description').value = response.data.description;
                document.getElementById('rr-item-name').focus();
            }
            btn.disabled = false;
            btn.textContent = 'Fetch';
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = 'Fetch';
        });
    });

    document.addEventListener('blur', function(e) {
        if (!e.target.closest('#rr-item-url')) return;
        var urlInput = document.getElementById('rr-item-url');
        if (urlInput.value && !document.getElementById('rr-item-name').value) {
            document.getElementById('rr-fetch-url').click();
        }
    }, true);

    // ── Add item ──────────────────────────────────────────────────────────────

    var addItemForm = document.getElementById('rr-add-item-form');
    if (addItemForm) {
        addItemForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = addItemForm.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = restartRegistry.strings.loading;

            post(restartRegistry.ajaxUrl, {
                action:      'restart_registry_add_item',
                nonce:       restartRegistry.nonce,
                registry_id: registryId,
                name:        addItemForm.querySelector('[name="name"]').value,
                url:         addItemForm.querySelector('[name="url"]').value,
                description: addItemForm.querySelector('[name="description"]').value,
                notes:       addItemForm.querySelector('[name="notes"]').value,
                price:       addItemForm.querySelector('[name="price"]').value,
                quantity:    addItemForm.querySelector('[name="quantity"]').value,
                image_url:   addItemForm.querySelector('[name="image_url"]').value,
            }).then(function(response) {
                if (response.success) {
                    var list = document.getElementById('rr-items-container');
                    var noItems = list.querySelector('.rr-no-items');
                    if (noItems) noItems.remove();
                    if (!list.querySelector('.rr-item-list')) {
                        list.innerHTML = '<ul class="rr-item-list"></ul>';
                    }
                    list.querySelector('.rr-item-list').insertAdjacentHTML('beforeend', response.data.html);
                    addItemForm.reset();
                    slideUp(document.getElementById('rr-add-item-panel'), 200);
                    document.getElementById('rr-add-item-toggle').style.display = '';
                    updateItemCount();
                    showNotice(response.data.is_affiliate
                        ? 'Added! Affiliate link from ' + escHtml(response.data.retailer) + '.'
                        : 'Item added.', 'success');
                } else {
                    alert(response.data.message || restartRegistry.strings.error);
                }
                btn.disabled = false;
                btn.textContent = 'Add';
            }).catch(function() {
                alert(restartRegistry.strings.error);
                btn.disabled = false;
                btn.textContent = 'Add';
            });
        });
    }

    // ── Delete item ───────────────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.rr-delete-item');
        if (!trigger) return;
        if (!confirm(restartRegistry.strings.confirmDelete)) return;

        var row    = trigger.closest('.rr-item-row, .rr-item-card');
        var itemId = row.dataset.itemId;

        post(restartRegistry.ajaxUrl, {
            action:      'restart_registry_delete_item',
            nonce:       restartRegistry.nonce,
            item_id:     itemId,
            registry_id: registryId,
        }).then(function(response) {
            if (response.success) {
                row.style.transition = 'opacity 250ms';
                row.style.opacity = '0';
                setTimeout(function() {
                    row.remove();
                    updateItemCount();
                    var itemsContainer = document.getElementById('rr-items-container');
                    if (!itemsContainer.querySelector('.rr-item-row, .rr-item-card')) {
                        itemsContainer.innerHTML = '<p class="rr-no-items">No items yet — add something you need to restart.</p>';
                    }
                }, 250);
            } else {
                alert(response.data.message || restartRegistry.strings.error);
            }
        }).catch(function() { alert(restartRegistry.strings.error); });
    });

    // ── Mark fulfilled (per-row checkbox) ────────────────────────────────────

    document.addEventListener('change', function(e) {
        var cb = e.target.closest('.rr-mark-fulfilled');
        if (!cb) return;
        var itemId = cb.dataset.itemId;
        if (!cb.checked) {
            cb.checked = true;
            return;
        }
        cb.disabled = true;
        post(restartRegistry.ajaxUrl, {
            action:         'restart_registry_update_item',
            nonce:          restartRegistry.nonce,
            item_id:        itemId,
            registry_id:    registryId,
            mark_fulfilled: '1',
        }).then(function(response) {
            if (response.success) {
                window.location.reload();
            } else {
                alert(response.data.message || restartRegistry.strings.error);
                cb.checked = false;
                cb.disabled = false;
            }
        }).catch(function() {
            alert(restartRegistry.strings.error);
            cb.checked = false;
            cb.disabled = false;
        });
    });

    // ── Edit item (modal) ─────────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.rr-edit-item');
        if (!trigger) return;
        var row = trigger.closest('.rr-item-row, .rr-item-card');
        document.getElementById('rr-edit-item-id').value       = row.dataset.itemId;
        document.getElementById('rr-edit-item-name').value     = row.dataset.name;
        document.getElementById('rr-edit-item-url').value      = row.dataset.url;
        document.getElementById('rr-edit-item-price').value    = row.dataset.price;
        document.getElementById('rr-edit-item-quantity').value = row.dataset.quantity || 1;
        document.getElementById('rr-edit-item-notes').value    = row.dataset.notes || '';
        document.getElementById('rr-edit-item-image-url').value = row.dataset.imageUrl;
        document.getElementById('rr-edit-item-fulfilled').checked = false;
        openModal('#rr-item-edit-modal');
    });

    var editItemForm = document.getElementById('rr-edit-item-form');
    if (editItemForm) {
        editItemForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = editItemForm.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = restartRegistry.strings.loading;

            post(restartRegistry.ajaxUrl, {
                action:         'restart_registry_update_item',
                nonce:          restartRegistry.nonce,
                item_id:        document.getElementById('rr-edit-item-id').value,
                registry_id:    registryId,
                name:           document.getElementById('rr-edit-item-name').value,
                url:            document.getElementById('rr-edit-item-url').value,
                price:          document.getElementById('rr-edit-item-price').value,
                quantity:       document.getElementById('rr-edit-item-quantity').value,
                notes:          document.getElementById('rr-edit-item-notes').value,
                image_url:      document.getElementById('rr-edit-item-image-url').value,
                mark_fulfilled: document.getElementById('rr-edit-item-fulfilled').checked ? '1' : '0',
            }).then(function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(response.data.message || restartRegistry.strings.error);
                    btn.disabled = false;
                    btn.textContent = restartRegistry.strings.save || 'Save Changes';
                }
            }).catch(function() {
                alert(restartRegistry.strings.error);
                btn.disabled = false;
                btn.textContent = restartRegistry.strings.save || 'Save Changes';
            });
        });
    }

    // ── Item detail modal ─────────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.rr-item-name-btn');
        if (!trigger) return;
        var row          = trigger.closest('.rr-item-row, .rr-item-card');
        var name         = row.dataset.name;
        var imageUrl     = row.dataset.imageUrl;
        var retailer     = row.dataset.retailer;
        var price        = row.dataset.price;
        var notes        = row.dataset.notes;
        var qtyNeeded    = row.dataset.quantity || 1;
        var qtyPurchased = row.dataset.qtyPurchased || 0;
        var affiliateUrl = row.dataset.affiliateUrl;
        var isFulfilled  = row.classList.contains('rr-item-row--fulfilled') || row.classList.contains('rr-item-fulfilled');
        var isGuestView  = !!row.closest('.rr-view-registry');
        var modal        = document.getElementById('rr-item-detail-modal');

        modal.querySelector('.rr-item-detail__title').textContent = name;

        var imageWrap = modal.querySelector('.rr-item-detail__image-wrap');
        if (imageUrl) {
            var img = modal.querySelector('.rr-item-detail__image');
            img.src = imageUrl;
            img.alt = name;
            imageWrap.style.display = '';
        } else {
            imageWrap.style.display = 'none';
        }

        var meta = '';
        if (retailer) meta += '<span class="rr-item-retailer">' + escHtml(retailer) + '</span>';
        if (price)    meta += '<span class="rr-item-price rr-item-detail__price">$' + parseFloat(price).toFixed(2) + '</span>';
        modal.querySelector('.rr-item-detail__meta').innerHTML = meta;

        var desc = modal.querySelector('.rr-item-detail__description');
        if (notes) {
            desc.textContent = notes;
            desc.style.display = '';
        } else {
            desc.style.display = 'none';
        }

        var remaining = parseInt(qtyNeeded) - parseInt(qtyPurchased);
        modal.querySelector('.rr-item-detail__qty-row').innerHTML =
            '<span class="rr-item-detail__qty-label">Needed</span> <strong>' + qtyNeeded + '</strong>'
            + ' &nbsp;&middot;&nbsp; '
            + '<span class="rr-item-detail__qty-label">Purchased</span> <strong>' + qtyPurchased + '</strong>'
            + (remaining > 0 ? ' &nbsp;&middot;&nbsp; <span class="rr-item-detail__qty-label">Remaining</span> <strong>' + remaining + '</strong>' : '');

        var purchaseBtn = modal.querySelector('.rr-item-detail__purchase-btn');
        if (affiliateUrl && !isFulfilled) {
            purchaseBtn.href = affiliateUrl;
            purchaseBtn.style.display = '';
        } else {
            purchaseBtn.style.display = 'none';
        }

        var markBtn = modal.querySelector('.rr-item-detail__mark-btn');
        if (!isFulfilled) {
            markBtn.dataset.itemId = row.dataset.itemId;
            markBtn.style.display = '';
        } else {
            markBtn.style.display = 'none';
        }

        openModal('#rr-item-detail-modal');
    });

    // ── Mark as purchased (guest view) ────────────────────────────────────────

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.rr-mark-purchased');
        if (!trigger) return;
        var card   = trigger.closest('.rr-item-card, .rr-item-row');
        var itemId = card ? card.dataset.itemId : trigger.dataset.itemId;
        var name   = card ? card.dataset.name : '';

        closeModal('#rr-item-detail-modal');
        document.getElementById('rr-purchase-item-id').value = itemId;
        document.querySelector('#rr-purchase-modal .rr-purchase-modal__item-name').textContent = name || '';
        document.getElementById('rr-purchase-form').reset();
        openModal('#rr-purchase-modal');
    });

    var purchaseForm = document.getElementById('rr-purchase-form');
    if (purchaseForm) {
        purchaseForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn       = purchaseForm.querySelector('button[type="submit"]');
            var buyerName = purchaseForm.querySelector('[name="purchaser_name"]').value.trim();
            var buyerNote = purchaseForm.querySelector('[name="purchaser_note"]').value.trim();

            btn.disabled = true;
            btn.textContent = restartRegistry.strings.loading;

            post(restartRegistry.ajaxUrl, {
                action:         'restart_registry_mark_purchased',
                nonce:          restartRegistry.nonce,
                item_id:        document.getElementById('rr-purchase-item-id').value,
                quantity:       1,
                purchaser_name: buyerName,
                purchaser_note: buyerNote,
                is_anonymous:   buyerName ? '0' : '1',
            }).then(function(response) {
                if (response.success) {
                    closeModal('#rr-purchase-modal');
                    showNotice(response.data.message, 'success');
                    setTimeout(function() { window.location.reload(); }, 1500);
                } else {
                    alert(response.data.message || restartRegistry.strings.error);
                    btn.disabled = false;
                    btn.textContent = 'Confirm Purchase';
                }
            }).catch(function() {
                alert(restartRegistry.strings.error);
                btn.disabled = false;
                btn.textContent = 'Confirm Purchase';
            });
        });
    }

    // ── Notification preferences ──────────────────────────────────────────────

    document.addEventListener('change', function(e) {
        var cb = e.target.closest('#rr-notify-purchase');
        if (!cb) return;
        var status = document.getElementById('rr-notify-prefs-status');

        post(restartRegistry.ajaxUrl, {
            action:             'restart_registry_update_notification_prefs',
            nonce:              restartRegistry.nonce,
            notify_on_purchase: cb.checked ? '1' : '0',
        }).then(function(response) {
            status.textContent = response.success
                ? restartRegistry.strings.prefsSaved
                : (response.data.message || restartRegistry.strings.error);
            setTimeout(function() { status.textContent = ''; }, 3000);
        }).catch(function() {
            status.textContent = restartRegistry.strings.error;
            setTimeout(function() { status.textContent = ''; }, 3000);
        });
    });

    // ── Send invite ───────────────────────────────────────────────────────────

    var sendInviteForm = document.getElementById('rr-send-invite-form');
    if (sendInviteForm) {
        sendInviteForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn     = sendInviteForm.querySelector('button[type="submit"]');
            var invitee = sendInviteForm.querySelector('[name="invitee"]');
            btn.disabled = true;
            btn.textContent = restartRegistry.strings.loading;

            post(restartRegistry.ajaxUrl, {
                action:      'restart_registry_send_invite',
                nonce:       restartRegistry.nonce,
                registry_id: registryId,
                invitee:     invitee.value,
            }).then(function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    appendInviteeRow(invitee.value);
                    invitee.value = '';
                } else {
                    alert(response.data.message || restartRegistry.strings.error);
                }
                btn.disabled = false;
                btn.textContent = 'Send Invite';
            }).catch(function() {
                alert(restartRegistry.strings.error);
                btn.disabled = false;
                btn.textContent = 'Send Invite';
            });
        });
    }

    // ── Remove invitee ────────────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.rr-remove-invitee');
        if (!trigger) return;
        var item  = trigger.closest('.rr-invitees__item');
        var email = item.dataset.invitee;
        if (!email) return;
        if (!confirm('Remove ' + email + '?')) return;
        trigger.disabled = true;

        post(restartRegistry.ajaxUrl, {
            action:      'restart_registry_remove_invitee',
            nonce:       restartRegistry.nonce,
            registry_id: registryId,
            invitee:     email,
        }).then(function(response) {
            if (response.success) {
                item.remove();
                renderEmptyInviteesIfNeeded();
            } else {
                alert(response.data.message || restartRegistry.strings.error);
                trigger.disabled = false;
            }
        }).catch(function() {
            alert(restartRegistry.strings.error);
            trigger.disabled = false;
        });
    });

    function appendInviteeRow(email) {
        var list      = document.getElementById('rr-invitees-list');
        var emptyItem = list.querySelector('.rr-invitees__empty');
        if (emptyItem) emptyItem.remove();
        var safeEmail = escHtml(email);
        list.insertAdjacentHTML('beforeend',
            '<li class="rr-invitees__item" data-invitee="' + safeEmail + '">' +
            '<span class="rr-invitees__email">' + safeEmail + '</span>' +
            '<button type="button" class="rr-btn-icon rr-btn-icon--danger rr-remove-invitee" title="Remove invitee" aria-label="Remove ' + safeEmail + '">✕</button>' +
            '</li>'
        );
    }

    function renderEmptyInviteesIfNeeded() {
        var list = document.getElementById('rr-invitees-list');
        if (!list.querySelector('.rr-invitees__item')) {
            var emptyText = document.getElementById('rr-invitees-section').dataset.emptyText || 'No one invited yet.';
            list.insertAdjacentHTML('beforeend', '<li class="rr-invitees__empty">' + emptyText + '</li>');
        }
    }

}());
