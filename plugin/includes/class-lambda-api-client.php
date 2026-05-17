<?php

/**
 * Lambda API Client
 *
 * HTTP client for the Restart Lambda FastAPI service.
 * Configure the endpoint via WP option 'restart_lambda_url' or the
 * RESTART_LAMBDA_URL environment variable.
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Lambda_Client {

    /** @var string Base URL for the Lambda/FastAPI service (no trailing slash). */
    private $base_url;

    /** @var int HTTP request timeout in seconds. */
    private $timeout = 10;

    /** @var string|null API Gateway x-api-key value, or null if unconfigured. */
    private $api_key;

    /** @var string|null Basic-auth credentials as "username:password", or null if unconfigured. */
    private $auth;

    public function __construct() {
        $this->base_url = rtrim(
            get_option('restart_lambda_url') ?: getenv('RESTART_LAMBDA_URL') ?: '',
            '/'
        );

        $this->api_key = get_option('restart_lambda_api_key') ?: getenv('RESTART_LAMBDA_API_KEY') ?: null;

        $username = get_option('restart_lambda_username') ?: getenv('RESTART_LAMBDA_USERNAME') ?: '';
        $password = get_option('restart_lambda_app_password') ?: getenv('RESTART_LAMBDA_APP_PASSWORD') ?: '';
        if ($username && $password) {
            $this->auth = base64_encode("{$username}:{$password}");
        }
    }

    /** True when a Lambda URL has been configured. */
    public function is_configured(): bool {
        return !empty($this->base_url);
    }

    // -------------------------------------------------------------------------
    // Items
    // -------------------------------------------------------------------------

    /**
     * Fetch a single item. Returns the item array, null if 404, or WP_Error.
     */
    public function get_item(int $item_id) {
        $response = $this->request('GET', "/items/{$item_id}");
        if (is_wp_error($response) || $response === null) {
            return $response;
        }
        return $response['data'] ?? $response;
    }

    /**
     * Fetch multiple items by ID. Skips IDs that return 404.
     * Returns a flat array of item arrays.
     */
    public function get_items(array $item_ids): array {
        $items = [];
        foreach ($item_ids as $id) {
            $item = $this->get_item((int) $id);
            if ($item && !is_wp_error($item)) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * Create a new item. Returns the created item array or WP_Error.
     *
     * Required keys: name, url, price.
     * Optional: description, retailer, affiliate_status, quantity_needed.
     */
    public function create_item(array $data) {
        $response = $this->request('POST', '/items', $data);
        if (is_wp_error($response)) {
            return $response;
        }
        return $response['data'] ?? $response;
    }

    /**
     * Update an item. Returns the updated item array or WP_Error.
     *
     * Accepted keys: name, description, price, quantity_needed,
     *                quantity_purchased, is_active.
     */
    public function update_item(int $item_id, array $data) {
        $response = $this->request('PUT', "/items/{$item_id}", $data);
        if (is_wp_error($response)) {
            return $response;
        }
        return $response['data'] ?? $response;
    }

    /**
     * Delete an item. Returns the deleted item array or WP_Error.
     */
    public function delete_item(int $item_id) {
        $response = $this->request('DELETE', "/items/{$item_id}");
        if (is_wp_error($response)) {
            return $response;
        }
        return $response['data'] ?? $response;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Make an HTTP request to the Lambda service.
     *
     * @return array|null|WP_Error  Parsed JSON body, null on 404, WP_Error on failure.
     */
    private function request(string $method, string $path, ?array $body = null) {
        if (!$this->is_configured()) {
            return new WP_Error(
                'lambda_not_configured',
                __('Lambda API URL is not configured. Set the "restart_lambda_url" option in WP settings.', 'restart-registry')
            );
        }

        $headers     = ['Content-Type' => 'application/json'];
        $service_key = get_option('restart_lambda_api_key') ?: getenv('WP_SERVICE_KEY') ?: null;

        if ($service_key) {
            $user = wp_get_current_user();
            $headers['X-Service-Key']  = $service_key;
            $headers['X-WP-User-ID']   = (string) $user->ID;
            $headers['X-WP-Username']  = $user->user_login;
            $headers['X-WP-Roles']     = implode(',', (array) $user->roles);
        } elseif ($this->api_key) {
            $headers['x-api-key'] = $this->api_key;
        } elseif ($this->auth) {
            $headers['Authorization'] = 'Basic ' . $this->auth;
        }

        $args = [
            'method'  => $method,
            'timeout' => $this->timeout,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $url      = $this->base_url . $path;
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code         = (int) wp_remote_retrieve_response_code($response);
        $raw_body     = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($raw_body, true);

        if ($code === 404) {
            return null;
        }

        if ($code >= 400) {
            $detail = $decoded_body['detail'] ?? __('Lambda API error.', 'restart-registry');
            return new WP_Error('lambda_error', $detail, ['status' => $code]);
        }

        return $decoded_body;
    }
}