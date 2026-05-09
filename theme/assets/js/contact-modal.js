(function () {
    var modal     = document.getElementById('rr-contact-modal');
    var overlay   = modal && modal.querySelector('.rr-modal__overlay');
    var closeBtn  = modal && modal.querySelector('.rr-modal__close');
    var form      = modal && modal.querySelector('#rr-contact-form');
    var statusEl  = form  && form.querySelector('.rr-contact-form__status');
    var submitBtn = form  && form.querySelector('button[type="submit"]');
    var closeTimer = null;

    if (!modal) return;

    function openModal() {
        if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
        modal.removeAttribute('hidden');
        modal.classList.add('is-open');
        document.body.classList.add('rr-modal-open');
        if (closeBtn) closeBtn.focus();
    }

    function closeModal() {
        if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
        modal.setAttribute('hidden', '');
        modal.classList.remove('is-open');
        document.body.classList.remove('rr-modal-open');
        if (form) {
            form.reset();
            if (statusEl) {
                statusEl.className = 'rr-contact-form__status';
                statusEl.textContent = '';
            }
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('a[href="#contact"]');
        if (trigger) {
            e.preventDefault();
            openModal();
        }
    });

    if (overlay)  overlay.addEventListener('click', closeModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hasAttribute('hidden')) {
            closeModal();
        }
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (submitBtn) submitBtn.disabled = true;
            if (statusEl) {
                statusEl.className = 'rr-contact-form__status';
                statusEl.textContent = '';
            }

            var data = new FormData(form);
            data.append('action', 'restart_contact_submit');

            var ajaxUrl = (window.restartContact && window.restartContact.ajaxUrl) || '/wp-admin/admin-ajax.php';

            fetch(ajaxUrl, {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            }).then(function (resp) {
                return resp.json().then(function (json) { return { ok: resp.ok, json: json }; });
            }).then(function (result) {
                var data = (result.json && result.json.data) || {};
                if (result.ok && result.json && result.json.success) {
                    if (statusEl) {
                        statusEl.className = 'rr-contact-form__status is-success';
                        statusEl.textContent = data.message || 'Thanks!';
                    }
                    form.reset();
                    closeTimer = setTimeout(closeModal, 3000);
                } else {
                    if (submitBtn) submitBtn.disabled = false;
                    if (statusEl) {
                        statusEl.className = 'rr-contact-form__status is-error';
                        if (data.errors) {
                            var msgs = [];
                            for (var k in data.errors) {
                                if (Object.prototype.hasOwnProperty.call(data.errors, k)) {
                                    msgs.push(data.errors[k]);
                                }
                            }
                            statusEl.textContent = msgs.join(' ');
                        } else {
                            statusEl.textContent = data.message || 'Something went wrong.';
                        }
                    }
                }
            }).catch(function () {
                if (submitBtn) submitBtn.disabled = false;
                if (statusEl) {
                    statusEl.className = 'rr-contact-form__status is-error';
                    statusEl.textContent = 'Network error. Please try again.';
                }
            });
        });
    }
}());
