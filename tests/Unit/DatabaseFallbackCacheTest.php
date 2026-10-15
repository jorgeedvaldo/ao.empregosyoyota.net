<?php

namespace Tests\Unit;

use App\Exceptions\DatabaseUnavailableException;
use App\Services\DatabaseCircuitBreaker;
use App\Services\DatabaseFallbackCache;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class DatabaseFallbackCacheTest extends TestCase
{
    private const PROFILES = [
        'shared' => ['ttl' => 600, 'emergency' => 604800],
    ];

    public function test_it_queries_once_and_then_serves_the_fresh_copy(): void
    {
        $cache = $this->fallbackCache();
        $calls = 0;

        $query = function () use (&$calls) {
            $calls++;

            return 'vagas';
        };

        $this->assertSame('vagas', $cache->remember('jobs', $query));
        $this->assertSame('vagas', $cache->remember('jobs', $query));
        $this->assertSame('vagas', $cache->remember('jobs', $query));

        $this->assertSame(1, $calls, 'A base de dados devia ter sido consultada uma única vez.');
    }

    public function test_it_serves_the_emergency_copy_when_mysql_is_unavailable(): void
    {
        $cache = $this->fallbackCache();

        $cache->remember('jobs', fn () => 'vagas em cache');

        // A cópia normal expira, a de emergência sobrevive.
        $this->travel(11)->minutes();

        $value = $cache->remember('jobs', fn () => throw $this->maxUserConnections());

        $this->assertSame('vagas em cache', $value);
    }

    public function test_the_emergency_copy_outlives_the_fresh_copy(): void
    {
        $cache = $this->fallbackCache();

        $cache->remember('jobs', fn () => 'vagas em cache');

        $this->travel(2)->days();

        $this->assertSame(
            'vagas em cache',
            $cache->remember('jobs', fn () => throw $this->maxUserConnections())
        );
    }

    public function test_it_fails_cleanly_when_nothing_was_ever_cached(): void
    {
        $cache = $this->fallbackCache();

        $this->expectException(DatabaseUnavailableException::class);

        $cache->remember('jobs', fn () => throw $this->maxUserConnections());
    }

    public function test_it_rethrows_genuine_sql_errors(): void
    {
        $cache = $this->fallbackCache();

        // Uma cópia válida existe, mas um erro de programação não deve ser
        // escondido por trás dela.
        $cache->remember('jobs', fn () => 'vagas em cache');
        $this->travel(11)->minutes();

        $this->expectException(QueryException::class);

        $cache->remember('jobs', fn () => throw $this->syntaxError());
    }

    public function test_it_rethrows_unrelated_exceptions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bug');

        $this->fallbackCache()->remember('jobs', fn () => throw new RuntimeException('bug'));
    }

    public function test_concurrent_requests_do_not_all_retry_the_connection(): void
    {
        $cache = $this->fallbackCache();
        $attempts = 0;

        $failing = function () use (&$attempts) {
            $attempts++;

            throw $this->maxUserConnections();
        };

        $cache->remember('jobs', fn () => 'vagas em cache');
        $this->travel(11)->minutes();

        // Três falhas abrem o disjuntor (failure_threshold = 3).
        for ($i = 0; $i < 3; $i++) {
            $cache->remember('jobs', $failing);
        }

        $this->assertTrue($cache->isDegraded());
        $this->assertSame(3, $attempts);

        // Com o disjuntor aberto, vinte pedidos seguidos fazem no máximo uma
        // tentativa de ligação (uma por probe_interval).
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame('vagas em cache', $cache->remember('jobs', $failing));
        }

        $this->assertSame(4, $attempts, 'Só a tentativa de recuperação devia ter ligado ao MySQL.');
    }

    public function test_it_resumes_normal_operation_once_mysql_recovers(): void
    {
        $cache = $this->fallbackCache();

        $cache->remember('jobs', fn () => 'vagas antigas');
        $this->travel(11)->minutes();

        for ($i = 0; $i < 3; $i++) {
            $cache->remember('jobs', fn () => throw $this->maxUserConnections());
        }

        $this->assertTrue($cache->isDegraded());

        // Passado o intervalo, a tentativa de recuperação é permitida.
        $this->travel(11)->seconds();

        $this->assertSame('vagas novas', $cache->remember('jobs', fn () => 'vagas novas'));
        $this->assertFalse($cache->isDegraded());

        // E a partir daí serve-se a cópia nova sem voltar ao MySQL.
        $this->assertSame('vagas novas', $cache->remember('jobs', fn () => throw $this->maxUserConnections()));
    }

    public function test_flushing_fresh_copies_keeps_the_emergency_copy(): void
    {
        $cache = $this->fallbackCache();

        $cache->remember('jobs', fn () => 'vagas antigas');

        $cache->flushFresh();

        // A cópia normal desapareceu: nova consulta ao MySQL.
        $this->assertSame('vagas novas', $cache->remember('jobs', fn () => 'vagas novas'));

        // E a de emergência continuou disponível entretanto.
        $cache->flushFresh();

        $this->assertSame(
            'vagas novas',
            $cache->remember('jobs', fn () => throw $this->maxUserConnections())
        );
    }

    private function fallbackCache(): DatabaseFallbackCache
    {
        $store = $this->app['cache']->store('array');

        return new DatabaseFallbackCache(
            $store,
            new DatabaseCircuitBreaker($store, [
                'failure_threshold' => 3,
                'failure_window' => 60,
                'open_seconds' => 30,
                'probe_interval' => 10,
            ]),
            self::PROFILES
        );
    }

    private function maxUserConnections(): QueryException
    {
        $message = "SQLSTATE[42000] [1226] User 'yqqnyhrgnt_edv' has exceeded the 'max_user_connections' resource (current value: 30)";

        $previous = new PDOException($message, 0);
        $previous->errorInfo = ['42000', 1226, $message];

        return new QueryException('select * from `jobs`', [], $previous);
    }

    private function syntaxError(): QueryException
    {
        $message = 'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax';

        $previous = new PDOException($message, 0);
        $previous->errorInfo = ['42000', 1064, $message];

        return new QueryException('select * form `jobs`', [], $previous);
    }
}
