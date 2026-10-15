<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full-page cache for the public, read-only pages.
 *
 * A hit is answered without touching MySQL at all, which is what keeps the
 * site up once the connection limit is reached. Two tiers are stored: a short
 * lived "fresh" copy for normal traffic and a long lived "stale" copy served
 * only while the database is unavailable.
 *
 * Nothing user-specific is ever stored: responses for authenticated visitors,
 * responses carrying a CSRF token and session cookies are all excluded.
 */
class PublicPageCache
{
    private const VERSION_KEY = 'page-version';

    private ?int $version = null;

    public function __construct(
        private CacheRepository $cache,
        private array $config = [],
    ) {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /**
     * Whether this request may be answered from, or stored in, the public
     * cache. Deliberately conservative.
     */
    public function isCacheable(Request $request): bool
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        return ! $this->hasPrivateSessionState($request);
    }

    /**
     * @return array{content: string, status: int, content_type: string, stored_at: int}|null
     */
    public function fresh(Request $request): ?array
    {
        $payload = $this->cache->get($this->freshKey($request));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array{content: string, status: int, content_type: string, stored_at: int}|null
     */
    public function stale(Request $request): ?array
    {
        $payload = $this->cache->get($this->staleKey($request));

        return is_array($payload) ? $payload : null;
    }

    public function put(Request $request, Response $response): void
    {
        if (! $this->isStorable($response)) {
            return;
        }

        $payload = [
            'content' => $response->getContent(),
            'status' => $response->getStatusCode(),
            'content_type' => (string) $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
            'stored_at' => time(),
        ];

        $this->cache->put($this->freshKey($request), $payload, $this->seconds('fresh_ttl', 300));
        $this->cache->put($this->staleKey($request), $payload, $this->seconds('stale_ttl', 172800));
    }

    /**
     * Invalidates the fresh copies of every page. The stale copies keep their
     * own unversioned keys on purpose: right after a content change they are
     * still the best thing to show if MySQL goes down.
     */
    public function flush(): void
    {
        $this->version = $this->version() + 1;

        $this->cache->forever(self::VERSION_KEY, $this->version);
    }

    /**
     * Rebuilds a response from a cached payload. Only the content type is
     * restored - no Set-Cookie, no session, no private headers.
     *
     * @param  array{content: string, status: int, content_type: string, stored_at: int}  $payload
     */
    public function toResponse(array $payload, string $state): Response
    {
        $response = new \Illuminate\Http\Response(
            $payload['content'],
            $payload['status'],
            ['Content-Type' => $payload['content_type']]
        );

        $response->headers->set('X-Page-Cache', $state);

        if ($this->config['http_cache_headers'] ?? false) {
            $this->addPublicCacheHeaders($response);
        }

        return $response;
    }

    /**
     * Throttles the outage warning to once a minute: every request would
     * otherwise write the same line and fill the disk.
     */
    public function shouldLog(): bool
    {
        return $this->cache->add('log-throttle', 1, 60);
    }

    public function addPublicCacheHeaders(Response $response): void
    {
        $maxAge = $this->seconds('http_cache_max_age', 300);

        $response->headers->set('Cache-Control', 'public, max-age='.$maxAge);

        if ($this->config['litespeed'] ?? false) {
            $response->headers->set('X-LiteSpeed-Cache-Control', 'public,max-age='.$maxAge);
        }
    }

    private function isStorable(Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type');

        if (! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'xml')) {
            return false;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return false;
        }

        // Safety net for pages that gain a form later on: a CSRF token belongs
        // to one session and must never be handed to another visitor.
        return ! preg_match('/name=["\']_token["\']|csrf-token/i', $content);
    }

    /**
     * Looks for an authenticated visitor or flashed data without resolving the
     * auth guard, which would query the users table - exactly what must be
     * avoided while MySQL is refusing connections. hasUser() only reports a
     * user that is already loaded, so it costs nothing.
     */
    private function hasPrivateSessionState(Request $request): bool
    {
        if (Auth::hasUser()) {
            return true;
        }

        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return false;
        }

        foreach (array_keys($request->session()->all()) as $key) {
            if (str_starts_with($key, 'login_') || str_starts_with($key, 'password_hash_')) {
                return true;
            }
        }

        $flash = array_merge(
            (array) $request->session()->get('_flash.old', []),
            (array) $request->session()->get('_flash.new', [])
        );

        return $flash !== [] || $request->session()->has('errors');
    }

    private function freshKey(Request $request): string
    {
        return 'fresh:v'.$this->version().':'.$this->fingerprint($request);
    }

    private function staleKey(Request $request): string
    {
        return 'stale:'.$this->fingerprint($request);
    }

    /**
     * Only whitelisted query parameters take part in the key. Tracking
     * parameters (utm_*, gclid, fbclid, ...) do not change the rendered page,
     * so ignoring them keeps the keyspace bounded and stops a visitor from
     * filling the disk with unique URLs.
     */
    private function fingerprint(Request $request): string
    {
        $allowed = (array) ($this->config['query_allowlist'] ?? []);

        $params = array_intersect_key($request->query(), array_flip($allowed));
        ksort($params);

        return sha1($request->getHost().'|'.$request->getPathInfo().'|'.http_build_query($params));
    }

    private function version(): int
    {
        return $this->version ??= max(1, (int) $this->cache->get(self::VERSION_KEY, 1));
    }

    private function seconds(string $key, int $default): int
    {
        return max(1, (int) ($this->config[$key] ?? $default));
    }
}
