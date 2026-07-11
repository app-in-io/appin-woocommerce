<?php

declare(strict_types=1);

namespace AppInIo\Sync;

use AppInIo\Api\Client;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thin, cached view over the search backend's real index counts (`GET /index/counts`).
 *
 * Where {@see IndexState} counts what the plugin has *queued* for indexing (a local
 * per-product flag), this counts what actually *landed* in the search index (Qdrant),
 * plus how many index jobs are still in flight and the last index-tracking status. The
 * two together let the dashboard show queued-vs-indexed drift and live indexing progress.
 *
 * A 30s transient caps real API traffic to ≤1 call per 30s no matter how often a poller
 * asks — so a 15s dashboard tick never hammers the backend. Even a failed fetch is cached
 * (as an "unavailable" sentinel) so an API outage shows "—" rather than a misleading 0 and
 * still can't trigger a fetch storm.
 */
final class RemoteIndexState
{
    private const CACHE_KEY = 'appinio_remote_counts';

    private const CACHE_TTL = 30;

    /** @var array<string, mixed>|null Memoized snapshot for the current request. */
    private ?array $snapshot = null;

    private bool $fetched = false;

    public function __construct(
        private Client $client = new Client,
    ) {}

    /**
     * Real number of products in the search index, or null when the backend is unavailable.
     */
    public function products(): ?int
    {
        $snapshot = $this->fetch();

        if ($snapshot === null || ! isset($snapshot['products'])) {
            return null;
        }

        return (int) $snapshot['products'];
    }

    /**
     * Index jobs still in flight for this store (0 = queue drained), or null when unavailable.
     */
    public function pending(): ?int
    {
        $snapshot = $this->fetch();

        if ($snapshot === null || ! isset($snapshot['pending'])) {
            return null;
        }

        return (int) $snapshot['pending'];
    }

    /**
     * Last index-tracking status (completed|running|failed|null), or null when unavailable.
     */
    public function status(): ?string
    {
        $snapshot = $this->fetch();

        if ($snapshot === null || ! isset($snapshot['status'])) {
            return null;
        }

        return (string) $snapshot['status'];
    }

    /**
     * Timestamp of the last successful index, or null when unavailable / never indexed.
     */
    public function lastIndexedAt(): ?string
    {
        $snapshot = $this->fetch();

        if ($snapshot === null || ! isset($snapshot['last_indexed_at'])) {
            return null;
        }

        return (string) $snapshot['last_indexed_at'];
    }

    /**
     * Whether the last fetch reached the backend. False → show "unavailable", not 0.
     */
    public function available(): bool
    {
        return $this->fetch() !== null;
    }

    /**
     * Whether the index has drifted from what the store queued locally: the last index run
     * failed, or fewer products are actually indexed than were queued. While indexing is in
     * flight (pending > 0) the backend index legitimately lags the local queued count, so
     * that is NOT drift — only a settled, converged state can be "wrong". When the backend
     * is unavailable (products() null) we can't tell, so report no drift.
     */
    public function drift(int $queuedLocal): bool
    {
        // Jobs still processing — the lag is expected, not drift.
        if (($this->pending() ?? 0) > 0) {
            return false;
        }

        if ($this->status() === 'failed') {
            return true;
        }

        $products = $this->products();

        return $products !== null && $products < $queuedLocal;
    }

    /**
     * Drop the cached snapshot so the next read re-fetches. Called on bulk finish so the
     * dashboard immediately reflects the newly-queued work instead of a stale count.
     */
    public function flush(): void
    {
        delete_transient(self::CACHE_KEY);
        $this->snapshot = null;
        $this->fetched = false;
    }

    /**
     * Return the cached counts snapshot, fetching once (per 30s) on a miss. A successful
     * fetch caches the normalized `counts` merged with the top-level status fields; a
     * failure caches an "unavailable" sentinel (also for 30s) so an outage neither shows a
     * misleading 0 nor lets a poller hammer the API. null = unavailable.
     *
     * @return array<string, mixed>|null
     */
    private function fetch(): ?array
    {
        if ($this->fetched) {
            return $this->snapshot;
        }

        $this->fetched = true;

        $cached = get_transient(self::CACHE_KEY);

        if (\is_array($cached)) {
            return $this->snapshot = ($cached['available'] ?? false) ? $cached : null;
        }

        $result = $this->client->getCounts();

        $body = $result['body'];

        // Unavailable when the request failed, OR when the backend explicitly reports the
        // live index read did not reconcile (embeddings unreachable → the counts are stale
        // last-known DB values, not the truth). Cache the sentinel so we show "—" rather
        // than a misleading number, and don't re-hammer the API for 30s. A missing
        // `reconciled` key is an older API — trust the counts as before.
        if (! $result['ok'] || ($body['reconciled'] ?? true) === false) {
            set_transient(self::CACHE_KEY, ['available' => false], self::CACHE_TTL);

            return $this->snapshot = null;
        }

        $counts = \is_array($body['counts'] ?? null) ? $body['counts'] : [];

        $snapshot = [
            'available' => true,
            'products' => (int) ($counts['products'] ?? 0),
            'pending' => (int) ($body['pending'] ?? 0),
            'status' => $body['status'] ?? null,
            'last_indexed_at' => $body['last_indexed_at'] ?? null,
        ];

        set_transient(self::CACHE_KEY, $snapshot, self::CACHE_TTL);

        return $this->snapshot = $snapshot;
    }
}
