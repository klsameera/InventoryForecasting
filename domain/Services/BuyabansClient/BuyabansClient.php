<?php

declare(strict_types=1);

namespace Domain\Services\BuyabansClient;

use Domain\Services\BuyabansSyncService\BuyabansSyncService;
use Domain\Services\MlServiceClient\MlServiceClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The HTTP side of the BuyAbans integration: authenticates, fetches, and walks
 * pages. It knows nothing about what the data means — {@see BuyabansSyncService}
 * decides that.
 *
 * **Read-only, on purpose.** Every method here issues a GET. This application
 * forecasts demand; the back office owns the catalog, the stock and the orders,
 * and nothing in this codebase is allowed to write back to it.
 *
 * Authentication is OAuth2 client credentials against the back office's
 * Passport install, so this application authenticates as a machine rather than
 * borrowing a person's session. The token is cached until shortly before it
 * expires, because a full sync makes hundreds of requests and minting a token
 * per request would triple the round trips for no benefit.
 *
 * Like {@see MlServiceClient}, this Service has
 * no constructor-injected model — it owns no table.
 */
final class BuyabansClient
{
    private const TOKEN_CACHE_KEY = 'buyabans:oauth:token';

    /**
     * Refresh this many seconds before the token actually expires, so a long
     * page walk cannot have a token die mid-request.
     */
    private const TOKEN_EXPIRY_MARGIN = 120;

    /**
     * Safety stop on a cursor walk. A back office that returned a non-advancing
     * cursor would otherwise loop forever; this turns that into a loud failure.
     */
    private const MAX_PAGES = 20000;

    /**
     * Fetch one page of a resource.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> the endpoint's `data` payload
     */
    public function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($this->url($path), $query);

        if ($response->status() === 401) {
            // The cached token was rejected — most likely revoked or rotated on
            // the back office. Drop it and try once more with a fresh one
            // rather than failing a multi-hour sync on a recoverable error.
            Cache::forget(self::TOKEN_CACHE_KEY);

            $response = $this->request()->get($this->url($path), $query);
        }

        if ($response->failed()) {
            Log::error('BuyAbans API request failed', [
                'path' => $path,
                'query' => $query,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new RuntimeException(
                "BuyAbans API {$path} returned HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300)
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['status'] ?? false) !== true) {
            throw new RuntimeException(
                "BuyAbans API {$path} returned an unsuccessful payload: ".($payload['message'] ?? 'no message')
            );
        }

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    /**
     * Walk every page of a keyset-paginated resource, handing each page's items
     * to the callback.
     *
     * Pages are streamed rather than accumulated: a full product or demand sync
     * is far larger than this process should ever hold in memory at once.
     *
     * @param  array<string, mixed>  $query
     * @param  callable(list<array<string, mixed>>): int  $onPage  returns rows written
     * @return array{pages: int, fetched: int, written: int}
     */
    public function walk(string $path, array $query, callable $onPage): array
    {
        $pages = 0;
        $fetched = 0;
        $written = 0;
        $cursor = null;

        do {
            $page = $this->get($path, $cursor === null ? $query : $query + ['cursor' => $cursor]);

            $items = $page['items'] ?? [];
            $meta = $page['meta'] ?? [];

            $fetched += count($items);
            $written += $onPage($items);
            $pages++;

            $next = $meta['next_cursor'] ?? null;

            if ($next !== null && $cursor !== null && (int) $next <= (int) $cursor) {
                throw new RuntimeException("BuyAbans API {$path} returned a non-advancing cursor ({$next}).");
            }

            $cursor = $next;

            if ($pages >= self::MAX_PAGES) {
                throw new RuntimeException("BuyAbans API {$path} exceeded ".self::MAX_PAGES.' pages; aborting.');
            }
        } while ($cursor !== null);

        return ['pages' => $pages, 'fetched' => $fetched, 'written' => $written];
    }

    /**
     * Walk every page of the daily-sales aggregate.
     *
     * That endpoint pages by offset rather than cursor, because an aggregate
     * has no stable single-column key to keyset from — so it needs its own
     * walker rather than {@see walk()}.
     *
     * @param  array<string, mixed>  $query
     * @param  callable(list<array<string, mixed>>): int  $onPage  returns rows written
     * @return array{pages: int, fetched: int, written: int}
     */
    public function walkOffset(string $path, array $query, callable $onPage): array
    {
        $pages = 0;
        $fetched = 0;
        $written = 0;
        $offset = 0;

        do {
            $page = $this->get($path, $query + ['offset' => $offset]);

            $items = $page['items'] ?? [];
            $meta = $page['meta'] ?? [];

            $fetched += count($items);
            $written += $onPage($items);
            $pages++;

            $next = $meta['next_offset'] ?? null;

            if ($next !== null && (int) $next <= $offset) {
                throw new RuntimeException("BuyAbans API {$path} returned a non-advancing offset ({$next}).");
            }

            $offset = $next === null ? 0 : (int) $next;

            if ($pages >= self::MAX_PAGES) {
                throw new RuntimeException("BuyAbans API {$path} exceeded ".self::MAX_PAGES.' pages; aborting.');
            }
        } while ($next !== null);

        return ['pages' => $pages, 'fetched' => $fetched, 'written' => $written];
    }

    /**
     * True when credentials are configured. The UI uses this to explain that
     * the integration is not set up, rather than showing a failed sync.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.buyabans.client_id'))
            && filled(config('services.buyabans.client_secret'))
            && filled(config('services.buyabans.url'));
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->timeout((int) config('services.buyabans.timeout', 120))
            ->retry(3, 500, throw: false);
    }

    /**
     * A client-credentials access token, cached until shortly before expiry.
     */
    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'BuyAbans API credentials are not configured. Set BUYABANS_API_URL, BUYABANS_CLIENT_ID and BUYABANS_CLIENT_SECRET.'
            );
        }

        $response = Http::acceptJson()
            ->timeout((int) config('services.buyabans.timeout', 120))
            ->post($this->url('/oauth/token'), [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.buyabans.client_id'),
                'client_secret' => config('services.buyabans.client_secret'),
                'scope' => '',
            ]);

        if ($response->failed()) {
            Log::error('BuyAbans token request failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw new RuntimeException("Could not obtain a BuyAbans access token (HTTP {$response->status()}).");
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('BuyAbans returned no access token.');
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - self::TOKEN_EXPIRY_MARGIN);

        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.buyabans.url'), '/').'/'.ltrim($path, '/');
    }
}
