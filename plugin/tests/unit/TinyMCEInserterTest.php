<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class TinyMCEInserterTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('plugin_dir_url')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function admin(): Restart_Registry_Admin
    {
        return new Restart_Registry_Admin('restart-registry', '1.0.0');
    }

    private function mockScreen(string $base): object
    {
        $screen = new stdClass();
        $screen->base = $base;
        return $screen;
    }

    // ── mce_external_plugins ─────────────────────────────────────────────────

    public function test_plugin_registered_on_post_screen(): void
    {
        Functions\when('get_current_screen')->justReturn($this->mockScreen('post'));

        $result = $this->admin()->mce_external_plugins([]);

        $this->assertArrayHasKey('restart_item', $result);
        $this->assertStringEndsWith('restart-registry-tinymce.js', $result['restart_item']);
    }

    public function test_plugin_registered_on_page_screen(): void
    {
        Functions\when('get_current_screen')->justReturn($this->mockScreen('page'));

        $result = $this->admin()->mce_external_plugins([]);

        $this->assertArrayHasKey('restart_item', $result);
    }

    public function test_plugin_not_registered_on_other_screens(): void
    {
        foreach (['dashboard', 'edit', 'options-general', 'restart-registry-affiliates'] as $base) {
            Functions\when('get_current_screen')->justReturn($this->mockScreen($base));

            $result = $this->admin()->mce_external_plugins([]);

            $this->assertArrayNotHasKey('restart_item', $result, "Should not register on '$base' screen");
        }
    }

    public function test_plugin_not_registered_when_screen_null(): void
    {
        Functions\when('get_current_screen')->justReturn(null);

        $result = $this->admin()->mce_external_plugins([]);

        $this->assertArrayNotHasKey('restart_item', $result);
    }

    public function test_existing_plugins_are_preserved(): void
    {
        Functions\when('get_current_screen')->justReturn($this->mockScreen('post'));

        $existing = ['other_plugin' => 'other-plugin.js'];
        $result   = $this->admin()->mce_external_plugins($existing);

        $this->assertArrayHasKey('other_plugin', $result);
        $this->assertArrayHasKey('restart_item', $result);
    }

    // ── mce_buttons ──────────────────────────────────────────────────────────

    public function test_button_added_on_post_screen(): void
    {
        Functions\when('get_current_screen')->justReturn($this->mockScreen('post'));

        $result = $this->admin()->mce_buttons([]);

        $this->assertContains('restart_item', $result);
    }

    public function test_button_added_on_page_screen(): void
    {
        Functions\when('get_current_screen')->justReturn($this->mockScreen('page'));

        $result = $this->admin()->mce_buttons([]);

        $this->assertContains('restart_item', $result);
    }

    public function test_button_not_added_on_other_screens(): void
    {
        foreach (['dashboard', 'edit', 'options-general'] as $base) {
            Functions\when('get_current_screen')->justReturn($this->mockScreen($base));

            $result = $this->admin()->mce_buttons([]);

            $this->assertNotContains('restart_item', $result, "Should not add button on '$base' screen");
        }
    }

    public function test_button_not_added_when_screen_null(): void
    {
        Functions\when('get_current_screen')->justReturn(null);

        $result = $this->admin()->mce_buttons([]);

        $this->assertNotContains('restart_item', $result);
    }

    public function test_existing_buttons_are_preserved(): void
    {
        Functions\when('get_current_screen')->justReturn($this->mockScreen('post'));

        $existing = ['bold', 'italic', 'underline'];
        $result   = $this->admin()->mce_buttons($existing);

        $this->assertContains('bold', $result);
        $this->assertContains('italic', $result);
        $this->assertContains('restart_item', $result);
    }
}
