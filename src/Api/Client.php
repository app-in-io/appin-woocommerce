<?php

declare(strict_types=1);

namespace AppInIo\Api;

if (! defined('ABSPATH')) {
    exit;
}

final class Client
{
    private const MAX_RETRIES = 3;

    private string $apiUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->apiUrl = rtrim(APPINIO_API_URL, '/');
        $this->apiKey = get_option('appinio_api_key', '');
    }

    /**
     * Index a product (upsert). Returns 202 when queued.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    public function indexProduct(array $data): array
    {
        return $this->request('POST', '/index/products', $data);
    }

    /**
     * Index multiple products in one request (max 20). Returns 202 when queued.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    public function indexProductBatch(array $items): array
    {
        return $this->request('POST', '/index/products/batch', ['items' => $items]);
    }

    /**
     * Delete a product by ID.
     *
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    public function deleteProduct(string $id): array
    {
        return $this->request('DELETE', '/index/products', ['id' => $id]);
    }

    /**
     * Search products by query and return the matching product IDs in relevance
     * order. Used by the results-page takeover — runs on page render, so it uses a
     * short timeout and no retries: a slow/failed call must fall back to native
     * search quickly rather than block the page.
     *
     * Returns null when the request fails (so the caller can leave the query
     * untouched and let native search — including its own product matching — run).
     * Returns a list (possibly empty) on success: an empty list is a genuine
     * "no products matched", distinct from a failure.
     *
     * @return list<int>|null
     */
    public function searchProducts(string $query, int $limit = 100, ?string $lang = null, ?int $categoryId = null): ?array
    {
        $body = ['query' => $query, 'limit' => $limit];

        if ($lang !== null) {
            $body['lang'] = $lang;
        }

        if ($categoryId !== null) {
            $body['category_id'] = $categoryId;
        }

        $result = $this->request('POST', '/search/products', $body, 5, 1);

        if (! $result['ok']) {
            return null;
        }

        $ids = [];
        foreach ($result['body']['results'] ?? [] as $item) {
            if (isset($item['id']) && (int) $item['id'] > 0) {
                $ids[] = (int) $item['id'];
            }
        }

        return $ids;
    }

    /**
     * Whether a failed request's status is worth retrying: network errors (status 0),
     * rate limits (429) and server-side errors (5xx). A 4xx is a permanent client error.
     */
    public static function isRetryable(int $status): bool
    {
        return $status === 0 || $status === 429 || $status >= 500;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function request(string $method, string $endpoint, array $body, int $timeout = 30, int $maxRetries = self::MAX_RETRIES): array
    {
        $url = $this->apiUrl . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-API-Key' => $this->apiKey,
                'X-Platform' => 'woocommerce',
            ],
            'body' => wp_json_encode($body),
            'timeout' => $timeout,
        ];

        // do/while guarantees at least one attempt regardless of $maxRetries.
        // $status stays 0 until an HTTP response arrives (i.e. on network errors).
        $attempt = 0;
        $status = 0;

        do {
            $attempt++;
            $response = wp_remote_request($url, $args);
            $networkError = is_wp_error($response);

            if (! $networkError) {
                $status = (int) wp_remote_retrieve_response_code($response);

                // Stop on success or a permanent (non-429, non-5xx) response.
                if ($status !== 429 && $status < 500) {
                    break;
                }
            }

            // Transient failure — network error, 429 (rate limit) or 5xx — back off and
            // retry while attempts remain. No point blocking after the final attempt.
            if ($attempt < $maxRetries) {
                $retryAfter = $networkError
                    ? $attempt * 2
                    : ((int) wp_remote_retrieve_header($response, 'retry-after') ?: $attempt * 2);
                sleep(min($retryAfter, 30));
            }
        } while ($attempt < $maxRetries);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => ['error' => $response->get_error_message()],
            ];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(wp_remote_retrieve_body($response), true) ?: [];

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $decoded,
        ];
    }
}
