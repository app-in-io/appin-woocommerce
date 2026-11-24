<?php

declare(strict_types=1);

namespace Appinio\Tests;

use Appinio\Frontend\SearchButton;
use PHPUnit\Framework\TestCase;

/**
 * The two halves of the translation integration are declared in different languages:
 * SearchButton::TRANSLATABLE_OPTIONS drives Polylang (register + pll__), and
 * wpml-config.xml drives WPML, which filters get_option() itself and needs no PHP. An
 * option added to one and not the other translates on one plugin and silently ships the
 * admin's language on the other. Same guard as in appin-chat.
 */
final class TranslatableOptionsTest extends TestCase
{
    public function test_wpml_config_declares_exactly_the_polylang_translatable_options(): void
    {
        $polylang = SearchButton::TRANSLATABLE_OPTIONS;
        $wpml = $this->wpmlAdminTextKeys();

        sort($polylang);
        sort($wpml);

        self::assertSame(
            $polylang,
            $wpml,
            'TRANSLATABLE_OPTIONS and wpml-config.xml <admin-texts> must list the same options.'
        );
    }

    /** @return list<string> */
    private function wpmlAdminTextKeys(): array
    {
        $path = \dirname(__DIR__) . '/wpml-config.xml';
        self::assertFileExists($path);

        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, 'wpml-config.xml is not valid XML.');

        $keys = [];
        foreach ($xml->{'admin-texts'}->key as $key) {
            $keys[] = (string) $key['name'];
        }

        return $keys;
    }
}
