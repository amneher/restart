<?php
/**
 * MU-Plugin: Register the restart-registry custom post type with REST API support.
 */

// Enable Application Password auth over plain HTTP in local environments only.
if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local' ) {
    add_filter( 'wp_is_application_passwords_available', '__return_true' );
}

add_action('init', function () {
    if (!get_role('registry_user')) {
        add_role('registry_user', 'Registry User', ['read' => true]);
    }

    // read_private_restart_registries is intentionally omitted: granting it would let
    // any registry user read every other user's private registry via the WP REST API.
    // Invitee access to private registries is handled by the map_meta_cap filter below.
    $registry_user_caps = [
        'edit_restart_registries',
        'publish_restart_registries',
        'delete_restart_registries',
    ];
    $registry_user = get_role('registry_user');
    foreach ($registry_user_caps as $cap) {
        $registry_user->add_cap($cap);
    }
    $registry_user->remove_cap('read_private_restart_registries');

    $admin_caps = [
        'edit_restart_registries',
        'edit_others_restart_registries',
        'edit_private_restart_registries',
        'edit_published_restart_registries',
        'publish_restart_registries',
        'read_private_restart_registries',
        'delete_restart_registries',
        'delete_others_restart_registries',
        'delete_private_restart_registries',
        'delete_published_restart_registries',
    ];
    $admin = get_role('administrator');
    if ($admin) {
        foreach ($admin_caps as $cap) {
            $admin->add_cap($cap);
        }
    }
});

add_filter('map_meta_cap', function (array $caps, string $cap, int $user_id, array $args): array {
    if ($cap !== 'read_post' || empty($args[0])) {
        return $caps;
    }
    $post = get_post($args[0]);
    if (!$post || $post->post_type !== 'restart-registry' || $post->post_status !== 'private') {
        return $caps;
    }
    if ($caps === ['read']) {
        return $caps;
    }
    $invitees = json_decode(get_post_meta($post->ID, 'restart_invitees', true) ?: '[]', true) ?: [];
    if (empty($invitees)) {
        return $caps;
    }
    $user = get_userdata($user_id);
    if ($user && (
        in_array($user->user_email, $invitees, true) ||
        in_array($user->user_login,  $invitees, true)
    )) {
        return ['read'];
    }
    return $caps;
}, 10, 4);

add_action('init', function () {
    register_post_type('restart-registry', [
        'label'               => 'Registries',
        'labels'              => [
            'name'          => 'Registries',
            'singular_name' => 'Registry',
            'add_new_item'  => 'Create a Registry',
            'edit_item'     => 'Edit Registry',
            'not_found'     => 'No Registries Found',
        ],
        'description'         => "Consists of a user's story, info, and list of items they wished for.",
        'public'              => true,
        'show_in_rest'        => true,
        'rest_base'           => 'restart-registry',
        'supports'            => ['title', 'editor', 'excerpt', 'custom-fields', 'author'],
        'taxonomies'          => ['category', 'post_tag'],
        'has_archive'         => 'registry',
        'hierarchical'        => false,
        'rewrite'             => ['slug' => 'registry'],
        'capability_type'     => ['restart_registry', 'restart_registries'],
        'map_meta_cap'        => true,
    ]);
});

add_action('init', function () {
    $meta_args = [
        'object_subtype' => 'restart-registry',
        'show_in_rest'   => true,
        'single'         => true,
        'type'           => 'string',
    ];

    register_post_meta('restart-registry', 'restart_invitees', $meta_args);
    register_post_meta('restart-registry', 'restart_item_ids', $meta_args);
    register_post_meta('restart-registry', 'restart_event_type', $meta_args);
    register_post_meta('restart-registry', 'restart_event_date', $meta_args);
});
