(function ($) {
    'use strict';

    // ── Registration form ──────────────────────────────────────────────────────

    $(document).on('submit', '#rr-register-form', function (e) {
        e.preventDefault();

        var $form   = $(this);
        var $btn    = $('#rr-register-submit');
        var $error  = $('#rr-register-error');

        $error.hide().text('');
        $btn.prop('disabled', true).text('Creating account…');

        $.post(restartAuth.ajaxUrl, {
            action:   'restart_register',
            nonce:    restartAuth.registerNonce,
            username: $form.find('[name="username"]').val().trim(),
            email:    $form.find('[name="email"]').val().trim(),
            password: $form.find('[name="password"]').val(),
        })
        .done(function (res) {
            if (res.success) {
                window.location.href = res.data.redirect;
            } else {
                $error.text(res.data.message).show();
                $btn.prop('disabled', false).text('Create Account');
            }
        })
        .fail(function () {
            $error.text('Something went wrong. Please try again.').show();
            $btn.prop('disabled', false).text('Create Account');
        });
    });

    // ── Profile edit toggle ────────────────────────────────────────────────────

    $(document).on('click', '#rr-edit-profile-toggle', function (e) {
        e.preventDefault();
        var $panel = $('#rr-edit-profile-panel');
        if ($panel.is(':hidden')) {
            $panel.removeAttr('hidden').hide().slideDown(200);
        } else {
            $panel.slideUp(200, function () { $panel.attr('hidden', ''); });
        }
    });

    $(document).on('click', '#rr-edit-profile-cancel', function () {
        var $panel = $('#rr-edit-profile-panel');
        $panel.slideUp(200, function () { $panel.attr('hidden', ''); });
    });

    // ── Notification preferences toggle ────────────────────────────────────────

    $(document).on('click', '#rr-notification-prefs-toggle', function (e) {
        e.preventDefault();
        var $panel = $('#rr-notification-prefs-panel');
        if ($panel.is(':hidden')) {
            $panel.removeAttr('hidden').hide().slideDown(200);
        } else {
            $panel.slideUp(200, function () { $panel.attr('hidden', ''); });
        }
    });

    $(document).on('click', '#rr-notification-prefs-close', function () {
        var $panel = $('#rr-notification-prefs-panel');
        $panel.slideUp(200, function () { $panel.attr('hidden', ''); });
    });

    // ── Profile update form ────────────────────────────────────────────────────

    $(document).on('submit', '#rr-profile-form', function (e) {
        e.preventDefault();

        var $form    = $(this);
        var $btn     = $('#rr-profile-save');
        var $error   = $('#rr-profile-error');
        var $success = $('#rr-profile-message');

        $error.hide().text('');
        $success.hide().text('');
        $btn.prop('disabled', true).text('Saving…');

        $.post(restartAuth.ajaxUrl, {
            action:       'restart_update_profile',
            nonce:        $('#rr-profile-nonce').val(),
            display_name: $form.find('[name="display_name"]').val().trim(),
            email:        $form.find('[name="email"]').val().trim(),
            password:     $form.find('[name="password"]').val(),
        })
        .done(function (res) {
            if (res.success) {
                $success.text(res.data.message).show();
                $form.find('[name="password"]').val('');
            } else {
                $error.text(res.data.message).show();
            }
            $btn.prop('disabled', false).text('Save Changes');
        })
        .fail(function () {
            $error.text('Something went wrong. Please try again.').show();
            $btn.prop('disabled', false).text('Save Changes');
        });
    });

    // ── Account danger zone ────────────────────────────────────────────────────

    function openAccountModal(id) {
        $(id).removeAttr('hidden').find('input, button').first().focus();
    }

    $(document).on('click', '#rr-account-danger-toggle', function (e) {
        e.preventDefault();
        var $panel = $('#rr-account-danger-panel');
        if ($panel.is(':hidden')) {
            $panel.removeAttr('hidden').hide().slideDown(200);
        } else {
            $panel.slideUp(200, function () { $panel.attr('hidden', ''); });
        }
    });

    $(document).on('click', '.restart-modal__overlay, .restart-modal__close, .rr-modal-dismiss', function () {
        $(this).closest('.restart-modal').attr('hidden', '');
    });

    // Deactivate flow
    $(document).on('click', '#rr-deactivate-account-btn', function () {
        openAccountModal('#rr-deactivate-confirm-modal');
    });

    $(document).on('click', '#rr-deactivate-confirm-btn', function () {
        var $btn = $(this).prop('disabled', true).text('Deactivating…');
        var $err = $('#rr-deactivate-error');
        $err.attr('hidden', '');

        $.post(restartAuth.ajaxUrl, {
            action: 'restart_deactivate_account',
            nonce:  restartAuth.deactivateAccountNonce,
        })
        .done(function (res) {
            if (res.success && res.data.redirect) {
                window.location.href = res.data.redirect;
            } else {
                $err.text((res.data && res.data.message) || 'Something went wrong.').removeAttr('hidden');
                $btn.prop('disabled', false).text('Deactivate My Account');
            }
        })
        .fail(function () {
            $err.text('Something went wrong. Please try again.').removeAttr('hidden');
            $btn.prop('disabled', false).text('Deactivate My Account');
        });
    });

    // Delete account flow
    $(document).on('click', '#rr-delete-account-btn', function () {
        $('#rr-delete-account-password').val('');
        $('#rr-delete-account-understand').prop('checked', false);
        $('#rr-delete-account-confirm-btn').prop('disabled', true);
        $('#rr-delete-account-error').attr('hidden', '');
        openAccountModal('#rr-delete-account-modal');
    });

    $(document).on('change', '#rr-delete-account-understand', function () {
        $('#rr-delete-account-confirm-btn').prop('disabled', !this.checked);
    });

    $(document).on('click', '#rr-delete-account-confirm-btn', function () {
        var $btn = $(this).prop('disabled', true).text('Deleting…');
        var $err = $('#rr-delete-account-error');
        var pwd  = $('#rr-delete-account-password').val();
        $err.attr('hidden', '');

        if (!pwd) {
            $err.text('Please enter your current password.').removeAttr('hidden');
            $btn.prop('disabled', false).text('Permanently Delete My Account');
            return;
        }

        $.post(restartAuth.ajaxUrl, {
            action:   'restart_delete_account',
            nonce:    restartAuth.deleteAccountNonce,
            password: pwd,
        })
        .done(function (res) {
            if (res.success && res.data.redirect) {
                window.location.href = res.data.redirect;
            } else {
                $err.text((res.data && res.data.message) || 'Something went wrong.').removeAttr('hidden');
                $btn.prop('disabled', false).text('Permanently Delete My Account');
            }
        })
        .fail(function () {
            $err.text('Something went wrong. Please try again.').removeAttr('hidden');
            $btn.prop('disabled', false).text('Permanently Delete My Account');
        });
    });

}(jQuery));
