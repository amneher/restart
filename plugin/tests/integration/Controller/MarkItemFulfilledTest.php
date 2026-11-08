<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class MarkItemFulfilledTest extends TestCase {
    use MockeryPHPUnitIntegration;

    private LambdaClientFake $fake;
    private Restart_Registry_Controller $controller;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__, 3) . '/includes/');
        Functions\when('getenv')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('get_option')->alias(fn($key, $default = '') => $default ?: '');

        $this->fake       = new LambdaClientFake();
        $this->controller = new Restart_Registry_Controller($this->fake);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function updatePayloads(): array {
        return array_values(array_map(
            fn($c) => $c['args'][1],
            array_filter($this->fake->getCalls(), fn($c) => $c['method'] === 'update_item')
        ));
    }

    public function test_zero_purchased_assumes_one_bought(): void {
        $this->fake->setItem(1, ['quantity_needed' => 3, 'quantity_purchased' => 0]);

        $result = $this->controller->mark_item_fulfilled(1);

        $this->assertIsArray($result);
        $this->assertSame(
            [['quantity_needed' => 1, 'quantity_purchased' => 1]],
            $this->updatePayloads()
        );
    }

    public function test_partial_purchase_clamps_needed_to_purchased(): void {
        $this->fake->setItem(1, ['quantity_needed' => 5, 'quantity_purchased' => 2]);

        $this->controller->mark_item_fulfilled(1);

        $this->assertSame(
            [['quantity_needed' => 2, 'quantity_purchased' => 2]],
            $this->updatePayloads()
        );
    }

    public function test_missing_quantity_purchased_treated_as_zero(): void {
        $this->fake->setItem(1, ['quantity_needed' => 2]);

        $this->controller->mark_item_fulfilled(1);

        $this->assertSame(
            [['quantity_needed' => 1, 'quantity_purchased' => 1]],
            $this->updatePayloads()
        );
    }

    public function test_returns_error_when_item_not_found(): void {
        $result = $this->controller->mark_item_fulfilled(999);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
        $this->assertSame([], $this->updatePayloads());
    }

    public function test_propagates_lambda_update_error(): void {
        $this->fake->setItem(1, ['quantity_needed' => 1, 'quantity_purchased' => 0]);
        $this->fake->setUpdateError(new WP_Error('lambda_error', 'boom'));

        $result = $this->controller->mark_item_fulfilled(1);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('lambda_error', $result->get_error_code());
    }
}
