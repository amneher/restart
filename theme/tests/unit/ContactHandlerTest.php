<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit;

use Brain\Monkey\Functions;
use RuntimeException;
use TheRestart\Tests\ThemeTestCase;

final class ContactHandlerTest extends ThemeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_email')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('is_email')->alias(fn($v) => is_string($v) && str_contains($v, '@'));
        Functions\when('__')->returnArg(1);
        Functions\when('get_option')->justReturn('admin@example.com');

        // Capture wp_send_json_* via exceptions tagged with success/payload/status.
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            throw new ContactHandlerResult(true, $data, 200);
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null, $status = 200) {
            throw new ContactHandlerResult(false, $data, $status);
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    private function postValidPayload(): void
    {
        $_POST = [
            '_nonce'  => 'good-nonce',
            'name'    => 'Alex',
            'email'   => 'alex@example.com',
            'subject' => '',
            'message' => 'Hello there.',
            'website' => '',
        ];
        Functions\when('wp_verify_nonce')->justReturn(1);
    }

    public function test_rejects_invalid_nonce_with_403(): void
    {
        $_POST = ['_nonce' => 'bad'];
        Functions\when('wp_verify_nonce')->justReturn(false);

        try {
            restart_handle_contact_submit();
            $this->fail('Expected ContactHandlerResult to be thrown.');
        } catch (ContactHandlerResult $r) {
            $this->assertFalse($r->success);
            $this->assertSame(403, $r->status);
        }
    }

    public function test_returns_success_silently_when_honeypot_filled(): void
    {
        $_POST = [
            '_nonce'  => 'good',
            'name'    => 'Bot',
            'email'   => 'bot@example.com',
            'message' => 'spam',
            'website' => 'http://spam.example',
        ];
        Functions\when('wp_verify_nonce')->justReturn(1);
        $mailCalled = false;
        Functions\when('wp_mail')->alias(function () use (&$mailCalled) {
            $mailCalled = true;
            return true;
        });

        try {
            restart_handle_contact_submit();
            $this->fail('Expected ContactHandlerResult to be thrown.');
        } catch (ContactHandlerResult $r) {
            $this->assertTrue($r->success);
        }
        $this->assertFalse($mailCalled, 'wp_mail should not be called when honeypot is filled.');
    }

    public function test_returns_field_errors_when_inputs_missing(): void
    {
        $_POST = [
            '_nonce'  => 'good',
            'name'    => '',
            'email'   => 'not-an-email',
            'subject' => '',
            'message' => '',
            'website' => '',
        ];
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_mail')->justReturn(true);

        try {
            restart_handle_contact_submit();
            $this->fail('Expected ContactHandlerResult to be thrown.');
        } catch (ContactHandlerResult $r) {
            $this->assertFalse($r->success);
            $this->assertSame(400, $r->status);
            $this->assertArrayHasKey('errors', $r->data);
            $this->assertArrayHasKey('name', $r->data['errors']);
            $this->assertArrayHasKey('email', $r->data['errors']);
            $this->assertArrayHasKey('message', $r->data['errors']);
        }
    }

    public function test_sends_mail_to_admin_with_reply_to_user(): void
    {
        $this->postValidPayload();
        $captured = [];
        Functions\when('wp_mail')->alias(function ($to, $subject, $body, $headers) use (&$captured) {
            $captured = compact('to', 'subject', 'body', 'headers');
            return true;
        });

        try {
            restart_handle_contact_submit();
        } catch (ContactHandlerResult $r) {
            $this->assertTrue($r->success);
        }

        $this->assertSame('admin@example.com', $captured['to']);
        $this->assertStringContainsString('Alex', $captured['subject']);
        $this->assertStringContainsString('Hello there.', $captured['body']);
        $this->assertContains('Reply-To: Alex <alex@example.com>', $captured['headers']);
    }

    public function test_uses_user_subject_when_provided(): void
    {
        $this->postValidPayload();
        $_POST['subject'] = 'Quick question';
        $captured = [];
        Functions\when('wp_mail')->alias(function ($to, $subject) use (&$captured) {
            $captured['subject'] = $subject;
            return true;
        });

        try {
            restart_handle_contact_submit();
        } catch (ContactHandlerResult $r) {
            $this->assertTrue($r->success);
        }
        $this->assertStringContainsString('Quick question', $captured['subject']);
    }

    public function test_returns_500_when_wp_mail_fails(): void
    {
        $this->postValidPayload();
        Functions\when('wp_mail')->justReturn(false);

        try {
            restart_handle_contact_submit();
            $this->fail('Expected ContactHandlerResult to be thrown.');
        } catch (ContactHandlerResult $r) {
            $this->assertFalse($r->success);
            $this->assertSame(500, $r->status);
        }
    }
}

final class ContactHandlerResult extends RuntimeException
{
    public function __construct(public bool $success, public mixed $data, public int $status)
    {
        parent::__construct('contact-handler-result');
    }
}
