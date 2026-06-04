'use strict';

(function() {

    function post(url, data) {
        return fetch(url, { method: 'POST', body: new URLSearchParams(data) })
            .then(function(r) { return r.json(); });
    }

    function slideDown(el, duration) {
        el.style.overflow = 'hidden';
        el.style.maxHeight = '0';
        el.removeAttribute('hidden');
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
            el.setAttribute('hidden', '');
            el.style.maxHeight = '';
            el.style.overflow = '';
            el.style.transition = '';
            if (callback) callback();
        }, duration);
    }

    function openAccountModal(id) {
        var modal = document.querySelector(id);
        modal.removeAttribute('hidden');
        var first = modal.querySelector('input, button');
        if (first) first.focus();
    }

    // ── Registration form ──────────────────────────────────────────────────────

    document.addEventListener('submit', function(e) {
        var form = e.target.closest('#rr-register-form');
        if (!form) return;
        e.preventDefault();

        var btn   = document.getElementById('rr-register-submit');
        var error = document.getElementById('rr-register-error');

        error.style.display = 'none';
        error.textContent = '';
        btn.disabled = true;
        btn.textContent = 'Creating account…';

        post(restartAuth.ajaxUrl, {
            action:   'restart_register',
            nonce:    restartAuth.registerNonce,
            username: form.querySelector('[name="username"]').value.trim(),
            email:    form.querySelector('[name="email"]').value.trim(),
            password: form.querySelector('[name="password"]').value,
        }).then(function(res) {
            if (res.success) {
                window.location.href = res.data.redirect;
            } else {
                error.textContent = res.data.message;
                error.style.display = '';
                btn.disabled = false;
                btn.textContent = 'Create Account';
            }
        }).catch(function() {
            error.textContent = 'Something went wrong. Please try again.';
            error.style.display = '';
            btn.disabled = false;
            btn.textContent = 'Create Account';
        });
    });

    // ── Profile edit toggle ────────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-edit-profile-toggle')) return;
        e.preventDefault();
        var panel = document.getElementById('rr-edit-profile-panel');
        if (panel.hidden) {
            slideDown(panel, 200);
        } else {
            slideUp(panel, 200);
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-edit-profile-cancel')) return;
        slideUp(document.getElementById('rr-edit-profile-panel'), 200);
    });

    // ── Notification preferences toggle ────────────────────────────────────────

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-notification-prefs-toggle')) return;
        e.preventDefault();
        var panel = document.getElementById('rr-notification-prefs-panel');
        if (panel.hidden) {
            slideDown(panel, 200);
        } else {
            slideUp(panel, 200);
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-notification-prefs-close')) return;
        slideUp(document.getElementById('rr-notification-prefs-panel'), 200);
    });

    // ── Profile update form ────────────────────────────────────────────────────

    document.addEventListener('submit', function(e) {
        var form = e.target.closest('#rr-profile-form');
        if (!form) return;
        e.preventDefault();

        var btn     = document.getElementById('rr-profile-save');
        var error   = document.getElementById('rr-profile-error');
        var success = document.getElementById('rr-profile-message');

        error.style.display = 'none';
        error.textContent = '';
        success.style.display = 'none';
        success.textContent = '';
        btn.disabled = true;
        btn.textContent = 'Saving…';

        post(restartAuth.ajaxUrl, {
            action:       'restart_update_profile',
            nonce:        document.getElementById('rr-profile-nonce').value,
            display_name: form.querySelector('[name="display_name"]').value.trim(),
            email:        form.querySelector('[name="email"]').value.trim(),
            password:     form.querySelector('[name="password"]').value,
        }).then(function(res) {
            if (res.success) {
                success.textContent = res.data.message;
                success.style.display = '';
                form.querySelector('[name="password"]').value = '';
            } else {
                error.textContent = res.data.message;
                error.style.display = '';
            }
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        }).catch(function() {
            error.textContent = 'Something went wrong. Please try again.';
            error.style.display = '';
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        });
    });

    // ── Account danger zone ────────────────────────────────────────────────────

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-account-danger-toggle')) return;
        e.preventDefault();
        var panel = document.getElementById('rr-account-danger-panel');
        if (panel.hidden) {
            slideDown(panel, 200);
        } else {
            slideUp(panel, 200);
        }
    });

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.restart-modal__overlay, .restart-modal__close, .rr-modal-dismiss');
        if (!trigger) return;
        var modal = trigger.closest('.restart-modal');
        if (modal) modal.setAttribute('hidden', '');
    });

    // Deactivate flow
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-deactivate-account-btn')) return;
        openAccountModal('#rr-deactivate-confirm-modal');
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-deactivate-confirm-btn')) return;
        var btn = e.target.closest('#rr-deactivate-confirm-btn');
        var err = document.getElementById('rr-deactivate-error');
        btn.disabled = true;
        btn.textContent = 'Deactivating…';
        err.setAttribute('hidden', '');

        post(restartAuth.ajaxUrl, {
            action: 'restart_deactivate_account',
            nonce:  restartAuth.deactivateAccountNonce,
        }).then(function(res) {
            if (res.success && res.data.redirect) {
                window.location.href = res.data.redirect;
            } else {
                err.textContent = (res.data && res.data.message) || 'Something went wrong.';
                err.removeAttribute('hidden');
                btn.disabled = false;
                btn.textContent = 'Deactivate My Account';
            }
        }).catch(function() {
            err.textContent = 'Something went wrong. Please try again.';
            err.removeAttribute('hidden');
            btn.disabled = false;
            btn.textContent = 'Deactivate My Account';
        });
    });

    // Delete account flow
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-delete-account-btn')) return;
        document.getElementById('rr-delete-account-password').value = '';
        document.getElementById('rr-delete-account-understand').checked = false;
        document.getElementById('rr-delete-account-confirm-btn').disabled = true;
        document.getElementById('rr-delete-account-error').setAttribute('hidden', '');
        openAccountModal('#rr-delete-account-modal');
    });

    document.addEventListener('change', function(e) {
        if (!e.target.closest('#rr-delete-account-understand')) return;
        document.getElementById('rr-delete-account-confirm-btn').disabled = !e.target.checked;
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#rr-delete-account-confirm-btn')) return;
        var btn = e.target.closest('#rr-delete-account-confirm-btn');
        var err = document.getElementById('rr-delete-account-error');
        var pwd = document.getElementById('rr-delete-account-password').value;
        err.setAttribute('hidden', '');

        if (!pwd) {
            err.textContent = 'Please enter your current password.';
            err.removeAttribute('hidden');
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Deleting…';

        post(restartAuth.ajaxUrl, {
            action:   'restart_delete_account',
            nonce:    restartAuth.deleteAccountNonce,
            password: pwd,
        }).then(function(res) {
            if (res.success && res.data.redirect) {
                window.location.href = res.data.redirect;
            } else {
                err.textContent = (res.data && res.data.message) || 'Something went wrong.';
                err.removeAttribute('hidden');
                btn.disabled = false;
                btn.textContent = 'Permanently Delete My Account';
            }
        }).catch(function() {
            err.textContent = 'Something went wrong. Please try again.';
            err.removeAttribute('hidden');
            btn.disabled = false;
            btn.textContent = 'Permanently Delete My Account';
        });
    });

}());
