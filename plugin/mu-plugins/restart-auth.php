<?php
/**
 * MU-Plugin: Auth hooks — custom login/register pages, role assignment, WP-admin redirect.
 */

// Point WP's generated login/register URLs at our custom pages.
add_filter('login_url', function (string $url, string $redirect, bool $force_reauth): string {
    $login = home_url('/login/');
    return $redirect ? add_query_arg('redirect_to', rawurlencode($redirect), $login) : $login;
}, 10, 3);

add_filter('register_url', function (): string {
    return home_url('/register/');
});

// After logout, always go to the homepage.
add_filter('logout_redirect', function (): string {
    return home_url('/');
});

// On login failure, redirect back to our login page with an error flag.
add_action('wp_login_failed', function (): void {
    wp_safe_redirect(add_query_arg('login', 'failed', home_url('/login/')));
    exit;
});

// After login, send registry_users to /my-account/ instead of /wp-admin/.
add_filter('login_redirect', function (string $redirect_to, string $requested, $user): string {
    if ($user instanceof WP_User && in_array('registry_user', (array) $user->roles, true)) {
        return home_url('/my-account/');
    }
    return $redirect_to;
}, 10, 3);

// Block registry_users from the WP admin dashboard (non-AJAX requests only).
add_action('admin_init', function (): void {
    if (wp_doing_ajax()) {
        return;
    }
    $user = wp_get_current_user();
    if ($user && in_array('registry_user', (array) $user->roles, true)) {
        wp_safe_redirect(home_url('/my-account/'));
        exit;
    }
});

// AJAX: register a new account (unauthenticated).
add_action('wp_ajax_nopriv_restart_register', function (): void {
    check_ajax_referer('restart_register_nonce', 'nonce');

    $username = sanitize_user(wp_unslash($_POST['username'] ?? ''));
    $email    = sanitize_email(wp_unslash($_POST['email']    ?? ''));
    $password = wp_unslash($_POST['password'] ?? '');

    if (!$username || !$email || !$password) {
        wp_send_json_error(['message' => 'Please fill in all fields.']);
    }

    if (strlen($password) < 8) {
        wp_send_json_error(['message' => 'Password must be at least 8 characters.']);
    }

    $user_id = register_new_user($username, $email);
    if (is_wp_error($user_id)) {
        wp_send_json_error(['message' => $user_id->get_error_message()]);
    }

    wp_set_password($password, $user_id);

    $user = new WP_User($user_id);
    $user->set_role('registry_user');

    $signon = wp_signon([
        'user_login'    => $username,
        'user_password' => $password,
        'remember'      => true,
    ]);

    if (is_wp_error($signon)) {
        wp_send_json_success(['redirect' => home_url('/login/')]);
    }

    wp_send_json_success(['redirect' => home_url('/my-account/')]);
});

// AJAX: update the current user's profile.
add_action('wp_ajax_restart_update_profile', function (): void {
    check_ajax_referer('restart_update_profile_nonce', 'nonce');

    $user_id      = get_current_user_id();
    $display_name = sanitize_text_field(wp_unslash($_POST['display_name'] ?? ''));
    $email        = sanitize_email(wp_unslash($_POST['email']        ?? ''));
    $password     = wp_unslash($_POST['password'] ?? '');

    $args = ['ID' => $user_id];

    if ($display_name) {
        $args['display_name'] = $display_name;
    }

    if ($email) {
        $existing = get_user_by('email', $email);
        if ($existing && (int) $existing->ID !== $user_id) {
            wp_send_json_error(['message' => 'That email address is already in use.']);
        }
        $args['user_email'] = $email;
    }

    if ($password) {
        if (strlen($password) < 8) {
            wp_send_json_error(['message' => 'Password must be at least 8 characters.']);
        }
        $args['user_pass'] = $password;
    }

    $result = wp_update_user($args);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success(['message' => 'Profile updated.']);
});
