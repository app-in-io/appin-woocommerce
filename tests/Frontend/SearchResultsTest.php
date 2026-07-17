<?php

declare(strict_types=1);

namespace Appinio\Tests\Frontend;

use Appinio\Api\Client;
use Appinio\Frontend\SearchResults;
use Appinio\I18n\LanguageResolver;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class SearchResultsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data));
        // Default: all context guards pass.
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('get_option')->alias(static function (string $key, mixed $default = false): mixed {
            return match ($key) {
                'appinio_results_page' => true,
                'appinio_api_key' => 'sk_test',
                default => $default,
            };
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- nullifyNativeSql ---------------------------------------------------

    public function test_nullify_native_sql_empties_clause_when_ai_used(): void
    {
        $query = Mockery::mock('WP_Query');
        $query->allows('get')->with('appinio_ai_used')->andReturn(true);

        self::assertSame('', (new SearchResults)->nullifyNativeSql(' AND (post_title LIKE ...)', $query));
    }

    public function test_nullify_native_sql_passes_through_when_not_used(): void
    {
        $query = Mockery::mock('WP_Query');
        $query->allows('get')->with('appinio_ai_used')->andReturn(false);

        self::assertSame(' AND x', (new SearchResults)->nullifyNativeSql(' AND x', $query));
    }

    // --- takeover guards (must not touch the query) -------------------------

    public function test_skips_when_not_main_query(): void
    {
        $query = Mockery::mock('WP_Query');
        $query->allows('is_main_query')->andReturn(false);
        $query->allows('is_search')->andReturn(true);
        $query->shouldNotReceive('set');

        (new SearchResults)->takeover($query);
    }

    public function test_skips_when_not_search(): void
    {
        $query = Mockery::mock('WP_Query');
        $query->allows('is_main_query')->andReturn(true);
        $query->allows('is_search')->andReturn(false);
        $query->shouldNotReceive('set');

        (new SearchResults)->takeover($query);
    }

    public function test_skips_in_admin(): void
    {
        Functions\when('is_admin')->justReturn(true);

        $query = Mockery::mock('WP_Query');
        $query->allows('is_main_query')->andReturn(true);
        $query->allows('is_search')->andReturn(true);
        $query->shouldNotReceive('set');

        (new SearchResults)->takeover($query);
    }

    public function test_skips_when_option_disabled(): void
    {
        Functions\when('get_option')->alias(static fn (string $key, mixed $default = false): mixed => $key === 'appinio_results_page' ? false : $default);

        $query = Mockery::mock('WP_Query');
        $query->allows('is_main_query')->andReturn(true);
        $query->allows('is_search')->andReturn(true);
        $query->shouldNotReceive('set');

        (new SearchResults)->takeover($query);
    }

    public function test_skips_when_no_api_key(): void
    {
        Functions\when('get_option')->alias(static fn (string $key, mixed $default = false): mixed => $key === 'appinio_results_page' ? true : ($key === 'appinio_api_key' ? '' : $default));

        $query = Mockery::mock('WP_Query');
        $query->allows('is_main_query')->andReturn(true);
        $query->allows('is_search')->andReturn(true);
        $query->shouldNotReceive('set');

        (new SearchResults)->takeover($query);
    }

    public function test_skips_when_term_empty(): void
    {
        $query = $this->passingQuery('   ');
        $query->shouldNotReceive('set');

        (new SearchResults)->takeover($query);
    }

    // --- takeover behaviour -------------------------------------------------

    public function test_merges_ai_products_and_native_others(): void
    {
        $this->stubSearchResponse('{"results":[{"id":"5"},{"id":"3"}]}');

        $query = $this->passingQuery('boots');
        $query->shouldReceive('set')->once()->with('appinio_ai_used', true);
        $query->shouldReceive('set')->once()->with('post_type', 'any');
        $query->shouldReceive('set')->once()->with('post__in', [5, 3, 99]);
        $query->shouldReceive('set')->once()->with('orderby', 'post__in');

        $this->searchResultsWithNative([99])->takeover($query);
    }

    public function test_forces_empty_result_when_nothing_matches(): void
    {
        $this->stubSearchResponse('{"results":[]}');

        $query = $this->passingQuery('zzz');
        $query->shouldReceive('set')->once()->with('appinio_ai_used', true);
        $query->shouldReceive('set')->once()->with('post_type', 'any');
        $query->shouldReceive('set')->once()->with('post__in', [0]);
        $query->shouldReceive('set')->once()->with('orderby', 'post__in');

        $this->searchResultsWithNative([])->takeover($query);
    }

    public function test_skips_on_ai_failure_for_native_fallback(): void
    {
        // 500 → searchProducts returns null → leave the query untouched entirely.
        $this->stubSearchResponse('{}', 500);

        $query = $this->passingQuery('boots');
        $query->shouldNotReceive('set');

        $this->searchResultsWithNative([99])->takeover($query);
    }

    public function test_product_category_archive_passes_category_id_and_stays_product_scoped(): void
    {
        // product_cat slug → term id resolved via get_term_by (not conditional tags).
        Functions\when('get_term_by')->justReturn((object) ['term_id' => 7]);

        Functions\expect('wp_remote_request')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (array $args): bool {
                self::assertSame(7, json_decode($args['body'], true)['category_id']);

                return true;
            }))
            ->andReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"results":[{"id":"5"}]}');

        $query = Mockery::mock('WP_Query');
        $query->allows('is_main_query')->andReturn(true);
        $query->allows('is_search')->andReturn(true);
        $query->allows('get')->with('s')->andReturn('jacket');
        $query->allows('get')->with('post_type')->andReturn('');
        $query->allows('get')->with('product_cat')->andReturn('jackets');
        $query->allows('get')->with('product_tag')->andReturn('');

        // Product-scoped: post_type is NOT widened (native product query + filters stay),
        // and native non-products are not merged.
        $query->shouldReceive('set')->with('appinio_ai_used', true)->once();
        $query->shouldReceive('set')->with('post__in', [5])->once();
        $query->shouldReceive('set')->with('orderby', 'post__in')->once();
        $query->shouldNotReceive('set')->with('post_type', Mockery::any());

        $this->searchResultsWithNative([99])->takeover($query);
    }

    public function test_current_language_is_passed_to_search(): void
    {
        // Multilingual store: the visitor's current language scopes the AI search.
        $lang = new class extends LanguageResolver
        {
            public function currentLanguage(): ?string
            {
                return 'fr';
            }
        };

        Functions\expect('wp_remote_request')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (array $args): bool {
                self::assertSame('fr', json_decode($args['body'], true)['lang']);

                return true;
            }))
            ->andReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"results":[{"id":"5"}]}');

        $query = $this->passingQuery('boots');
        $query->allows('set');

        $this->searchResultsWithNative([99], $lang)->takeover($query);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * A WP_Query mock that clears every context guard, with the given search term.
     */
    private function passingQuery(string $term): Mockery\MockInterface
    {
        $query = Mockery::mock('WP_Query');
        $query->allows('is_main_query')->andReturn(true);
        $query->allows('is_search')->andReturn(true);
        $query->allows('get')->with('s')->andReturn($term);
        // Generic (non-product) context.
        $query->allows('get')->with('post_type')->andReturn('');
        $query->allows('get')->with('product_cat')->andReturn('');
        $query->allows('get')->with('product_tag')->andReturn('');

        return $query;
    }

    private function stubSearchResponse(string $body, int $status = 200): void
    {
        Functions\expect('wp_remote_request')->once()->andReturn('RESP');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
    }

    /**
     * SearchResults with a real (HTTP-mocked) Client and a stubbed native lookup.
     *
     * @param  list<int>  $native
     */
    private function searchResultsWithNative(array $native, ?LanguageResolver $lang = null): SearchResults
    {
        return new class(new Client, $native, $lang) extends SearchResults
        {
            /** @param list<int> $native */
            public function __construct(Client $client, private array $native, ?LanguageResolver $lang)
            {
                parent::__construct($client, $lang);
            }

            protected function nativeNonProductIds(string $term): array
            {
                return $this->native;
            }
        };
    }
}
