<?php

namespace App\Services;

use App\Exceptions\DatabaseUnavailableException;
use App\Support\DatabaseFailure;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads data through two cache tiers:
 *
 *  - "fresh": short lived, answers the normal request and keeps MySQL idle.
 *  - "emergency": long lived copy of the last known good result, used only
 *    when the database is unreachable. It is stored under its own key so it
 *    survives the expiry of the fresh copy.
 *
 * A genuine SQL error is never masked - only connection-level failures
 * (notably MySQL 1226, the shared-hosting max_user_connections limit).
 */
class DatabaseFallbackCache
{
    private const VERSION_KEY = 'fresh-version';

    private ?int $version = null;

    public function __construct(
        private CacheRepository $cache,
        private DatabaseCircuitBreaker $breaker,
        private array $profiles = [],
        private bool $enabled = true,
    ) {
    }

    /**
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws DatabaseUnavailableException when the database is down and
     *         nothing usable was ever cached under this key.
     */
    public function remember(string $key, Closure $callback, string $profile = 'shared')
    {
        if (! $this->enabled) {
            return $callback();
        }

        $fresh = $this->cache->get($this->freshKey($key));

        if (is_array($fresh)) {
            return $fresh['value'];
        }

        // Database is known to be down: skip the connection attempt entirely,
        // except for the single probe allowed per interval.
        if ($this->breaker->isOpen() && ! $this->breaker->allowsProbe()) {
            return $this->emergencyOrFail($key, null);
        }

        try {
            $value = $callback();
        } catch (Throwable $e) {
            if (! DatabaseFailure::isUnavailable($e)) {
                throw $e;
            }

            $this->breaker->recordFailure();

            if ($this->shouldLog()) {
                Log::warning('MySQL indisponível; a servir dados em cache.', [
                    'key' => $key,
                    'driver_code' => DatabaseFailure::driverCode($e),
                ]);
            }

            return $this->emergencyOrFail($key, $e);
        }

        $this->breaker->recordSuccess();
        $this->store($key, $value, $profile);

        return $value;
    }

    /**
     * Invalidates every fresh copy at once, so edits made in the admin panel
     * show up immediately. The emergency copies are deliberately kept: they
     * are the safety net if MySQL is down by the time the next request lands.
     */
    public function flushFresh(): void
    {
        $this->version = $this->version() + 1;

        $this->cache->forever(self::VERSION_KEY, $this->version);
    }

    public function isDegraded(): bool
    {
        return $this->breaker->isOpen();
    }

    /**
     * An outage affects every request, so the warning is written at most once
     * a minute. Filling the disk with identical log lines would turn a
     * recoverable incident into a worse one.
     */
    private function shouldLog(): bool
    {
        return $this->cache->add('log-throttle', 1, 60);
    }

    private function store(string $key, mixed $value, string $profile): void
    {
        [$ttl, $emergencyTtl] = $this->ttlFor($profile);

        $payload = ['value' => $value, 'stored_at' => time()];

        $this->cache->put($this->freshKey($key), $payload, $ttl);
        $this->cache->put($this->emergencyKey($key), $payload, $emergencyTtl);
    }

    private function emergencyOrFail(string $key, ?Throwable $previous): mixed
    {
        $payload = $this->cache->get($this->emergencyKey($key));

        if (is_array($payload)) {
            return $payload['value'];
        }

        throw new DatabaseUnavailableException(previous: $previous);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function ttlFor(string $profile): array
    {
        $settings = $this->profiles[$profile] ?? $this->profiles['shared'] ?? [];

        return [
            max(1, (int) ($settings['ttl'] ?? 300)),
            max(1, (int) ($settings['emergency'] ?? 86400)),
        ];
    }

    private function freshKey(string $key): string
    {
        return 'fresh:v'.$this->version().':'.$key;
    }

    private function version(): int
    {
        return $this->version ??= max(1, (int) $this->cache->get(self::VERSION_KEY, 1));
    }

    private function emergencyKey(string $key): string
    {
        return 'emergency:'.$key;
    }
}
