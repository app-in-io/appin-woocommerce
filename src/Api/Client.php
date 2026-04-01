<?php

declare(strict_types=1);

namespace AppIn\WooCommerce\Api;

final class Client
{
    private string $apiUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->apiUrl = rtrim(APPIN_API_URL, '/');
        $this->apiKey = get_option('appin_api_key', '');
    }

    /**
     * Index a product (upsert).
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    public function indexProduct(array $data): array
    {
        return $this->request('POST', '/index/products', $data);
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

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => ['error' => $response->get_error_message()],
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true) ?: [];

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $decoded,
        ];
    }
}
