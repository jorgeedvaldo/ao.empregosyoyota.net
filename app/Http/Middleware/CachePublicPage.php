<?php

namespace App\Http\Middleware;

use App\Exceptions\DatabaseUnavailableException;
use App\Services\PublicPageCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves public pages from the page cache and, while MySQL is unavailable,
 * falls back to the last stale copy instead of failing.
 *
 * Applied explicitly to the public read-only routes (default deny), never to
 * the admin panel, the API or search.
 */
class CachePublicPage
{
    public function __construct(private PublicPageCache $cache)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->cache->enabled() || ! $this->cache->isCacheable($request)) {
            return $next($request);
        }

        if ($payload = $this->cache->fresh($request)) {
            return $this->cache->toResponse($payload, 'hit');
        }

        try {
            $response = $next($request);
        } catch (DatabaseUnavailableException $e) {
            // Reached when this middleware runs outside the routing pipeline;
            // inside it, the handler has already turned the exception into the
            // marked 503 response handled below.
            return $this->serveStale($request) ?? throw $e;
        }

        if ($this->isDatabaseUnavailable($response)) {
            return $this->serveStale($request) ?? $response;
        }

        if ($this->cache->isCacheable($request)) {
            $this->cache->put($request, $response);
        }

        $response->headers->set('X-Page-Cache', 'miss');

        return $response;
    }

    private function serveStale(Request $request): ?Response
    {
        if (! $payload = $this->cache->stale($request)) {
            return null;
        }

        if ($this->cache->shouldLog()) {
            Log::warning('MySQL indisponível; a servir página em cache.', [
                'path' => $request->getPathInfo(),
                'stored_at' => $payload['stored_at'] ?? null,
            ]);
        }

        return $this->cache->toResponse($payload, 'stale');
    }

    private function isDatabaseUnavailable(Response $response): bool
    {
        return $response->headers->has('X-Database-Unavailable');
    }
}
