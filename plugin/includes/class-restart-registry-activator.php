<?php

/**
 * Fired during plugin activation.
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Activator {

    public static function activate() {
        self::register_registry_user_role();
        self::add_capabilities();
        self::create_pages();
        self::install_mu_plugins();
        flush_rewrite_rules();
    }

    public static function install_mu_plugins(): void {
        $src_dir = plugin_dir_path( dirname( __FILE__ ) ) . 'mu-plugins/';
        $dst_dir = WP_CONTENT_DIR . '/mu-plugins/';

        if ( ! is_dir( $dst_dir ) ) {
            wp_mkdir_p( $dst_dir );
        }

        $files     = [ 'restart-registry-cpt.php', 'restart-auth.php' ];
        $installed = [];

        foreach ( $files as $file ) {
            if ( copy( $src_dir . $file, $dst_dir . $file ) ) {
                $installed[] = $file;
            }
        }

        update_option( 'restart_registry_mu_plugins', $installed );
    }

    public static function register_registry_user_role(): void {
        if (!get_role('registry-user')) {
            add_role('registry-user', __('Registry User', 'restart-registry'), [
                'read' => true,
            ]);
        }
    }

    private static function add_capabilities() {
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_restart_registry');
        }

        // Subscribers (the default role for registry owners) need upload_files
        // so they can pick a hero image via the WP media library on their own
        // registry. WP enforces ownership filters by default, so this only
        // exposes uploads to authenticated users — not anonymous visitors.
        // Tradeoff: media-library quota grows with users; size cap +
        // media filtering live downstream of this cap grant.
        $subscriber = get_role('subscriber');
        if ($subscriber) {
            $subscriber->add_cap('upload_files');
        }
        $registry_user = get_role('registry-user');
        if ($registry_user) {
            $registry_user->add_cap('upload_files');
        }
    }

    public static function create_pages(): void {
        $pages = [
            [ 'title' => 'Home',                'slug' => 'home',                 'template' => '' ],
            [ 'title' => 'Login',               'slug' => 'login',                'template' => 'page-login' ],
            [ 'title' => 'Register',            'slug' => 'register',             'template' => 'page-register' ],
            [ 'title' => 'My Account',          'slug' => 'my-account',           'template' => 'page-my-account' ],
            [ 'title' => 'My Registries',       'slug' => 'my-registries',        'template' => 'page-my-registries' ],
            [ 'title' => 'Start a Registry',    'slug' => 'start-a-registry',     'template' => 'page-start-a-registry' ],
            [ 'title' => 'Find a Registry',     'slug' => 'find-a-registry',      'template' => '' ],
            [ 'title' => 'FAQ',                 'slug' => 'faq',                  'template' => 'page-faq',      'copy' => true ],
            [ 'title' => 'About Us',            'slug' => 'about-us',             'template' => 'page-about-us', 'copy' => true ],
            [ 'title' => 'Terms and Conditions','slug' => 'terms-and-conditions', 'template' => '',              'copy' => true ],
            [ 'title' => 'Privacy Policy',      'slug' => 'privacy-policy',       'template' => '',              'copy' => true ],
        ];

        $ids = [];
        foreach ( $pages as $page ) {
            $content = ! empty( $page['copy'] ) ? self::read_copy( $page['slug'] ) : '';
            $ids[ $page['slug'] ] = self::ensure_page( $page['title'], $page['slug'], $page['template'], $content );
        }

        if ( ! empty( $ids['home'] ) ) {
            update_option( 'show_on_front', 'page' );
            update_option( 'page_on_front', $ids['home'] );
        }

        if ( ! empty( $ids['my-registries'] ) ) {
            update_option( 'restart_registry_page_id', $ids['my-registries'] );
        }

        update_option( 'users_can_register', 1 );
        update_option( 'default_role', 'registry-user' );
    }

    private static function ensure_page( string $title, string $slug, string $template = '', string $content = '' ): ?int {
        $existing = get_posts( [
            'post_type'   => 'page',
            'post_status' => [ 'publish', 'draft', 'private' ],
            'name'        => $slug,
            'numberposts' => 1,
        ] );

        if ( ! empty( $existing ) ) {
            $page    = $existing[0];
            $updates = [];
            if ( $page->post_status !== 'publish' ) {
                $updates['post_status'] = 'publish';
                $updates['post_title']  = $title;
            }
            // Backfill content only if the page is still empty — never overwrite real edits.
            if ( $content && trim( $page->post_content ) === '' ) {
                $updates['post_content'] = $content;
            }
            if ( ! empty( $updates ) ) {
                wp_update_post( array_merge( [ 'ID' => $page->ID ], $updates ) );
            }
            return $page->ID;
        }

        $page_id = wp_insert_post( [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ] );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            if ( $template ) {
                update_post_meta( $page_id, '_wp_page_template', $template );
            }
            return $page_id;
        }

        return null;
    }

    private static function read_copy( string $slug ): string {
        $path = get_template_directory() . "/assets/copy/{$slug}.html";
        return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
    }
}
