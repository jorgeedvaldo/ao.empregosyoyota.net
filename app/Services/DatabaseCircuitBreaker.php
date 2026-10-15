<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Keeps the application from hammering a MySQL server that has no connections
 * left to give. State lives in the cache store, so it is shared by every PHP
 * process serving the site.
 */
class DatabaseCircuitBreaker
{
    private const FAILURES_KEY = 'db-breaker:failures';
    private const OPEN_KEY = 'db-breaker:open';
    private const PROBE_KEY = 'db-breaker:probe';

    public function __construct(
        private CacheRepository $cache,
        private array $config = [],
    ) {
    }

    public function isOpen(): bool
    {
        return (bool) $this->cache->get(self::OPEN_KEY, false);
    }

    /**
     * True for at most one caller per probe interval, so a single request
     * tests recovery while the others keep serving cached content.
     *
     * Relies on the atomic add() of the file cache store (exclusive flock),
     * which is what makes this safe across concurrent PHP processes.
     */
    public function allowsProbe(): bool
    {
        return $this->cache->add(self::PROBE_KEY, 1, $this->seconds('probe_interval', 10));
    }

    public function recordFailure(): void
    {
        $failures = ((int) $this->cache->get(self::FAILURES_KEY, 0)) + 1;

        $this->cache->put(self::FAILURES_KEY, $failures, $this->seconds('failure_window', 60));

        if ($failures >= max(1, (int) ($this->config['failure_threshold'] ?? 3))) {
            $this->cache->put(self::OPEN_KEY, true, $this->seconds('open_seconds', 30));
        }
    }

    public function recordSuccess(): void
    {
        $this->cache->forget(self::FAILURES_KEY);
        $this->cache->forget(self::OPEN_KEY);
    }

    private function seconds(string $key, int $default): int
    {
        return max(1, (int) ($this->config[$key] ?? $default));
    }
}
