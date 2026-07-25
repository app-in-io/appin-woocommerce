<?php

declare(strict_types=1);

namespace Appinio\Api;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Announces to the Appinio API that the store just connected, whenever the merchant
 * saves (or first sets) their API key. This is what lets a dashboard-first account —
 * one created on the Appinio website and sitting on the Free plan — claim the founding
 * trial after wiring up the plugin.
 *
 * Hooked on WordPress's own option-write actions, which fire *after* the value is
 * persisted, so a freshly constructed {@see Client} reads the new key. `update_option_*`
 * only fires on an actual change, so re-saving the same key does not re-announce.
 */
final class ConnectionSignal
{
    public function register(): void
    {
        // add_option_{$option}: ($option, $value) — first time the key is set.
        add_action('add_option_appinio_api_key', [$this, 'onAdd'], 10, 2);
        // update_option_{$option}: ($old_value, $value) — key changed.
        add_action('update_option_appinio_api_key', [$this, 'onUpdate'], 10, 2);
    }

    /**
     * @param  mixed  $value
     */
    public function onAdd(string $option, $value): void
    {
        $this->announce((string) $value);
    }

    /**
     * @param  mixed  $old
     * @param  mixed  $value
     */
    public function onUpdate($old, $value): void
    {
        $this->announce((string) $value);
    }

    private function announce(string $key): void
    {
        if ($key === '') {
            return;
        }

        // Best-effort: a connection ping must never break saving the key.
        try {
            (new Client)->pluginConnected(home_url());
        } catch (\Throwable) {
            // Swallow — the claim is control-plane, not critical to the save.
        }
    }
}
