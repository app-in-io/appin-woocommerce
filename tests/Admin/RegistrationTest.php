<?php

declare(strict_types=1);

namespace AppInIo\Tests\Admin;

use AppInIo\Admin\Registration;
use AppInIo\Api\Client;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Registration subclass that captures the post-handler redirect instead of exiting, so the
 * handlers can be driven to completion in a unit test.
 */
class SpyRegistration extends Registration
{
    public bool $redirected = false;

    /** @var array<string, int|string> */
    public array $redirectArgs = [];

    protected function redirectBack(array $args = []): void
    {
        $this->redirected = true;
        $this->redirectArgs = $args;
    }
}

class RegistrationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // In-memory option + transient stores so we can assert on writes.
        Functions\when('get_option')->alias(fn ($key, $default = '') => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn ($key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function ($key, $value) {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) {
            unset($this->transients[$key]);

            return true;
        });

        // Auth + request plumbing.
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(1); // step state is keyed per user
        Functions\when('home_url')->justReturn('https://shop.com');
        Functions\when('get_user_locale')->justReturn('en_US');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('is_email')->alias(static fn ($email) => str_contains((string) $email, '@'));
        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data));
        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    // Transients are keyed per user (get_current_user_id() stubbed to 1).
    private function pending(): mixed
    {
        return $this->transients['appinio_registration_pending_1'] ?? null;
    }

    private function notice(): mixed
    {
        return $this->transients['appinio_registration_notice_1'] ?? null;
    }

    public function test_request_otp_202_sets_pending_and_success_notice(): void
    {
        $_POST = ['appinio_reg_email' => 'o@shop.com', 'appinio_reg_name' => 'My Shop', 'appinio_reg_consent' => '1'];
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(202);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"status":"otp_sent"}');

        $reg = new SpyRegistration(new Client);
        $reg->handleRequestOtp();

        self::assertTrue($reg->redirected);
        self::assertSame('o@shop.com', $this->pending()['email']);
        self::assertSame('My Shop', $this->pending()['name']);
        self::assertSame('success', $this->notice()['type']);
    }

    public function test_request_otp_invalid_email_errors_without_calling_api(): void
    {
        $_POST = ['appinio_reg_email' => 'not-an-email', 'appinio_reg_name' => 'Shop', 'appinio_reg_consent' => '1'];
        // No wp_remote_request expectation → the guard must return before any API call.
        Functions\expect('wp_remote_request')->never();

        $reg = new SpyRegistration(new Client);
        $reg->handleRequestOtp();

        self::assertNull($this->pending());
        self::assertSame('error', $this->notice()['type']);
    }

    public function test_request_otp_without_consent_errors_without_calling_api(): void
    {
        // Valid email but the consent box was not ticked — the gate must stop the request
        // before any API call and before any pending state is created.
        $_POST = ['appinio_reg_email' => 'o@shop.com', 'appinio_reg_name' => 'Shop'];
        Functions\expect('wp_remote_request')->never();

        $reg = new SpyRegistration(new Client);
        $reg->handleRequestOtp();

        self::assertNull($this->pending());
        self::assertSame('error', $this->notice()['type']);
    }

    public function test_request_otp_429_advances_to_otp_step_with_cooldown(): void
    {
        $_POST = ['appinio_reg_email' => 'o@shop.com', 'appinio_reg_name' => 'Shop', 'appinio_reg_consent' => '1'];
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(429);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"retry_after":42}');

        $reg = new SpyRegistration(new Client);
        $reg->handleRequestOtp();

        // A 429 means a code was already emailed — advance to the OTP step so a user
        // holding a valid code can enter it, with the resend cooldown seeded from retry_after.
        self::assertNotNull($this->pending());
        self::assertSame('error', $this->notice()['type']);
    }

    public function test_verify_201_stores_keys_and_clears_pending(): void
    {
        $this->transients['appinio_registration_pending_1'] = ['email' => 'o@shop.com', 'name' => 'Shop', 'resend_at' => 0];
        $_POST = ['appinio_reg_code' => '12 34 56'];
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(201);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"api_key":"sk_live_x","public_key":"pk_live_y"}');

        $reg = new SpyRegistration(new Client);
        $reg->handleVerify();

        self::assertSame('sk_live_x', $this->options['appinio_api_key']);
        self::assertSame('pk_live_y', $this->options['appinio_public_key']);
        self::assertNull($this->pending());
        // Success is signalled via a redirect query arg (the card no longer renders once
        // the key is set, so a flash notice would never show).
        self::assertSame(['appinio_connected' => 1], $reg->redirectArgs);
    }

    public function test_verify_201_without_api_key_is_treated_as_failure(): void
    {
        $this->transients['appinio_registration_pending_1'] = ['email' => 'o@shop.com', 'name' => 'Shop', 'resend_at' => 0];
        $_POST = ['appinio_reg_code' => '123456'];
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(201);
        // 201 but no api_key in the body — must not save an empty key or claim success.
        Functions\when('wp_remote_retrieve_body')->justReturn('{"public_key":"pk_live_y"}');

        $reg = new SpyRegistration(new Client);
        $reg->handleVerify();

        self::assertArrayNotHasKey('appinio_api_key', $this->options);
        self::assertNotNull($this->pending()); // preserved for retry
        self::assertSame('error', $this->notice()['type']);
        self::assertSame([], $reg->redirectArgs); // not a success redirect
    }

    public function test_verify_transient_server_error_does_not_blame_the_code(): void
    {
        $this->transients['appinio_registration_pending_1'] = ['email' => 'o@shop.com', 'name' => 'Shop', 'resend_at' => 0];
        $_POST = ['appinio_reg_code' => '123456'];
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $reg = new SpyRegistration(new Client);
        $reg->handleVerify();

        // A correct code must survive a transient 5xx — pending kept, no "invalid code".
        self::assertArrayNotHasKey('appinio_api_key', $this->options);
        self::assertNotNull($this->pending());
        self::assertSame('error', $this->notice()['type']);
    }

    public function test_verify_422_keeps_pending_and_errors(): void
    {
        $this->transients['appinio_registration_pending_1'] = ['email' => 'o@shop.com', 'name' => 'Shop', 'resend_at' => 0];
        $_POST = ['appinio_reg_code' => '000000'];
        Functions\when('wp_remote_request')->justReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(422);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $reg = new SpyRegistration(new Client);
        $reg->handleVerify();

        self::assertArrayNotHasKey('appinio_api_key', $this->options);
        self::assertNotNull($this->pending()); // preserved so the user can retry/resend
        self::assertSame('error', $this->notice()['type']);
    }

    public function test_verify_without_pending_reports_expired_session(): void
    {
        $_POST = ['appinio_reg_code' => '123456'];
        Functions\expect('wp_remote_request')->never();

        $reg = new SpyRegistration(new Client);
        $reg->handleVerify();

        self::assertTrue($reg->redirected);
        self::assertSame('error', $this->notice()['type']);
        self::assertArrayNotHasKey('appinio_api_key', $this->options);
    }

    public function test_reset_clears_pending(): void
    {
        $this->transients['appinio_registration_pending_1'] = ['email' => 'o@shop.com', 'name' => 'Shop', 'resend_at' => 0];

        $reg = new SpyRegistration(new Client);
        $reg->handleReset();

        self::assertTrue($reg->redirected);
        self::assertNull($this->pending());
    }

    public function test_render_card_email_step_when_no_pending(): void
    {
        $this->stubRenderHelpers();
        Functions\when('wp_get_current_user')->justReturn((object) ['user_email' => 'admin@shop.com']);
        Functions\when('get_bloginfo')->justReturn('My Shop');

        ob_start();
        (new Registration)->renderCard();
        $output = ob_get_clean();

        self::assertStringContainsString('name="appinio_reg_email"', $output);
        self::assertStringContainsString('name="appinio_reg_name"', $output);
        self::assertStringContainsString('value="admin@shop.com"', $output);
        self::assertStringNotContainsString('one-time-code', $output);
        // Required legal-consent gate with links to the policy documents. Assert the checkbox
        // specifically (the email input is also `required`, so a bare 'required' would be vacuous).
        self::assertStringContainsString('name="appinio_reg_consent" value="1" required', $output);
        self::assertStringContainsString('app-in.io/terms', $output);
        self::assertStringContainsString('app-in.io/dpa', $output);
    }

    public function test_render_card_otp_step_when_pending(): void
    {
        $this->transients['appinio_registration_pending_1'] = ['email' => 'o@shop.com', 'name' => 'Shop', 'resend_at' => 0];
        $this->stubRenderHelpers();

        ob_start();
        (new Registration)->renderCard();
        $output = ob_get_clean();

        self::assertStringContainsString('autocomplete="one-time-code"', $output);
        self::assertStringContainsString('inputmode="numeric"', $output);
        self::assertStringContainsString('o@shop.com', $output);
        // The "start over" affordance is present so email/name can be edited again.
        self::assertStringContainsString('appinio_register_reset', $output);
        // The resend form must carry the consent forward, else a resend hits the consent gate
        // in handleRequestOtp() and dead-ends. (On the OTP step this is the only consent input.)
        self::assertStringContainsString('type="hidden" name="appinio_reg_consent" value="1"', $output);
    }

    private function stubRenderHelpers(): void
    {
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->returnArg();
        Functions\when('wp_kses')->returnArg();
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('wp_print_inline_script_tag')->justReturn(null);
        Functions\when('_n')->alias(static fn ($single, $plural, $number) => (int) $number === 1 ? $single : $plural);
        Functions\when('submit_button')->alias(static function ($text = '') {
            echo '<button type="submit">' . $text . '</button>';
        });
    }
}
