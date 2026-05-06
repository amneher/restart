<?php

declare(strict_types=1);

define('THEME_DIR', dirname(__DIR__));

require_once THEME_DIR . '/vendor/autoload.php';

if (!class_exists('WP_User')) {
    class WP_User
    {
        public int $ID = 0;
        public string $user_login = '';
        public string $user_email = '';
        public string $display_name = '';
    }
}

if (!class_exists('WP_Theme')) {
    class WP_Theme
    {
        public function get(string $key): string
        {
            return $key === 'Version' ? '1.0.0' : '';
        }
    }
}

if (!class_exists('WP_Term')) {
    class WP_Term
    {
        public int $term_id = 0;
        public string $taxonomy = '';
        public string $slug = '';
    }
}

if (!class_exists('WP_Block')) {
    class WP_Block
    {
    }
}

if (!class_exists('WP_Application_Passwords')) {
    class WP_Application_Passwords
    {
        public static function create_new_application_password(int $user_id, array $args): array
        {
            return ['test-app-password-123', ['name' => $args['name']]];
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public string $code;
        public string $message;

        public function __construct(string $code = '', string $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }
    }
}
