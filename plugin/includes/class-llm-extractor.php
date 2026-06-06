<?php
declare(strict_types=1);

/**
 * LLM-powered product metadata extractor.
 *
 * Slices the fetched HTML down to the <head> section and any JSON-LD blocks
 * (typically 2–5 KB), then calls the Anthropic API with a forced tool_use
 * response to get structured product data back without regex maintenance.
 *
 * Returns an empty array when:
 *   - no API key is configured (caller should fall back to regex extraction)
 *   - the HTML yields no useful context to send
 *   - the API call fails for any reason
 *
 * Usable outside WordPress: falls back to a cURL implementation when
 * wp_remote_post() is unavailable (integration tests, CLI).
 */
class Restart_Registry_LLM_Extractor {

    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL   = 'claude-haiku-4-5-20251001';

    private const TOOL = [
        'name'         => 'extract_product_data',
        'description'  => 'Extract product metadata from HTML meta tags and structured data.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'name'        => ['type' => 'string',           'description' => 'Product name, clean, without retailer name suffix.'],
                'price'       => ['type' => ['number', 'null'], 'description' => 'Product sale price as a number, or null if not found.'],
                'image_url'   => ['type' => 'string',           'description' => 'Absolute URL of the primary product image, or empty string.'],
                'description' => ['type' => 'string',           'description' => 'Product description, 160 characters or fewer.'],
            ],
            'required' => ['name', 'price', 'image_url', 'description'],
        ],
    ];

    /**
     * Extract product metadata from a fetched HTML body.
     *
     * @return array{name:string,price:mixed,image_url:string,description:string}|array{}
     */
    public function extract(string $url, string $html_body): array {
        $api_key = $this->get_api_key();
        if ($api_key === '') {
            return [];
        }

        $context = $this->slice_html($html_body);
        if ($context === '') {
            return [];
        }

        $payload = [
            'model'       => self::MODEL,
            'max_tokens'  => 256,
            'tool_choice' => ['type' => 'tool', 'name' => 'extract_product_data'],
            'tools'       => [self::TOOL],
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => "Extract product metadata from this HTML. Source URL: {$url}\n\n{$context}",
                ],
            ],
        ];

        $response = $this->http_post(self::API_URL, $api_key, $payload);
        if ($response === '') {
            return [];
        }

        return $this->parse_response($response);
    }

    /**
     * Reduce a full HTML document to only the parts useful for extraction:
     * the <head> section and any JSON-LD <script> blocks from anywhere in the document.
     */
    public function slice_html(string $html): string {
        $parts = [];

        if (preg_match('/<head[^>]*>(.*?)<\/head>/is', $html, $m)) {
            $parts[] = '<head>' . $m[1] . '</head>';
        }

        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is', $html, $scripts)) {
            foreach ($scripts[0] as $block) {
                $parts[] = $block;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Parse an Anthropic API response body and return structured product data.
     *
     * @return array{name:string,price:mixed,image_url:string,description:string}|array{}
     */
    public function parse_response(string $response_body): array {
        $data = json_decode($response_body, true);
        if (!is_array($data)) {
            return [];
        }

        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'extract_product_data') {
                $input = $block['input'] ?? [];
                return [
                    'name'        => trim((string) ($input['name']      ?? '')),
                    'price'       => isset($input['price']) && $input['price'] !== null ? (float) $input['price'] : '',
                    'image_url'   => trim((string) ($input['image_url'] ?? '')),
                    'description' => trim((string) ($input['description'] ?? '')),
                ];
            }
        }

        return [];
    }

    private function get_api_key(): string {
        if (function_exists('get_option')) {
            return (string) get_option('restart_registry_anthropic_api_key', '');
        }
        return '';
    }

    private function http_post(string $url, string $api_key, array $payload): string {
        $body    = (string) json_encode($payload);
        $headers = [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ];

        if (function_exists('wp_remote_post')) {
            $response = wp_remote_post($url, [
                'timeout' => 15,
                'headers' => $headers,
                'body'    => $body,
            ]);

            if (function_exists('is_wp_error') && is_wp_error($response)) {
                return '';
            }

            $code = function_exists('wp_remote_retrieve_response_code')
                ? (int) wp_remote_retrieve_response_code($response)
                : 0;

            if ($code !== 200) {
                return '';
            }

            $resp_body = function_exists('wp_remote_retrieve_body')
                ? wp_remote_retrieve_body($response)
                : '';

            return is_string($resp_body) ? $resp_body : '';
        }

        return $this->curl_post($url, $body, $headers);
    }

    private function curl_post(string $url, string $body, array $headers): string {
        $header_lines = array_map(
            static fn(string $k, string $v): string => "{$k}: {$v}",
            array_keys($headers),
            array_values($headers)
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($code === 200 && is_string($result)) ? $result : '';
    }
}
