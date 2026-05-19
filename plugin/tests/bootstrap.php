<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists('WP_Error')) {
    class WP_Error {
        public string $code;
        public string $message;
        public function __construct(string $code = '', string $message = '', mixed $data = null) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message(): string { return $this->message; }
        public function get_error_code(): string    { return $this->code; }
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int    $ID           = 0;
        public int    $post_author  = 0;
        public string $post_title   = '';
        public string $post_name    = '';
        public string $post_content = '';
        public string $post_status  = 'publish';
        public string $post_type    = 'restart-registry';
    }
}

if (!class_exists('WP_User')) {
    class WP_User {
        public int    $ID           = 0;
        public string $user_email   = '';
        public string $user_login   = '';
        public string $display_name = '';
    }
}

// Plugin classes under test
require_once dirname(__DIR__) . '/includes/class-restart-registry-activator.php';
require_once dirname(__DIR__) . '/includes/class-retailer-api.php';
require_once dirname(__DIR__) . '/includes/class-affiliate-converter.php';
require_once dirname(__DIR__) . '/includes/class-lambda-api-client.php';
require_once dirname(__DIR__) . '/includes/class-restart-registry-controller.php';
require_once dirname(__DIR__) . '/includes/class-product-scraper.php';
require_once dirname(__DIR__) . '/admin/class-restart-registry-admin.php';

// Test fakes
require_once __DIR__ . '/Fakes/LambdaClientFake.php';
