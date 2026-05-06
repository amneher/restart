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

}(jQuery));
