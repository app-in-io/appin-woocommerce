<?php

declare(strict_types=1);

namespace Appinio\Tests\I18n;

use Appinio\I18n\LanguageResolver;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs in its own process: the Polylang/WPML detection is `function_exists()` /
 * `defined()` based, and Brain Monkey's function stubs (and the WPML version constant) can
 * neither be undefined nor unset once created — so without isolation a stub from one test
 * would leak into the next and flip a detection branch. Isolation keeps every case honest.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class LanguageResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- single-language store (neither plugin active) ----------------------

    public function test_post_language_null_when_no_multilingual_plugin(): void
    {
        self::assertNull((new LanguageResolver)->postLanguage(42));
    }

    public function test_current_language_null_when_no_multilingual_plugin(): void
    {
        self::assertNull((new LanguageResolver)->currentLanguage());
    }

    public function test_active_languages_empty_when_no_multilingual_plugin(): void
    {
        self::assertSame([], (new LanguageResolver)->activeLanguages());
    }

    public function test_all_languages_query_args_empty_without_polylang(): void
    {
        self::assertSame([], (new LanguageResolver)->allLanguagesQueryArgs());
    }

    // --- Polylang -----------------------------------------------------------

    public function test_post_language_from_polylang(): void
    {
        Functions\when('pll_get_post_language')->justReturn('fr');

        self::assertSame('fr', (new LanguageResolver)->postLanguage(42));
    }

    public function test_current_language_from_polylang(): void
    {
        Functions\when('pll_current_language')->justReturn('fr');

        self::assertSame('fr', (new LanguageResolver)->currentLanguage());
    }

    public function test_active_languages_from_polylang(): void
    {
        Functions\when('pll_languages_list')->justReturn(['en', 'fr', 'de']);

        self::assertSame(['en', 'fr', 'de'], (new LanguageResolver)->activeLanguages());
    }

    public function test_all_languages_query_args_empty_lang_when_polylang_active(): void
    {
        Functions\when('pll_current_language')->justReturn('fr');

        self::assertSame(['lang' => ''], (new LanguageResolver)->allLanguagesQueryArgs());
    }

    public function test_polylang_begin_end_all_languages_are_noop(): void
    {
        Functions\when('pll_current_language')->justReturn('fr');

        // Polylang widens via query args, not a language switch — no action fired.
        Actions\expectDone('wpml_switch_language')->never();

        $resolver = new LanguageResolver;
        $resolver->beginAllLanguages();
        $resolver->endAllLanguages();

        self::assertSame(['lang' => ''], $resolver->allLanguagesQueryArgs());
    }

    // --- normalization ------------------------------------------------------

    public function test_underscore_locale_is_canonicalized_to_hyphen(): void
    {
        Functions\when('pll_get_post_language')->justReturn('en_US');

        // Region preserved (en-us), only the separator canonicalized and case lowered.
        self::assertSame('en-us', (new LanguageResolver)->postLanguage(1));
    }

    public function test_region_variants_stay_distinct(): void
    {
        // pt-BR and pt-PT must NOT collapse to a single "pt" — that would remix results.
        Functions\when('pll_get_post_language')->justReturn('pt-PT');

        self::assertSame('pt-pt', (new LanguageResolver)->postLanguage(1));
    }

    public function test_plain_two_letter_slug_is_unchanged(): void
    {
        Functions\when('pll_get_post_language')->justReturn('DE');

        self::assertSame('de', (new LanguageResolver)->postLanguage(1));
    }

    public function test_empty_polylang_language_yields_null(): void
    {
        Functions\when('pll_get_post_language')->justReturn('');

        self::assertNull((new LanguageResolver)->postLanguage(1));
    }

    // --- WPML (isolated: defines ICL_SITEPRESS_VERSION) ---------------------

    public function test_post_language_from_wpml(): void
    {
        \define('ICL_SITEPRESS_VERSION', '4.6.0');
        Filters\expectApplied('wpml_post_language_details')->andReturn(['language_code' => 'de']);

        self::assertSame('de', (new LanguageResolver)->postLanguage(42));
    }

    public function test_current_language_from_wpml(): void
    {
        \define('ICL_SITEPRESS_VERSION', '4.6.0');
        Filters\expectApplied('wpml_current_language')->andReturn('de');

        self::assertSame('de', (new LanguageResolver)->currentLanguage());
    }

    public function test_active_languages_from_wpml(): void
    {
        \define('ICL_SITEPRESS_VERSION', '4.6.0');
        Filters\expectApplied('wpml_active_languages')->andReturn([
            'en' => ['code' => 'en'],
            'de' => ['code' => 'de'],
        ]);

        self::assertSame(['en', 'de'], (new LanguageResolver)->activeLanguages());
    }

    public function test_wpml_begin_end_all_languages_switch_context(): void
    {
        \define('ICL_SITEPRESS_VERSION', '4.6.0');

        Actions\expectDone('wpml_switch_language')->twice();

        $resolver = new LanguageResolver;
        $resolver->beginAllLanguages();
        $resolver->endAllLanguages();

        // WPML widens via context switch, not query args.
        self::assertSame([], $resolver->allLanguagesQueryArgs());
    }
}
