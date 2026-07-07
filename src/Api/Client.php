<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Api;

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
        $this->apiUrl = rtrim(APPIN_API_URL, '/');
        $this->apiKey = get_option('appin_api_key', '');
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
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function request(string $method, string $endpoint, array $body): array
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
            'timeout' => 30,
        ];

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'body' => ['error' => $response->get_error_message()],
                ];
            }

            $status = (int) wp_remote_retrieve_response_code($response);

            // Retry transient failures: 429 (rate limit) and any 5xx (server-side).
            if ($status !== 429 && $status < 500) {
                break;
            }

            // No point blocking after the final attempt — the loop is about to exit.
            if ($attempt < self::MAX_RETRIES) {
                $retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after') ?: $attempt * 2;
                sleep(min($retryAfter, 30));
            }
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
