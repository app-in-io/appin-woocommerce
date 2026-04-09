# WordPress Conventions

> Applies to: `src/**/*.php`

This is a WordPress plugin — use WordPress APIs, not generic PHP libraries.

## HTTP

- Use `wp_remote_request` / `wp_remote_get` / `wp_remote_post` for HTTP calls (not cURL, not Guzzle)
- Parse responses with `wp_remote_retrieve_response_code()` and `wp_remote_retrieve_body()`

## Data Storage

- Use `get_option` / `update_option` / `delete_option` for plugin settings
- Never write to the filesystem for configuration

## Output Escaping

- All output must be escaped: `esc_html()`, `esc_attr()`, `esc_url()`
- Translatable strings: `esc_html__()`, `esc_attr__()`

## Security

- Nonces required for all form submissions (`wp_nonce_field`, `wp_verify_nonce`)
- Capability checks before admin actions (`current_user_can`)
- Sanitize all input: `sanitize_text_field()`, `absint()`

## Assets

- Use `wp_enqueue_script` / `wp_enqueue_style` for frontend assets
- Add `type="module"` via `script_loader_tag` filter for ES module scripts

## Background Work

- Use **Action Scheduler** (bundled with WooCommerce) for deferred/background tasks
- Never use `wp_cron` for time-sensitive operations