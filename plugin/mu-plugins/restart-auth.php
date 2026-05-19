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

// After login, send registry_users home unless they were redirected from a specific
// local page (e.g. a protected registry URL) — in that case honour the destination.
add_filter('login_redirect', function (string $redirect_to, string $requested, $user): string {
    if (!($user instanceof WP_User) || !in_array('registry_user', (array) $user->roles, true)) {
        return $redirect_to;
    }
    $home  = home_url('/');
    $admin = admin_url();
    if ($requested && $requested !== $admin && str_starts_with($requested, $home)) {
        return $requested;
    }
    return $home;
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

// Block login for deactivated accounts.
add_filter('authenticate', function ($user, string $username, string $password) {
    if (!($user instanceof WP_User)) {
        return $user;
    }
    if (get_user_meta($user->ID, 'restart_account_deactivated', true) === '1') {
        return new WP_Error(
            'account_deactivated',
            'Your account has been deactivated. Please contact us at hello@the-restart.co to reactivate it.'
        );
    }
    return $user;
}, 30, 3);

// AJAX: deactivate the current user's account.
add_action('wp_ajax_restart_deactivate_account', function (): void {
    check_ajax_referer('restart_deactivate_account_nonce', 'nonce');

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'Not logged in.']);
    }

    // Force all public registries to private so they disappear from public views.
    $public_registries = get_posts([
        'post_type'      => 'restart-registry',
        'author'         => $user_id,
        'post_status'    => ['publish'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);
    foreach ($public_registries as $registry_id) {
        wp_update_post(['ID' => $registry_id, 'post_status' => 'private']);
    }

    update_user_meta($user_id, 'restart_account_deactivated', '1');
    wp_logout();
    wp_send_json_success(['redirect' => home_url('/')]);
});

// AJAX: permanently delete the current user's account and all their data.
add_action('wp_ajax_restart_delete_account', function (): void {
    check_ajax_referer('restart_delete_account_nonce', 'nonce');

    $user_id  = get_current_user_id();
    $password = wp_unslash($_POST['password'] ?? '');

    if (!$user_id || !$password) {
        wp_send_json_error(['message' => 'Password is required.']);
    }

    $user = get_userdata($user_id);
    if (!$user || !wp_check_password($password, $user->user_pass, $user_id)) {
        wp_send_json_error(['message' => 'Incorrect password. Please try again.']);
    }

    // Delete all registries (and their Lambda items) via the plugin controller.
    if (class_exists('Restart_Registry_Controller')) {
        $registries = get_posts([
            'post_type'      => 'restart-registry',
            'author'         => $user_id,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        $controller = new Restart_Registry_Controller();
        foreach ($registries as $registry_id) {
            $controller->delete_registry((int) $registry_id);
        }
    }

    // Send confirmation email to the user before we lose their address.
    $site_name = get_bloginfo('name');
    wp_mail(
        $user->user_email,
        sprintf('[%s] Your account has been deleted', $site_name),
        sprintf(
            "Hi %s,\n\nYour account and all associated data have been permanently deleted from %s.\n\nIf you did not request this, please contact us at hello@the-restart.co.\n\nTake care,\nThe %s team",
            $user->display_name,
            $site_name,
            $site_name
        )
    );

    // Notify admin.
    $admin_email = get_option('admin_email');
    if ($admin_email) {
        wp_mail(
            $admin_email,
            sprintf('[%s] Account deleted: %s', $site_name, $user->user_login),
            sprintf(
                "User %s (%s, ID %d) has deleted their account and all associated data.",
                $user->display_name,
                $user->user_email,
                $user_id
            )
        );
    }

    wp_logout();
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($user_id);
    wp_send_json_success(['redirect' => home_url('/')]);
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

    wp_send_json_success(['redirect' => home_url('/')]);
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
