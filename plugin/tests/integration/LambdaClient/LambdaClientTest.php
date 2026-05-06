<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class LambdaClientTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(fn($key, $default = '') => match ($key) {
            'restart_lambda_url' => 'http://lambda:5000',
            default              => '',
        });
        Functions\when('getenv')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('wp_json_encode')->alias(fn($v) => json_encode($v));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')
            ->alias(fn($r) => is_array($r) ? (int) ($r['response']['code'] ?? 0) : 0);
        Functions\when('wp_remote_retrieve_body')
            ->alias(fn($r) => is_array($r) ? ($r['body'] ?? '') : '');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mockResponse(int $code, array $body): array {
        return ['response' => ['code' => $code], 'body' => json_encode($body)];
    }

    public function test_returns_error_when_not_configured(): void {
        Functions\when('get_option')->justReturn('');
        $client = new Restart_Registry_Lambda_Client();
        $this->assertInstanceOf(WP_Error::class, $client->get_item(1));
    }

    public function test_get_item_returns_item_on_200(): void {
        Functions\when('wp_remote_request')->justReturn(
            $this->mockResponse(200, ['id' => 1, 'name' => 'Blender'])
        );
        $client = new Restart_Registry_Lambda_Client();
        $this->assertSame(['id' => 1, 'name' => 'Blender'], $client->get_item(1));
    }

    public function test_get_item_returns_null_on_404(): void {
        Functions\when('wp_remote_request')->justReturn($this->mockResponse(404, []));
        $client = new Restart_Registry_Lambda_Client();
        $this->assertNull($client->get_item(1));
    }

    public function test_get_item_returns_error_on_500(): void {
        Functions\when('wp_remote_request')->justReturn(
            $this->mockResponse(500, ['detail' => 'Server error'])
        );
        $client = new Restart_Registry_Lambda_Client();
        $this->assertInstanceOf(WP_Error::class, $client->get_item(1));
    }

    public function test_create_item_posts_to_items_endpoint(): void {
        $capturedUrl  = '';
        $capturedArgs = [];
        Functions\expect('wp_remote_request')->once()->andReturnUsing(
            function ($url, $args) use (&$capturedUrl, &$capturedArgs) {
                $capturedUrl  = $url;
                $capturedArgs = $args;
                return ['response' => ['code' => 201], 'body' => json_encode(['id' => 99, 'name' => 'Blender'])];
            }
        );

        $client = new Restart_Registry_Lambda_Client();
        $client->create_item(['name' => 'Blender', 'url' => 'http://a.com']);

        $this->assertStringEndsWith('/items', $capturedUrl);
        $this->assertSame('POST', $capturedArgs['method']);
    }

    public function test_update_item_puts_to_item_endpoint(): void {
        $capturedUrl  = '';
        $capturedArgs = [];
        Functions\expect('wp_remote_request')->once()->andReturnUsing(
            function ($url, $args) use (&$capturedUrl, &$capturedArgs) {
                $capturedUrl  = $url;
                $capturedArgs = $args;
                return ['response' => ['code' => 200], 'body' => json_encode(['id' => 5])];
            }
        );

        $client = new Restart_Registry_Lambda_Client();
        $client->update_item(5, ['name' => 'Updated']);

        $this->assertStringEndsWith('/items/5', $capturedUrl);
        $this->assertSame('PUT', $capturedArgs['method']);
    }

    public function test_sends_api_key_header_when_configured(): void {
        Functions\when('get_option')->alias(fn($key, $default = '') => match ($key) {
            'restart_lambda_url'     => 'http://lambda:5000',
            'restart_lambda_api_key' => 'test-api-key',
            default                  => '',
        });

        $capturedArgs = [];
        Functions\expect('wp_remote_request')->once()->andReturnUsing(
            function ($url, $args) use (&$capturedArgs) {
                $capturedArgs = $args;
                return ['response' => ['code' => 200], 'body' => json_encode(['id' => 1])];
            }
        );

        $client = new Restart_Registry_Lambda_Client();
        $client->get_item(1);

        $this->assertArrayHasKey('x-api-key', $capturedArgs['headers']);
        $this->assertSame('test-api-key', $capturedArgs['headers']['x-api-key']);
    }

    public function test_sends_basic_auth_header_when_configured(): void {
        Functions\when('get_option')->alias(fn($key, $default = '') => match ($key) {
            'restart_lambda_url'          => 'http://lambda:5000',
            'restart_lambda_username'     => 'user',
            'restart_lambda_app_password' => 'pass',
            default                       => '',
        });

        $capturedArgs = [];
        Functions\expect('wp_remote_request')->once()->andReturnUsing(
            function ($url, $args) use (&$capturedArgs) {
                $capturedArgs = $args;
                return ['response' => ['code' => 200], 'body' => json_encode(['id' => 1])];
            }
        );

        $client = new Restart_Registry_Lambda_Client();
        $client->get_item(1);

        $this->assertArrayHasKey('Authorization', $capturedArgs['headers']);
        $this->assertSame('Basic ' . base64_encode('user:pass'), $capturedArgs['headers']['Authorization']);
    }

    public function test_propagates_wp_error_from_wp_remote_request(): void {
        Functions\when('wp_remote_request')->justReturn(new WP_Error('http_failure', 'connection refused'));
        $client = new Restart_Registry_Lambda_Client();
        $this->assertInstanceOf(WP_Error::class, $client->get_item(1));
    }

    public function test_get_items_skips_404s(): void {
        $responses = [
            $this->mockResponse(200, ['id' => 1, 'name' => 'Found']),
            $this->mockResponse(404, []),
        ];
        Functions\when('wp_remote_request')->alias(function () use (&$responses) {
            return array_shift($responses);
        });

        $client = new Restart_Registry_Lambda_Client();
        $items  = $client->get_items([1, 2]);

        $this->assertCount(1, $items);
        $this->assertSame(['id' => 1, 'name' => 'Found'], $items[0]);
    }
}
