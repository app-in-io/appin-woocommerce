<?php

declare(strict_types=1);

namespace AppInIo\Admin;

use AppInIo\Api\Client;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * In-plugin self-serve registration (GTM Idea 5): create the AppIn account + Site + API
 * keys straight from WP-admin. Two keyless API steps — request an OTP by email, then verify
 * it — after which the minted secret + public keys are saved to options and the plugin is
 * connected. No copy-pasting keys from the dashboard.
 *
 * Server-rendered steps over admin-post (WP-idiomatic, unit-testable). Step state lives in a
 * transient so it survives a page reload; a flash transient carries notices across redirects.
 */
class Registration
{
    /** Step state: {email, name, resend_at}. TTL matches the OTP lifetime (10 min). */
    private const PENDING_TRANSIENT = 'appinio_registration_pending';

    /** One-shot flash notice across the post→redirect boundary: {type, message}. */
    private const NOTICE_TRANSIENT = 'appinio_registration_notice';

    private const OTP_TTL = 600;

    /** Fallback resend cooldown when the API doesn't send a retry_after (matches server default). */
    private const DEFAULT_COOLDOWN = 60;

    public function __construct(private ?Client $client = null) {}

    public function register(): void
    {
        add_action('admin_post_appinio_register_request_otp', [$this, 'handleRequestOtp']);
        add_action('admin_post_appinio_register_verify', [$this, 'handleVerify']);
        add_action('admin_post_appinio_register_reset', [$this, 'handleReset']);
    }

    public function handleRequestOtp(): void
    {
        $this->authorize('appinio_register_request_otp');

        $email = sanitize_email(wp_unslash((string) ($_POST['appinio_reg_email'] ?? '')));
        $name = sanitize_text_field(wp_unslash((string) ($_POST['appinio_reg_name'] ?? '')));

        if ($email === '' || ! is_email($email)) {
            $this->flash('error', __('Please enter a valid email address.', 'appinio-search'));
            $this->redirectBack();

            return;
        }

        $result = $this->client()->requestOtp($email, home_url(), $name, get_user_locale());
        $this->applyOtpResult($result, $email, $name);
        $this->redirectBack();
    }

    public function handleVerify(): void
    {
        $this->authorize('appinio_register_verify');

        $pending = $this->pending();

        if ($pending === null) {
            $this->flash('error', __('Your registration session expired. Please start again.', 'appinio-search'));
            $this->redirectBack();

            return;
        }

        $code = preg_replace('/\D/', '', (string) ($_POST['appinio_reg_code'] ?? ''));

        if ($code === '') {
            $this->flash('error', __('Enter the 6-digit code from the email.', 'appinio-search'));
            $this->redirectBack();

            return;
        }

        $result = $this->client()->verifyRegistration((string) $pending['email'], home_url(), $code);
        $apiKey = (string) ($result['body']['api_key'] ?? '');

        if ($result['status'] === 201 && $apiKey !== '') {
            update_option('appinio_api_key', $apiKey);
            update_option('appinio_public_key', (string) ($result['body']['public_key'] ?? ''));
            $this->clearPending();
            // Signal success via a query arg: once the key is set the registration card no
            // longer renders, so a flash notice would never be shown — the settings page
            // reads this arg and prints the confirmation itself.
            $this->redirectBack(['appinio_connected' => 1]);

            return;
        }

        // Keep the pending state so the user can re-enter the code or resend.
        $this->flash('error', match (true) {
            $result['status'] === 409 => __('A store for this website is already registered. Paste its API key below instead.', 'appinio-search'),
            $result['status'] === 422 => __('That code is invalid or expired. Check the email or resend a new code.', 'appinio-search'),
            // 201-without-key, network (0) or 5xx — a valid code should not be blamed.
            default => __('Could not reach AppIn to verify the code. Please try again in a moment.', 'appinio-search'),
        });
        $this->redirectBack();
    }

    public function handleReset(): void
    {
        $this->authorize('appinio_register_reset');
        $this->clearPending();
        $this->redirectBack();
    }

    /**
     * Render the onboarding card: the email step, or the OTP step once a code has been sent.
     */
    public function renderCard(): void
    {
        $notice = $this->takeNotice();
        $pending = $this->pending();

        echo '<div class="card" style="max-width:600px;margin-bottom:20px;padding:12px 20px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Connect your store', 'appinio-search') . '</h2>';

        if ($notice !== null) {
            printf(
                '<div class="notice notice-%s inline" style="margin:0 0 12px;"><p>%s</p></div>',
                esc_attr($notice['type'] === 'success' ? 'success' : 'error'),
                esc_html((string) $notice['message'])
            );
        }

        if ($pending === null) {
            $this->renderEmailStep();
        } else {
            $this->renderOtpStep($pending);
        }

        echo '</div>';
    }

    /**
     * Map the request-otp response to the next step + a user-facing notice.
     *
     * @param  array{ok: bool, status: int, body: array<string, mixed>}  $result
     */
    private function applyOtpResult(array $result, string $email, string $name): void
    {
        switch ($result['status']) {
            case 202:
                $this->setPending($email, $name, time() + self::DEFAULT_COOLDOWN);
                $this->flash('success', __('We emailed you a 6-digit code. Enter it below to finish.', 'appinio-search'));

                return;

            case 429:
                $retryAfter = max(1, (int) ($result['body']['retry_after'] ?? self::DEFAULT_COOLDOWN));
                // A per-domain cooldown means a code was already emailed (possibly in an
                // earlier session whose local state is gone), so advance to the OTP step
                // regardless — otherwise a user holding a valid code has nowhere to enter it.
                $this->setPending($email, $name, time() + $retryAfter);
                $this->flash('error', sprintf(
                    /* translators: %d: number of seconds */
                    __('A code was already sent. You can request a new one in %d seconds.', 'appinio-search'),
                    $retryAfter
                ));

                return;

            case 409:
                $this->flash('error', __('A store for this website is already registered. Paste its API key below instead.', 'appinio-search'));

                return;

            case 422:
                $this->flash('error', __('That email or store address looks invalid. Please check and try again.', 'appinio-search'));

                return;

            default:
                $this->flash('error', __('Could not reach AppIn. Please try again in a moment.', 'appinio-search'));
        }
    }

    private function renderEmailStep(): void
    {
        $user = wp_get_current_user();
        $email = $user && $user->user_email ? $user->user_email : (string) get_option('admin_email', '');
        $name = (string) get_bloginfo('name');

        printf(
            '<p>%s</p>',
            esc_html__('Create your free AppIn account right here — we\'ll email you a 6-digit code to confirm.', 'appinio-search')
        );

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('appinio_register_request_otp');
        echo '<input type="hidden" name="action" value="appinio_register_request_otp" />';

        echo '<p><label for="appinio_reg_email"><strong>' . esc_html__('Email', 'appinio-search') . '</strong></label><br />';
        printf(
            '<input type="email" id="appinio_reg_email" name="appinio_reg_email" value="%s" class="regular-text" required /></p>',
            esc_attr($email)
        );

        echo '<p><label for="appinio_reg_name"><strong>' . esc_html__('Store name', 'appinio-search') . '</strong></label><br />';
        printf(
            '<input type="text" id="appinio_reg_name" name="appinio_reg_name" value="%s" class="regular-text" /></p>',
            esc_attr($name)
        );

        submit_button(__('Send code', 'appinio-search'));
        echo '</form>';

        printf(
            '<p class="description">%s</p>',
            esc_html__('Already have an API key? Paste it in the settings form below instead.', 'appinio-search')
        );
    }

    /**
     * @param  array{email?: mixed, name?: mixed, resend_at?: mixed}  $pending
     */
    private function renderOtpStep(array $pending): void
    {
        $email = (string) ($pending['email'] ?? '');
        $name = (string) ($pending['name'] ?? '');
        $remaining = max(0, (int) ($pending['resend_at'] ?? 0) - time());

        printf(
            '<p>%s</p>',
            wp_kses(
                sprintf(
                    /* translators: %s: email address */
                    __('We sent a 6-digit code to <strong>%s</strong>. Enter it below to finish.', 'appinio-search'),
                    esc_html($email)
                ),
                ['strong' => []]
            )
        );

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('appinio_register_verify');
        echo '<input type="hidden" name="action" value="appinio_register_verify" />';
        echo '<p><label for="appinio_reg_code"><strong>' . esc_html__('Verification code', 'appinio-search') . '</strong></label><br />';
        echo '<input type="text" id="appinio_reg_code" name="appinio_reg_code" class="regular-text" '
            . 'inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" required /></p>';
        submit_button(__('Verify & connect', 'appinio-search'));
        echo '</form>';

        echo '<p>';
        $this->renderResendForm($email, $name, $remaining > 0);
        echo ' &middot; ';
        $this->renderResetForm();
        echo '</p>';

        if ($remaining > 0) {
            $this->renderResendCountdown($remaining);
        }
    }

    private function renderResendForm(string $email, string $name, bool $disabled): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
        wp_nonce_field('appinio_register_request_otp');
        echo '<input type="hidden" name="action" value="appinio_register_request_otp" />';
        printf('<input type="hidden" name="appinio_reg_email" value="%s" />', esc_attr($email));
        printf('<input type="hidden" name="appinio_reg_name" value="%s" />', esc_attr($name));
        printf(
            '<button type="submit" class="button-link appinio-resend"%s>%s</button>',
            $disabled ? ' disabled' : '',
            esc_html__('Resend code', 'appinio-search')
        );
        echo '</form>';
    }

    private function renderResetForm(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
        wp_nonce_field('appinio_register_reset');
        echo '<input type="hidden" name="action" value="appinio_register_reset" />';
        printf(
            '<button type="submit" class="button-link">%s</button>',
            esc_html__('Change email / start over', 'appinio-search')
        );
        echo '</form>';
    }

    /**
     * Progressive enhancement: count the resend cooldown down and re-enable the button when
     * it elapses. The server-computed `$seconds` (from `resend_at`) survives page reloads;
     * the API enforces the cooldown regardless of this script.
     */
    private function renderResendCountdown(int $seconds): void
    {
        // Phrasing keeps the number last with no count-dependent word, so the JS countdown
        // (which only swaps the digits) never produces "1 seconds".
        printf(
            '<p class="description appinio-resend-hint">%s</p>',
            esc_html(sprintf(
                /* translators: %d: number of seconds remaining before the code can be resent */
                __('Seconds until you can resend: %d', 'appinio-search'),
                $seconds
            ))
        );

        wp_print_inline_script_tag(sprintf(
            <<<'JS'
                (function () {
                    var left = %d;
                    var btn = document.querySelector('.appinio-resend');
                    var hint = document.querySelector('.appinio-resend-hint');
                    if (!btn) { return; }
                    var t = setInterval(function () {
                        left -= 1;
                        if (left <= 0) {
                            clearInterval(t);
                            btn.disabled = false;
                            if (hint) { hint.style.display = 'none'; }
                        } else if (hint) {
                            hint.textContent = hint.textContent.replace(/\d+/, left);
                        }
                    }, 1000);
                })();
                JS,
            $seconds
        ));
    }

    private function client(): Client
    {
        return $this->client ??= new Client;
    }

    private function authorize(string $action): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'appinio-search'));
        }

        check_admin_referer($action);
    }

    /**
     * @return array{email: mixed, name: mixed, resend_at: mixed}|null
     */
    private function pending(): ?array
    {
        $data = get_transient($this->pendingKey());

        return \is_array($data) ? $data : null;
    }

    private function setPending(string $email, string $name, int $resendAt): void
    {
        set_transient($this->pendingKey(), [
            'email' => $email,
            'name' => $name,
            'resend_at' => $resendAt,
        ], self::OTP_TTL);
    }

    private function clearPending(): void
    {
        delete_transient($this->pendingKey());
    }

    private function flash(string $type, string $message): void
    {
        set_transient($this->noticeKey(), ['type' => $type, 'message' => $message], self::OTP_TTL);
    }

    /**
     * @return array{type: mixed, message: mixed}|null
     */
    private function takeNotice(): ?array
    {
        $notice = get_transient($this->noticeKey());

        if (\is_array($notice)) {
            delete_transient($this->noticeKey());

            return $notice;
        }

        return null;
    }

    /**
     * Step state and notices are scoped to the current WP user, so two admins of the same
     * store registering at once don't clobber each other's OTP session or see each other's
     * notices. The API's per-domain dedup remains the backstop against a double registration.
     */
    private function pendingKey(): string
    {
        return self::PENDING_TRANSIENT . '_' . get_current_user_id();
    }

    private function noticeKey(): string
    {
        return self::NOTICE_TRANSIENT . '_' . get_current_user_id();
    }

    /**
     * Redirect back to the settings page after handling a POST. Protected so tests can
     * override it to capture the redirect instead of exiting the process.
     *
     * @param  array<string, int|string>  $args  extra query args (e.g. a success flag the
     *                                            settings page reads to show a confirmation)
     */
    protected function redirectBack(array $args = []): void
    {
        $url = admin_url('admin.php?page=appinio-search');

        if ($args !== []) {
            $url = add_query_arg($args, $url);
        }

        wp_safe_redirect($url);
        exit;
    }
}
