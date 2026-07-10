<?php

declare(strict_types=1);

namespace AppInIo\I18n;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for multilingual language detection (WPML / Polylang).
 *
 * On a single-language store — neither plugin active — every method degrades to
 * null / [] / no-op, so the plugin behaves exactly as before (no `lang` emitted,
 * no query widening). `lang` codes are normalized to the API's ≤5-char contract
 * (`WooCommerceProductData::$lang` is `Max(5)`): `en_US` / `pt-BR` → `en` / `pt`.
 */
class LanguageResolver
{
    /**
     * Language slug of a single product/post, or null on a single-language store
     * (or an untranslated post).
     */
    public function postLanguage(int $postId): ?string
    {
        if (\function_exists('pll_get_post_language')) {
            $code = pll_get_post_language($postId, 'slug');

            return $this->normalize(\is_string($code) ? $code : null);
        }

        if ($this->wpmlActive()) {
            $details = apply_filters('wpml_post_language_details', null, $postId);

            if (\is_array($details) && ! empty($details['language_code'])) {
                return $this->normalize((string) $details['language_code']);
            }
        }

        return null;
    }

    /**
     * Language slug of the current front-end request, or null on a single-language store.
     */
    public function currentLanguage(): ?string
    {
        if (\function_exists('pll_current_language')) {
            $code = pll_current_language('slug');

            return $this->normalize(\is_string($code) ? $code : null);
        }

        if ($this->wpmlActive()) {
            $code = apply_filters('wpml_current_language', null);

            return $this->normalize(\is_string($code) ? $code : null);
        }

        return null;
    }

    /**
     * All active language slugs, or [] on a single-language store.
     *
     * @return list<string>
     */
    public function activeLanguages(): array
    {
        if (\function_exists('pll_languages_list')) {
            $list = pll_languages_list(['fields' => 'slug']);

            return $this->normalizeList(\is_array($list) ? $list : []);
        }

        if ($this->wpmlActive()) {
            $languages = apply_filters('wpml_active_languages', null);

            return $this->normalizeList(\is_array($languages) ? array_keys($languages) : []);
        }

        return [];
    }

    /**
     * Extra wc_get_products() / WP_Query args that widen a query to every language.
     * Polylang treats an empty `lang` var as "all languages"; WPML instead needs the
     * beginAllLanguages()/endAllLanguages() context switch, so it contributes no args.
     *
     * @return array<string, mixed>
     */
    public function allLanguagesQueryArgs(): array
    {
        return \function_exists('pll_current_language') ? ['lang' => ''] : [];
    }

    /**
     * Enter an "all languages" query context (WPML). No-op for Polylang / single-language.
     */
    public function beginAllLanguages(): void
    {
        if ($this->wpmlActive()) {
            do_action('wpml_switch_language', 'all');
        }
    }

    /**
     * Restore the original WPML language context after beginAllLanguages().
     */
    public function endAllLanguages(): void
    {
        if ($this->wpmlActive()) {
            do_action('wpml_switch_language', null);
        }
    }

    private function wpmlActive(): bool
    {
        return \defined('ICL_SITEPRESS_VERSION');
    }

    /**
     * Normalize a locale/slug to a lowercase, ≤5-char language code: `en_US` → `en-us`,
     * `FR` → `fr`. The region/script subtag is **kept** (only the separator is canonicalized
     * to `-`): a store may run distinct languages that share a base — pt-BR vs pt-PT, zh-cn vs
     * zh-tw — and collapsing them to `pt` / `zh` would remix exactly the results this feature
     * separates. Capped at the API `lang` field's `Max(5)` contract. Null for empty input.
     */
    private function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = strtolower(trim($code));

        if ($code === '') {
            return null;
        }

        return mb_substr(str_replace('_', '-', $code), 0, 5);
    }

    /**
     * @param  array<int|string, mixed>  $codes
     * @return list<string>
     */
    private function normalizeList(array $codes): array
    {
        $out = [];

        foreach ($codes as $code) {
            $slug = \is_string($code) ? $this->normalize($code) : null;

            if ($slug !== null) {
                $out[] = $slug;
            }
        }

        return array_values(array_unique($out));
    }
}
