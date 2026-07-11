<?php

$fonts_url = 'https://fonts.googleapis.com/css2?family=Libre+Caslon+Display&family=Libre+Caslon+Text:ital,wght@0,400;0,700;1,400&family=Montserrat:ital,wght@0,400;0,500;0,700;0,900;1,400;1,500;1,700;1,900&display=swap';

add_action('wp_enqueue_scripts', function () use ($fonts_url) {
    wp_enqueue_style('therestart-fonts', $fonts_url, [], null);
    wp_enqueue_style('therestart-style', get_stylesheet_uri(), ['therestart-fonts'], wp_get_theme()->get('Version'));
    wp_enqueue_script(
        'therestart-header-current-nav',
        get_stylesheet_directory_uri() . '/assets/js/header-current-nav.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );
});

add_action('admin_enqueue_scripts', function () use ($fonts_url) {
    wp_enqueue_style('therestart-fonts', $fonts_url, [], null);
});

add_filter('show_admin_bar', function ($show) {
    return $show && current_user_can('edit_posts');
});

// Enqueue nav user-state script on every front-end page.
// Passes rrNavState so nav-user-state.js can personalise the header/footer
// without any PHP in the FSE block templates.
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'therestart-nav-user-state',
        get_stylesheet_directory_uri() . '/assets/js/nav-user-state.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );

    $registry_url = '';
    if (is_user_logged_in()) {
        $owned = get_posts([
            'post_type'      => 'restart-registry',
            'post_status'    => ['publish', 'private'],
            'author'         => get_current_user_id(),
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);
        if (!empty($owned)) {
            $registry_url = (string) get_permalink($owned[0]);
        }
    }

    wp_localize_script('therestart-nav-user-state', 'rrNavState', [
        'isLoggedIn'      => is_user_logged_in(),
        'registryUrl'     => $registry_url,
        'loginUrl'        => home_url('/login/'),
        'logoutUrl'       => wp_logout_url(home_url('/')),
        'myAccountUrl'    => home_url('/my-account/'),
        'startRegistryUrl'=> home_url('/start-a-registry/'),
    ]);
});

// Preload the hero background image on every page that renders it above the fold.
// hero-sunrise.png is the CSS background-image for both .hero-cover (front page,
// 520px tall) and .hero-page (auth/account pages, 220px tall). Browsers cannot
// discover CSS background images until the stylesheet is parsed, so without this
// hint they start loading late and become the LCP element. Priority 1 places the
// <link> before stylesheet tags so the fetch begins in parallel with CSS parsing.
add_action('wp_head', function () {
    $uses_hero = is_front_page()
        || is_home()
        || is_page(['login', 'register', 'my-account', 'my-registries']);
    if (!$uses_hero) return;

    $url = get_stylesheet_directory_uri() . '/assets/hero-sunrise.png';
    echo '<link rel="preload" as="image" href="' . esc_url($url) . '" fetchpriority="high">' . "\n";
}, 1);

// Defer/async third-party scripts that have no role in the critical render path.
//
// Two approaches needed because plugins load scripts different ways:
//
// 1. script_loader_tag: catches scripts registered via wp_enqueue_script().
//    Site Kit (gtag/js) and Bluehost scripts go through this path.
//
// 2. wp_head output buffer (below): catches scripts output as raw HTML by
//    plugins that bypass wp_enqueue_script. GTM4WP does this — it writes
//    the GTM container snippet directly in wp_head.
//
// ANALYTICS NOTE: Site Kit, GTM4WP, and MonsterInsights are all active.
// That is three analytics stacks loading on every page. Consolidating to
// one would eliminate 1-2 of these script loads entirely — far more impactful
// than async-ing them. Recommend keeping whichever is actually being used for
// reporting and disabling the other two.
//
// BLUEHOST NOTE: The two slow Bluehost scripts (~904ms each) are Newfold AI
// features (nfd-ai-chat, nfd-agents, nfd-editor-chat) — admin-only tools that
// should not load on the front end at all. Deferring reduces the damage but
// the real fix is disabling front-end loading in the Bluehost plugin settings,
// or dequeuing by handle once we know the registered names.
add_filter('script_loader_tag', function (string $tag, string $handle, string $src): string {
    if (is_admin()) return $tag;

    $defer = [
        'bluehost-wordpress-plugin',
        'newfold-labs',
        'stats.wp.com',
    ];
    $async = [
        'googletagmanager.com',
    ];

    foreach ($defer as $pattern) {
        if (str_contains($src, $pattern) && !str_contains($tag, ' defer')) {
            return str_replace('<script ', '<script defer ', $tag);
        }
    }
    foreach ($async as $pattern) {
        if (str_contains($src, $pattern) && !str_contains($tag, ' async')) {
            return str_replace('<script ', '<script async ', $tag);
        }
    }

    return $tag;
}, 10, 3);

// Intercept raw <script> tags written directly to wp_head by plugins that
// bypass wp_enqueue_script (e.g. GTM4WP writes the GTM container snippet
// as plain HTML). We buffer the entire wp_head output and add async to any
// googletagmanager.com or google-analytics.com script tag that doesn't
// already have it.
add_action('wp_head', function () {
    ob_start(function (string $output): string {
        return preg_replace(
            '/(<script\b(?![^>]*\basync\b)[^>]*\bsrc=["\'][^"\']*(?:googletagmanager\.com|google-analytics\.com)[^"\']*["\'][^>]*)>/i',
            '$1 async>',
            $output
        );
    });
}, 1);
add_action('wp_head', function () { if (ob_get_level() > 0) ob_end_flush(); }, 999);

// Favicon + Open Graph meta. Theme assets serve as fallback when no
// site icon is set in the Customizer.
add_action('wp_head', function () {
    $assets = get_stylesheet_directory_uri() . '/assets';
    echo '<link rel="icon" type="image/svg+xml" href="' . esc_url($assets . '/favicon.svg') . '">' . "\n";
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url($assets . '/favicon-32.png') . '">' . "\n";
    echo '<link rel="icon" type="image/png" sizes="16x16" href="' . esc_url($assets . '/favicon-16.png') . '">' . "\n";
    echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url($assets . '/apple-touch-icon.png') . '">' . "\n";

    if (is_singular()) {
        $title = wp_get_document_title();
        $description = get_bloginfo('description');
        $url = get_permalink();
    } else {
        $title = get_bloginfo('name');
        $description = get_bloginfo('description');
        $url = home_url('/');
    }
    $og_image = $assets . '/og-image.png';

    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
    echo '<meta property="og:image:width" content="1200">' . "\n";
    echo '<meta property="og:image:height" content="630">' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
});

// Allow uploading the brand mark via Customizer → Site Identity.
add_action('after_setup_theme', function () {
    add_theme_support('custom-logo', [
        'height'      => 50,
        'width'       => 220,
        'flex-height' => true,
        'flex-width'  => true,
    ]);
});

// Enqueue the Start a Registry JS with localized credentials when on that page
add_action('wp_enqueue_scripts', function () {
    if (!is_page('start-a-registry') || !is_user_logged_in()) {
        return;
    }
    $user = wp_get_current_user();

    wp_enqueue_script(
        'restart-start-registry',
        get_stylesheet_directory_uri() . '/assets/js/start-registry.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );
    wp_localize_script('restart-start-registry', 'restartRegistry', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('restart_create_registry'),
        'username'     => $user->user_login,
        'myAccountUrl' => home_url('/my-account/'),
    ]);
});

add_action('wp_ajax_restart_create_registry', function () {
    $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
    if (!wp_verify_nonce($nonce, 'restart_create_registry')) {
        wp_send_json_error(['message' => 'Invalid nonce.'], 403);
    }

    $user = wp_get_current_user();
    if (!$user->exists()) {
        wp_send_json_error(['message' => 'Not logged in.'], 401);
    }

    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $title = sanitize_text_field($body['title'] ?? '');
    if (!$title) {
        wp_send_json_error(['message' => 'Registry title is required.'], 400);
    }

    $is_private = !empty($body['is_private']);
    $story      = sanitize_textarea_field($body['story'] ?? '');
    $meta       = is_array($body['meta'] ?? null) ? $body['meta'] : [];

    $post_id = wp_insert_post([
        'post_type'    => 'restart-registry',
        'post_title'   => $title,
        'post_status'  => $is_private ? 'private' : 'publish',
        'post_author'  => $user->ID,
        'post_content' => $story,
        'meta_input'   => [
            'restart_invitees'               => wp_json_encode($meta['invitees'] ?? []),
            'restart_item_ids'               => wp_json_encode($meta['item_ids'] ?? []),
            'restart_event_type'             => sanitize_text_field($meta['event_type'] ?? ''),
            'restart_event_date'             => sanitize_text_field($meta['event_date'] ?? ''),
            'restart_is_for_self'            => empty($meta['is_for_self']) ? '0' : '1',
            'restart_recipient_name'         => sanitize_text_field($meta['recipient_name'] ?? ''),
            'restart_recipient_relationship' => sanitize_text_field($meta['recipient_relationship'] ?? ''),
            'restart_recipient_email'        => sanitize_email($meta['recipient_email'] ?? ''),
        ],
    ], true);

    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => $post_id->get_error_message()], 500);
    }

    wp_send_json_success(['id' => $post_id, 'url' => get_permalink($post_id)]);
});

add_shortcode('restart_start_registry', function () {
    if (!is_user_logged_in()) {
        ob_start(); ?>
        <div class="restart-login-prompt">
            <p>You need to be logged in to create a registry.</p>
            <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="restart-btn">Log In</a>
        </div>
        <?php return ob_get_clean();
    }

    ob_start(); ?>
    <form id="restart-registry-form" class="restart-form" novalidate>

        <div class="restart-form__group">
            <label class="restart-form__label" for="registry-title">Registry Name <span aria-hidden="true">*</span></label>
            <input class="restart-form__input" type="text" id="registry-title" name="title" required maxlength="200" placeholder="e.g. My New Beginning">
        </div>

        <div class="restart-form__group">
            <label class="restart-form__label" for="event-type">My Situation</label>
            <select class="restart-form__select" id="event-type" name="event_type">
                <option value="">— Select your situation —</option>
                <option value="divorce">Divorce</option>
                <option value="separation">Separation</option>
                <option value="moving-on">Moving On</option>
                <option value="getting-out">Getting Out</option>
                <option value="other">Other</option>
            </select>
        </div>

        <div class="restart-form__group">
            <label class="restart-form__label" for="event-date">My Date</label>
            <input class="restart-form__input" type="date" id="event-date" name="event_date">
        </div>

        <div class="restart-form__group">
            <label class="restart-form__label" for="registry-story">My Story</label>
            <textarea class="restart-form__textarea" id="registry-story" name="story" rows="5" maxlength="2000" placeholder="Share what's brought you to this moment, and what this fresh start means to you…"></textarea>
            <p class="restart-form__hint">This will appear on your public registry page.</p>
        </div>

        <!-- Recipient: when the creator is making this for someone else. Hidden
             is_for_self=1 by default; when "for someone else" is checked the
             value flips and the recipient block appears. -->
        <div class="restart-form__group">
            <label class="restart-toggle">
                <input type="checkbox" id="rr-not-for-self" name="not_for_self" value="1">
                <span class="restart-toggle__track"></span>
                <span class="restart-toggle__label">This registry is for someone else</span>
            </label>
            <input type="hidden" id="rr-is-for-self" name="is_for_self" value="1">
        </div>

        <div class="restart-form__group rr-recipient-fields" id="rr-recipient-fields" hidden>
            <label class="restart-form__label" for="rr-recipient-name">Recipient name</label>
            <input class="restart-form__input" type="text" id="rr-recipient-name" name="recipient_name" maxlength="100" placeholder="e.g. Sarah">

            <label class="restart-form__label" for="rr-recipient-relationship" style="margin-top:0.75rem">Your relationship to them</label>
            <input class="restart-form__input" type="text" id="rr-recipient-relationship" name="recipient_relationship" maxlength="100" placeholder="e.g. my sister">

            <label class="restart-form__label" for="rr-recipient-email" style="margin-top:0.75rem">Recipient email <span class="restart-form__hint" style="display:inline">(optional, so they can claim the registry later)</span></label>
            <input class="restart-form__input" type="email" id="rr-recipient-email" name="recipient_email" maxlength="200">
        </div>

        <div class="restart-form__group">
            <span class="restart-form__label">Private Registry?</span>
            <label class="restart-toggle">
                <input type="checkbox" id="is-private" name="is_private">
                <span class="restart-toggle__track"></span>
                <span class="restart-toggle__label">Only visible to people I invite</span>
            </label>
        </div>

        <div class="restart-form__group" id="invitees-group" hidden>
            <label class="restart-form__label" for="invitees">Invite by Username or Email</label>
            <input class="restart-form__input" type="text" id="invitees" name="invitees" placeholder="username or email">
            <p class="restart-form__hint">Enter reStart usernames or email addresses separated by commas.</p>
        </div>

        <div id="restart-form-error" class="restart-form__error" hidden></div>

        <button type="submit" class="restart-btn">Create Registry</button>

    </form>
    <?php return ob_get_clean();
});

add_shortcode('restart_my_account', function () {
    if (!is_user_logged_in()) {
        return '<p>' . wp_kses_post(
            sprintf(
                'Please <a href="%s">log in</a> to manage your account.',
                esc_url(wp_login_url(get_permalink()))
            )
        ) . '</p>';
    }

    $user = wp_get_current_user();

    ob_start();
    ?>
    <div class="restart-my-account">

        <p class="restart-my-account__greeting">Hello, <strong><?php echo esc_html($user->display_name); ?></strong>.</p>

        <nav class="restart-my-account__nav" aria-label="Account navigation">
            <ul>
                <li><a href="<?php echo esc_url(home_url('/my-registries/')); ?>">My Registries</a></li>
                <li><a href="#edit-profile" id="rr-edit-profile-toggle">Edit Profile</a></li>
                <li><a href="#notification-prefs" id="rr-notification-prefs-toggle">Notifications</a></li>
                <li><a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Log Out</a></li>
                <li><a href="#account-danger" id="rr-account-danger-toggle">Account</a></li>
            </ul>
        </nav>

        <div id="rr-edit-profile-panel" class="restart-my-account__edit-panel" hidden>
            <h2 class="restart-my-account__section-title">Edit Profile</h2>

            <div id="rr-profile-message" class="restart-form__success" hidden></div>
            <div id="rr-profile-error" class="restart-form__error" hidden></div>

            <form id="rr-profile-form" class="restart-form" novalidate>
                <input type="hidden" id="rr-profile-nonce" value="<?php echo esc_attr(wp_create_nonce('restart_update_profile_nonce')); ?>" />

                <div class="restart-form__group">
                    <label class="restart-form__label" for="rr-display-name">Display Name</label>
                    <input class="restart-form__input" type="text" id="rr-display-name" name="display_name"
                        value="<?php echo esc_attr($user->display_name); ?>" maxlength="100">
                </div>

                <div class="restart-form__group">
                    <label class="restart-form__label" for="rr-email">Email Address</label>
                    <input class="restart-form__input" type="email" id="rr-email" name="email"
                        value="<?php echo esc_attr($user->user_email); ?>">
                </div>

                <div class="restart-form__group">
                    <label class="restart-form__label" for="rr-new-password">New Password <span class="restart-form__hint" style="display:inline">(leave blank to keep current)</span></label>
                    <input class="restart-form__input" type="password" id="rr-new-password" name="password"
                        autocomplete="new-password" minlength="8">
                </div>

                <div class="restart-form__actions">
                    <button type="submit" class="restart-btn" id="rr-profile-save">Save Changes</button>
                    <button type="button" class="restart-btn restart-btn--ghost" id="rr-edit-profile-cancel">Cancel</button>
                </div>
            </form>
        </div>

        <?php
        $notify_pref = get_user_meta($user->ID, 'restart_notify_on_purchase', true);
        $notify_on   = $notify_pref !== '0';
        ?>
        <div id="rr-notification-prefs-panel" class="restart-my-account__edit-panel" hidden>
            <h2 class="restart-my-account__section-title">Notification Preferences</h2>

            <label class="rr-checkbox-label">
                <input type="checkbox" id="rr-notify-purchase" <?php checked($notify_on); ?>>
                Email me when items are purchased from my registries
            </label>
            <p id="rr-notify-prefs-status" class="rr-notify-status" aria-live="polite"></p>

            <div class="restart-form__actions" style="margin-top:1.5rem">
                <button type="button" class="restart-btn restart-btn--ghost" id="rr-notification-prefs-close">Close</button>
            </div>
        </div>

        <div id="rr-account-danger-panel" class="restart-my-account__edit-panel" hidden>
            <h2 class="restart-my-account__section-title">Account</h2>
            <div class="restart-account-danger-zone">
                <div class="restart-account-danger-zone__row">
                    <div>
                        <strong>Deactivate account</strong>
                        <p>Your account will be locked and your registries hidden. Your data is preserved. Contact us to reactivate.</p>
                    </div>
                    <button type="button" class="restart-btn restart-btn--ghost" id="rr-deactivate-account-btn">Deactivate</button>
                </div>
                <div class="restart-account-danger-zone__row restart-account-danger-zone__row--delete">
                    <div>
                        <strong>Delete account</strong>
                        <p>Permanently removes your account, all registries, and all data. This cannot be undone.</p>
                    </div>
                    <button type="button" class="restart-btn restart-btn--danger" id="rr-delete-account-btn">Delete Account</button>
                </div>
            </div>
        </div>

        <!-- Deactivate confirm modal -->
        <div id="rr-deactivate-confirm-modal" class="restart-modal" hidden aria-modal="true" role="dialog">
            <div class="restart-modal__overlay"></div>
            <div class="restart-modal__dialog">
                <button class="restart-modal__close" aria-label="Close">&times;</button>
                <h2 class="restart-modal__title">Deactivate your account?</h2>
                <p>You will be logged out and unable to log back in. Your registries will be set to private. All data is preserved — contact us at hello@the-restart.co to reactivate.</p>
                <div class="restart-form__actions">
                    <button type="button" class="restart-btn" id="rr-deactivate-confirm-btn">Deactivate My Account</button>
                    <button type="button" class="restart-btn restart-btn--ghost rr-modal-dismiss">Cancel</button>
                </div>
                <p id="rr-deactivate-error" class="restart-form__error" hidden></p>
            </div>
        </div>

        <!-- Delete account confirm modal -->
        <div id="rr-delete-account-modal" class="restart-modal" hidden aria-modal="true" role="dialog">
            <div class="restart-modal__overlay"></div>
            <div class="restart-modal__dialog">
                <button class="restart-modal__close" aria-label="Close">&times;</button>
                <h2 class="restart-modal__title">Delete your account?</h2>
                <p class="restart-account-delete-warning">This cannot be undone. Your account, all registries, and all data will be permanently removed.</p>
                <div class="restart-form__group">
                    <label class="restart-form__label" for="rr-delete-account-password">Enter your current password to confirm</label>
                    <input class="restart-form__input" type="password" id="rr-delete-account-password" autocomplete="current-password">
                </div>
                <label class="rr-checkbox-label" style="margin-bottom:1.25rem">
                    <input type="checkbox" id="rr-delete-account-understand">
                    I understand this is permanent and cannot be undone
                </label>
                <div class="restart-form__actions">
                    <button type="button" class="restart-btn restart-btn--danger" id="rr-delete-account-confirm-btn" disabled>Permanently Delete My Account</button>
                    <button type="button" class="restart-btn restart-btn--ghost rr-modal-dismiss">Cancel</button>
                </div>
                <p id="rr-delete-account-error" class="restart-form__error" hidden></p>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
});

add_shortcode('restart_login_form', function () {
    if (is_user_logged_in()) {
        return '<p>' . wp_kses_post(
            sprintf('You are already logged in. <a href="%s">Go to your account</a>.', esc_url(home_url('/my-account/')))
        ) . '</p>';
    }

    $error = isset($_GET['login']) && $_GET['login'] === 'failed'
        ? 'Incorrect username or password. Please try again.'
        : '';

    $redirect_to = isset($_GET['redirect_to']) ? esc_url(urldecode($_GET['redirect_to'])) : esc_url(home_url('/'));

    ob_start();
    ?>
    <form class="restart-form" method="post" action="<?php echo esc_url(site_url('wp-login.php')); ?>">
        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
        <input type="hidden" name="testcookie" value="1" />

        <?php if ($error) : ?>
        <div class="restart-form__error"><?php echo esc_html($error); ?></div>
        <?php endif; ?>

        <div class="restart-form__group">
            <label class="restart-form__label" for="user_login">Username or Email</label>
            <input class="restart-form__input" type="text" id="user_login" name="log" required autocomplete="username">
        </div>

        <div class="restart-form__group">
            <label class="restart-form__label" for="user_pass">Password</label>
            <input class="restart-form__input" type="password" id="user_pass" name="pwd" required autocomplete="current-password">
        </div>

        <div class="restart-form__actions">
            <button type="submit" name="wp-submit" class="restart-btn">Log In</button>
        </div>

        <p class="restart-form__hint restart-form__footer-link">
            Don't have an account? <a href="<?php echo esc_url(wp_registration_url()); ?>">Create one</a>.
        </p>
    </form>
    <?php
    return ob_get_clean();
});

add_shortcode('restart_register_form', function () {
    if (is_user_logged_in()) {
        return '<p>' . wp_kses_post(
            sprintf('You already have an account. <a href="%s">Go to your account</a>.', esc_url(home_url('/my-account/')))
        ) . '</p>';
    }

    if (!get_option('users_can_register')) {
        return '<p>Account registration is currently closed.</p>';
    }

    ob_start();
    ?>
    <div id="rr-register-error" class="restart-form__error" hidden></div>

    <form id="rr-register-form" class="restart-form" novalidate>
        <div class="restart-form__group">
            <label class="restart-form__label" for="rr-reg-username">Username <span aria-hidden="true">*</span></label>
            <input class="restart-form__input" type="text" id="rr-reg-username" name="username"
                required autocomplete="username" maxlength="60">
        </div>

        <div class="restart-form__group">
            <label class="restart-form__label" for="rr-reg-email">Email Address <span aria-hidden="true">*</span></label>
            <input class="restart-form__input" type="email" id="rr-reg-email" name="email"
                required autocomplete="email">
        </div>

        <div class="restart-form__group">
            <label class="restart-form__label" for="rr-reg-password">Password <span aria-hidden="true">*</span></label>
            <input class="restart-form__input" type="password" id="rr-reg-password" name="password"
                required autocomplete="new-password" minlength="8">
            <p class="restart-form__hint">At least 8 characters.</p>
        </div>

        <div class="restart-form__actions">
            <button type="submit" class="restart-btn" id="rr-register-submit">Create Account</button>
        </div>

        <p class="restart-form__hint restart-form__footer-link">
            Already have an account? <a href="<?php echo esc_url(wp_login_url()); ?>">Log in</a>.
        </p>
    </form>
    <?php
    return ob_get_clean();
});

add_shortcode('restart_my_registries', function () {
    if (!is_user_logged_in()) {
        return '<p>' . wp_kses_post(
            sprintf('Please <a href="%s">log in</a> to view your registries.', esc_url(wp_login_url(get_permalink())))
        ) . '</p>';
    }

    $user    = wp_get_current_user();
    $user_id = $user->ID;

    $owned = get_posts([
        'post_type'      => 'restart-registry',
        'post_status'    => ['publish', 'private', 'draft'],
        'author'         => $user_id,
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $invited = get_posts([
        'post_type'      => 'restart-registry',
        'post_status'    => ['publish', 'private'],
        'author__not_in' => [$user_id],
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'     => 'restart_invitees',
                'value'   => $user->user_login,
                'compare' => 'LIKE',
            ],
            [
                'key'     => 'restart_invitees',
                'value'   => $user->user_email,
                'compare' => 'LIKE',
            ],
        ],
    ]);

    ob_start();
    ?>
    <div class="restart-my-registries">

        <section class="restart-my-registries__section">
            <h2 class="restart-my-registries__heading">My Registries</h2>
            <?php if (empty($owned)) : ?>
                <p class="restart-my-registries__empty">You haven't created any registries yet. <a href="<?php echo esc_url(home_url('/start-a-registry/')); ?>">Start one now</a>.</p>
            <?php else : ?>
                <ul class="restart-registry-list">
                    <?php foreach ($owned as $post) :
                        $status     = $post->post_status;
                        $event_type = get_post_meta($post->ID, 'restart_event_type', true);
                        ?>
                        <li class="restart-registry-list__item">
                            <a class="restart-registry-list__title" href="<?php echo esc_url(get_permalink($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a>
                            <span class="restart-registry-list__meta">
                                <?php if ($event_type) : ?><span class="restart-registry-list__type"><?php echo esc_html(ucfirst(str_replace('-', ' ', $event_type))); ?></span><?php endif; ?>
                                <span class="restart-registry-list__status restart-registry-list__status--<?php echo esc_attr($status); ?>"><?php echo esc_html($status === 'publish' ? 'Public' : ucfirst($status)); ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if (!empty($invited)) : ?>
        <section class="restart-my-registries__section">
            <h2 class="restart-my-registries__heading">Registries I'm Invited To</h2>
            <ul class="restart-registry-list">
                <?php foreach ($invited as $post) :
                    $owner      = get_userdata($post->post_author);
                    $event_type = get_post_meta($post->ID, 'restart_event_type', true);
                    ?>
                    <li class="restart-registry-list__item">
                        <a class="restart-registry-list__title" href="<?php echo esc_url(get_permalink($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a>
                        <span class="restart-registry-list__meta">
                            <?php if ($event_type) : ?><span class="restart-registry-list__type"><?php echo esc_html(ucfirst(str_replace('-', ' ', $event_type))); ?></span><?php endif; ?>
                            <?php if ($owner) : ?><span class="restart-registry-list__owner">by <?php echo esc_html($owner->display_name); ?></span><?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php
        $archived = get_posts([
            'post_type'      => 'restart-registry',
            'post_status'    => ['restart-archived'],
            'author'         => $user_id,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        if (!empty($archived)) : ?>
        <section class="restart-my-registries__section restart-my-registries__archived">
            <h2 class="restart-my-registries__heading restart-my-registries__heading--archived">Archived Registries</h2>
            <ul class="restart-registry-list">
                <?php foreach ($archived as $post) :
                    $event_type = get_post_meta($post->ID, 'restart_event_type', true);
                    ?>
                    <li class="restart-registry-list__item restart-registry-list__item--archived">
                        <span class="restart-registry-list__title"><?php echo esc_html($post->post_title); ?></span>
                        <span class="restart-registry-list__meta">
                            <?php if ($event_type) : ?><span class="restart-registry-list__type"><?php echo esc_html(ucfirst(str_replace('-', ' ', $event_type))); ?></span><?php endif; ?>
                            <span class="restart-registry-list__status restart-registry-list__status--archived">Archived</span>
                        </span>
                        <button type="button"
                                class="restart-registry-list__restore rr-btn-ghost"
                                data-registry-id="<?php echo esc_attr($post->ID); ?>"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('restart_registry_nonce')); ?>">
                            Restore
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
});

// Enqueue auth JS on login, register, and account pages.
add_action('wp_enqueue_scripts', function () {
    if (!is_page(['login', 'register', 'my-account'])) {
        return;
    }

    wp_enqueue_script(
        'restart-auth',
        get_stylesheet_directory_uri() . '/assets/js/auth.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );

    wp_localize_script('restart-auth', 'restartAuth', [
        'ajaxUrl'                 => admin_url('admin-ajax.php'),
        'registerNonce'           => wp_create_nonce('restart_register_nonce'),
        'updateProfileNonce'      => wp_create_nonce('restart_update_profile_nonce'),
        'deactivateAccountNonce'  => wp_create_nonce('restart_deactivate_account_nonce'),
        'deleteAccountNonce'      => wp_create_nonce('restart_delete_account_nonce'),
    ]);
});

// My Registries — Restore archived registry buttons.
add_action('wp_footer', function () {
    if (!is_page('my-registries') || !is_user_logged_in()) {
        return;
    }
    ?>
    <script>
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.restart-registry-list__restore');
        if (!btn) return;
        btn.disabled = true;
        btn.textContent = 'Restoring…';
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
            method: 'POST',
            body: new URLSearchParams({
                action:      'restart_registry_restore',
                nonce:       btn.dataset.nonce,
                registry_id: btn.dataset.registryId,
            }),
        }).then(function(r) { return r.json(); }).then(function() {
            window.location.reload();
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = 'Restore';
            alert('Could not restore the registry. Please try again.');
        });
    });
    </script>
    <?php
});

// Contact modal — enqueue JS and inject modal HTML into every page footer.
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'restart-contact-modal',
        get_stylesheet_directory_uri() . '/assets/js/contact-modal.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );
    wp_localize_script('restart-contact-modal', 'restartContact', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ]);
});

add_action('wp_footer', function () {
    $nonce = wp_create_nonce('restart_contact_submit');
    ?>
    <div id="rr-contact-modal" class="rr-modal" role="dialog" aria-modal="true" aria-labelledby="rr-contact-modal-title" hidden>
        <div class="rr-modal__overlay"></div>
        <div class="rr-modal__dialog">
            <button class="rr-modal__close" aria-label="Close">&times;</button>
            <h2 class="rr-modal__title" id="rr-contact-modal-title"><?php esc_html_e('Contact Us', 'therestart'); ?></h2>
            <form id="rr-contact-form" class="rr-contact-form" novalidate>
                <div class="rr-contact-form__field">
                    <label for="rr-contact-name"><?php esc_html_e('Name', 'therestart'); ?></label>
                    <input type="text" id="rr-contact-name" name="name" required autocomplete="name">
                </div>
                <div class="rr-contact-form__field">
                    <label for="rr-contact-email"><?php esc_html_e('Email', 'therestart'); ?></label>
                    <input type="email" id="rr-contact-email" name="email" required autocomplete="email">
                </div>
                <div class="rr-contact-form__field">
                    <label for="rr-contact-subject"><?php esc_html_e('Subject', 'therestart'); ?> <span class="rr-contact-form__optional"><?php esc_html_e('(optional)', 'therestart'); ?></span></label>
                    <input type="text" id="rr-contact-subject" name="subject" autocomplete="off">
                </div>
                <div class="rr-contact-form__field">
                    <label for="rr-contact-message"><?php esc_html_e('Message', 'therestart'); ?></label>
                    <textarea id="rr-contact-message" name="message" rows="5" required></textarea>
                </div>
                <div class="rr-contact-form__honeypot" aria-hidden="true">
                    <label for="rr-contact-website">Website</label>
                    <input type="text" id="rr-contact-website" name="website" tabindex="-1" autocomplete="off">
                </div>
                <input type="hidden" name="_nonce" value="<?php echo esc_attr($nonce); ?>">
                <button type="submit" class="rr-button rr-contact-form__submit"><?php esc_html_e('Send', 'therestart'); ?></button>
                <div class="rr-contact-form__status" role="status" aria-live="polite"></div>
            </form>
        </div>
    </div>
    <?php
});

if (!function_exists('restart_handle_contact_submit')) {
function restart_handle_contact_submit() {
    $nonce = isset($_POST['_nonce']) ? sanitize_text_field(wp_unslash($_POST['_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'restart_contact_submit')) {
        wp_send_json_error(['message' => __('Invalid request.', 'therestart')], 403);
    }

    // Honeypot: if filled, return success silently so the bot thinks it worked.
    if (!empty($_POST['website'])) {
        wp_send_json_success(['message' => __('Thanks — we will get back to you.', 'therestart')]);
    }

    $name    = isset($_POST['name'])    ? sanitize_text_field(wp_unslash($_POST['name']))     : '';
    $email   = isset($_POST['email'])   ? sanitize_email(wp_unslash($_POST['email']))         : '';
    $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject']))  : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

    $errors = [];
    if ($name === '')                       { $errors['name']    = __('Name is required.', 'therestart'); }
    if ($email === '' || !is_email($email)) { $errors['email']   = __('A valid email is required.', 'therestart'); }
    if ($message === '')                    { $errors['message'] = __('Message is required.', 'therestart'); }

    if (!empty($errors)) {
        wp_send_json_error(['errors' => $errors], 400);
    }

    $to            = get_option('admin_email');
    $email_subject = $subject !== ''
        ? sprintf('[Contact] %s', $subject)
        : sprintf(__('[Contact] Message from %s', 'therestart'), $name);
    $body          = sprintf("Name: %s\nEmail: %s\n\n%s", $name, $email, $message);
    $headers       = ['Reply-To: ' . sprintf('%s <%s>', $name, $email)];

    $sent = wp_mail($to, $email_subject, $body, $headers);
    if (!$sent) {
        wp_send_json_error(['message' => __('Could not send. Please try again later.', 'therestart')], 500);
    }

    wp_send_json_success(['message' => __('Thanks — we will get back to you.', 'therestart')]);
}
}
add_action('wp_ajax_restart_contact_submit',        'restart_handle_contact_submit');
add_action('wp_ajax_nopriv_restart_contact_submit', 'restart_handle_contact_submit');

add_filter('manage_posts_columns', function (array $columns): array {
    $columns = ['cb' => $columns['cb'], 'post_id' => 'ID'] + $columns;
    $columns['internal_link_ids'] = 'Related Posts';
    return $columns;
});

add_action('manage_posts_custom_column', function (string $column, int $post_id): void {
    if ($column === 'post_id') {
        echo esc_html($post_id);
    }
    if ($column === 'internal_link_ids') {
        $ids = get_post_meta($post_id, 'internal_link_ids', true);
        if (is_array($ids) && !empty($ids)) {
            echo esc_html(implode(', ', $ids));
        } else {
            echo '<span style="color:#999">—</span>';
        }
    }
}, 10, 2);

add_filter('manage_edit-post_sortable_columns', function (array $columns): array {
    $columns['post_id'] = 'ID';
    return $columns;
});

add_action('admin_head-edit.php', function (): void {
    echo '<style>.column-post_id { width: 3.5rem; text-align: right; } .column-post_id + th, .column-post_id + td { padding-left: 1rem; }</style>';
});

add_action('add_meta_boxes', function (): void {
    add_meta_box(
        'internal-link-ids',
        'Related Posts',
        function (WP_Post $post): void {
            $ids = get_post_meta($post->ID, 'internal_link_ids', true);
            $ids = is_array($ids) ? array_map('intval', $ids) : [];

            wp_nonce_field('internal_link_ids_save', 'internal_link_ids_nonce');
            ?>
            <ul id="rr-ilids-list" style="margin:0 0 10px;padding:0;list-style:none">
                <?php foreach ($ids as $id) :
                    $linked = get_post($id);
                    if (!$linked) continue;
                ?>
                <li data-id="<?php echo esc_attr($id); ?>" style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f0f0f0">
                    <span style="flex:1"><?php echo esc_html($linked->post_title); ?> <span style="color:#999">(ID: <?php echo esc_html($id); ?>)</span></span>
                    <button type="button" class="rr-ilids-remove button-link" style="color:#b32d2e">Remove</button>
                </li>
                <?php endforeach; ?>
            </ul>
            <input type="hidden" id="rr-ilids-value" name="internal_link_ids" value="<?php echo esc_attr(wp_json_encode($ids)); ?>">
            <input type="text" id="rr-ilids-search" placeholder="Search posts to link…" class="widefat" autocomplete="off">
            <ul id="rr-ilids-results" style="border:1px solid #ddd;display:none;list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto;background:#fff;position:absolute;z-index:100;width:calc(100% - 28px)"></ul>
            <script>
            (function () {
                const search  = document.getElementById('rr-ilids-search');
                const results = document.getElementById('rr-ilids-results');
                const list    = document.getElementById('rr-ilids-list');
                const hidden  = document.getElementById('rr-ilids-value');
                const nonce   = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
                const apiRoot = <?php echo wp_json_encode(rest_url('wp/v2/posts')); ?>;
                const exclude = <?php echo (int) $post->ID; ?>;

                const getIds = () => JSON.parse(hidden.value || '[]');
                const setIds = ids => {
                    hidden.value = JSON.stringify(ids);
                    // Meta boxes run in a hidden iframe; dispatch into the
                    // parent frame's Gutenberg store so the REST API save
                    // picks up our changes instead of overwriting them.
                    const parentWp = window.parent && window.parent.wp;
                    const store = parentWp && parentWp.data && parentWp.data.dispatch('core');
                    if (store) {
                        store.editEntityRecord('postType', 'post', <?php echo (int) $post->ID; ?>, {
                            meta: { internal_link_ids: ids }
                        });
                    }
                };

                function addPost(id, title) {
                    const ids = getIds();
                    if (ids.includes(id)) return;
                    setIds([...ids, id]);
                    const li = document.createElement('li');
                    li.dataset.id = id;
                    li.style.cssText = 'display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f0f0f0';
                    li.innerHTML = `<span style="flex:1">${title} <span style="color:#999">(ID: ${id})</span></span><button type="button" class="rr-ilids-remove button-link" style="color:#b32d2e">Remove</button>`;
                    list.appendChild(li);
                }

                list.addEventListener('click', e => {
                    const btn = e.target.closest('.rr-ilids-remove');
                    if (!btn) return;
                    const li = btn.closest('li');
                    setIds(getIds().filter(i => i !== parseInt(li.dataset.id)));
                    li.remove();
                });

                let timer;
                search.addEventListener('input', function () {
                    clearTimeout(timer);
                    const q = this.value.trim();
                    if (!q) { results.style.display = 'none'; return; }
                    timer = setTimeout(() => {
                        fetch(`${apiRoot}?search=${encodeURIComponent(q)}&per_page=10&status=publish&exclude=${exclude}&_fields=id,title`, {
                            headers: { 'X-WP-Nonce': nonce }
                        })
                        .then(r => r.json())
                        .then(posts => {
                            results.innerHTML = '';
                            if (!posts.length) { results.style.display = 'none'; return; }
                            posts.forEach(p => {
                                const li = document.createElement('li');
                                li.style.cssText = 'padding:6px 10px;cursor:pointer';
                                li.textContent = `${p.title.rendered} (ID: ${p.id})`;
                                li.onmouseenter = () => li.style.background = '#f0f0f0';
                                li.onmouseleave = () => li.style.background = '';
                                li.addEventListener('click', () => {
                                    addPost(p.id, p.title.rendered);
                                    search.value = '';
                                    results.style.display = 'none';
                                });
                                results.appendChild(li);
                            });
                            results.style.display = 'block';
                        });
                    }, 300);
                });

                document.addEventListener('click', e => {
                    if (!results.contains(e.target) && e.target !== search) {
                        results.style.display = 'none';
                    }
                });
            })();
            </script>
            <?php
        },
        'post',
        'normal',
        'default'
    );
});

add_action('save_post', function (int $post_id): void {
    if (!isset($_POST['internal_link_ids_nonce'])) return;
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['internal_link_ids_nonce'])), 'internal_link_ids_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $raw = sanitize_text_field(wp_unslash($_POST['internal_link_ids'] ?? '[]'));
    $ids = json_decode($raw, true);
    $ids = array_values(array_map('intval', array_filter((array) $ids)));

    update_post_meta($post_id, 'internal_link_ids', $ids);
});

// When a cover block uses useFeaturedImage but the post has none, fall back to
// image 162 so the hero always has a background.
add_filter('render_block_data', function (array $block): array {
    if ($block['blockName'] !== 'core/cover' || empty($block['attrs']['useFeaturedImage'])) {
        return $block;
    }
    if (get_the_post_thumbnail_url()) {
        return $block;
    }
    $fallback_url = wp_get_attachment_image_url(162, 'full');
    if ($fallback_url) {
        $block['attrs']['useFeaturedImage'] = false;
        $block['attrs']['id']               = 162;
        $block['attrs']['url']              = $fallback_url;
    }
    return $block;
});

// Inject category-specific single templates into the block theme hierarchy.
// WP doesn't natively support single-category-{slug}.html, so we add them here.
add_filter('block_template_hierarchy', function (array $templates): array {
    if (!is_singular('post')) {
        return $templates;
    }

    $categories = get_the_category();
    if (empty($categories)) {
        return $templates;
    }

    $category_slugs = array_map(
        fn($cat) => 'single-category-' . $cat->slug,
        $categories
    );

    $single_pos = array_search('single', $templates);
    if ($single_pos !== false) {
        array_splice($templates, $single_pos, 0, $category_slugs);
    }

    return $templates;
});

add_action('init', function () {
    register_post_meta('post', 'internal_link_ids', [
        'show_in_rest' => [
            'schema' => [
                'type'  => 'array',
                'items' => ['type' => 'integer'],
            ],
        ],
        'single'  => true,
        'type'    => 'array',
        'default' => [],
    ]);
});

add_shortcode('internal_links', function () {
    $ids = get_post_meta(get_the_ID(), 'internal_link_ids', true);
    if (empty($ids)) {
        return '';
    }

    $cards      = [];
    $per_page   = 3;

    foreach ($ids as $post_id) {
        $linked = get_post($post_id);
        if (!$linked || $linked->post_status !== 'publish') {
            continue;
        }
        $title   = get_the_title($linked);
        $excerpt = get_the_excerpt($linked);
        $url     = get_permalink($linked);

        $excerpt_html = $excerpt
            ? '<p style="color:#4d6a72;font-family:var(--wp--preset--font-family--libre-caslon-text);font-size:15px;line-height:1.6;margin-bottom:1.25rem">' . esc_html(wp_trim_words($excerpt, 30)) . '</p>'
            : '';

        $cards[] = '<div class="rr-il-card" style="background-color:#ffffff;border-radius:8px;border:1px solid #c8e8d4;box-shadow:0 2px 12px rgba(25,53,64,0.07);padding:2rem">'
            . '<h3 style="color:#193540;font-family:var(--wp--preset--font-family--montserrat);font-size:24px;font-weight:700;margin-top:0;margin-bottom:0.75rem">' . esc_html($title) . '</h3>'
            . $excerpt_html
            . '<a href="' . esc_url($url) . '" style="display:inline-block;background-color:#193540;color:#ffffff;border-radius:4px;padding:0.6em 1.2em;font-family:var(--wp--preset--font-family--montserrat);font-size:13px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;text-decoration:none">Read more →</a>'
            . '</div>';
    }

    if (!$cards) {
        return '';
    }

    $total      = count($cards);
    $pages      = (int) ceil($total / $per_page);
    $cards_html = implode('', $cards);

    $btn_style  = 'background-color:#193540;color:#ffffff;border:none;border-radius:4px;padding:0.6em 1.2em;font-family:var(--wp--preset--font-family--montserrat);font-size:13px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;cursor:pointer';

    $pagination = $pages > 1
        ? '<div style="display:flex;align-items:center;justify-content:center;gap:1.5rem;margin-top:2rem">'
            . '<button id="rr-il-prev" type="button" style="' . $btn_style . '" disabled>← Prev</button>'
            . '<span id="rr-il-page" style="color:#193540;font-family:var(--wp--preset--font-family--montserrat);font-size:13px;font-weight:700">1 / ' . $pages . '</span>'
            . '<button id="rr-il-next" type="button" style="' . $btn_style . '">Next →</button>'
            . '</div>'
            . '<script>(function(){'
            . 'var cards=document.querySelectorAll(".rr-il-card"),prev=document.getElementById("rr-il-prev"),next=document.getElementById("rr-il-next"),indicator=document.getElementById("rr-il-page"),n=' . $per_page . ',pages=' . $pages . ',p=0;'
            . 'function show(){cards.forEach(function(c,i){c.style.display=(i>=p*n&&i<(p+1)*n)?"":"none";});prev.disabled=p===0;prev.style.opacity=p===0?"0.4":"1";next.disabled=p===pages-1;next.style.opacity=p===pages-1?"0.4":"1";indicator.textContent=(p+1)+" / "+pages;}'
            . 'prev.addEventListener("click",function(){if(p>0){p--;show();}});'
            . 'next.addEventListener("click",function(){if(p<pages-1){p++;show();}});'
            . 'show();'
            . '})();</script>'
        : '';

    return '<hr style="background-color:#9fd4b3;color:#9fd4b3;margin-top:var(--wp--preset--spacing--large);margin-bottom:var(--wp--preset--spacing--large);border:none;height:1px"/>'
        . '<div style="background-color:#ffffff;padding-top:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--large)">'
        . '<div style="max-width:80%;margin-left:auto;margin-right:auto">'
        . '<h2 style="color:#47b4b0;font-family:var(--wp--preset--font-family--montserrat);font-size:13px;font-weight:700;letter-spacing:0.08em;margin-bottom:0.5rem;text-align:center;text-transform:uppercase">What other mountains lie ahead?</h2>'
        . '<h3 style="color:#193540;font-family:var(--wp--preset--font-family--montserrat);font-size:clamp(28px,4vw,42px);font-weight:700;margin-top:0;margin-bottom:var(--wp--preset--spacing--small);text-align:center">You might also find these articles interesting:</h3>'
        . '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:2rem">' . $cards_html . '</div>'
        . $pagination
        . '</div>'
        . '</div>';
});

// Fix: WP strips inherit:true from query block context (it equals the default), so
// category/taxonomy archives don't filter the query loop. Inject the term filter here.
add_filter('query_loop_block_query_vars', function (array $query, WP_Block $block, int $page): array {
    if (is_category()) {
        $term = get_queried_object();
        if ($term instanceof WP_Term) {
            $query['cat'] = $term->term_id;
        }
    } elseif (is_tag()) {
        $term = get_queried_object();
        if ($term instanceof WP_Term) {
            $query['tag_id'] = $term->term_id;
        }
    } elseif (is_tax()) {
        $term = get_queried_object();
        if ($term instanceof WP_Term) {
            $query['tax_query'][] = [
                'taxonomy' => $term->taxonomy,
                'field'    => 'term_id',
                'terms'    => [$term->term_id],
            ];
        }
    }
    return $query;
}, 10, 3);
