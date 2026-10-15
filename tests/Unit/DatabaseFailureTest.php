<?php

namespace Tests\Unit;

use App\Support\DatabaseFailure;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseFailureTest extends TestCase
{
    /**
     * The real production error. Note the SQLSTATE is 42000, the same as a
     * syntax error, so the classification has to use the driver code.
     */
    public function test_it_detects_the_max_user_connections_error(): void
    {
        $exception = $this->queryException(
            42000,
            1226,
            "SQLSTATE[42000] [1226] User 'yqqnyhrgnt_edv' has exceeded the 'max_user_connections' resource (current value: 30)"
        );

        $this->assertTrue(DatabaseFailure::isUnavailable($exception));
        $this->assertSame(1226, DatabaseFailure::driverCode($exception));
    }

    /**
     * @dataProvider connectionFailures
     */
    public function test_it_detects_connection_level_failures(int $driverCode, string $message): void
    {
        $this->assertTrue(DatabaseFailure::isUnavailable($this->queryException('HY000', $driverCode, $message)));
    }

    public static function connectionFailures(): array
    {
        return [
            'too many connections' => [1040, 'SQLSTATE[HY000] [1040] Too many connections'],
            'max per user' => [1203, "SQLSTATE[HY000] [1203] User already has more than 'max_user_connections' active connections"],
            'socket missing' => [2002, 'SQLSTATE[HY000] [2002] No such file or directory'],
            'refused' => [2002, 'SQLSTATE[HY000] [2002] Connection refused'],
            'tcp' => [2003, "SQLSTATE[HY000] [2003] Can't connect to MySQL server on 'localhost'"],
            'gone away' => [2006, 'SQLSTATE[HY000] [2006] MySQL server has gone away'],
            'lost during query' => [2013, 'SQLSTATE[HY000] [2013] Lost connection to MySQL server during query'],
        ];
    }

    /**
     * A programming mistake must never be hidden behind cached content.
     *
     * @dataProvider realBugs
     */
    public function test_it_ignores_genuine_sql_errors(string $sqlState, int $driverCode, string $message): void
    {
        $this->assertFalse(DatabaseFailure::isUnavailable($this->queryException($sqlState, $driverCode, $message)));
    }

    public static function realBugs(): array
    {
        return [
            'syntax error' => ['42000', 1064, "SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax"],
            'unknown column' => ['42S22', 1054, "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'titel' in 'field list'"],
            'unknown table' => ['42S02', 1146, "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.jobz' doesn't exist"],
            'duplicate entry' => ['23000', 1062, "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry"],
            'deadlock' => ['40001', 1213, 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found'],
        ];
    }

    public function test_it_ignores_exceptions_that_are_not_database_errors(): void
    {
        $this->assertFalse(DatabaseFailure::isUnavailable(new RuntimeException('Lost connection to the printer')));
        $this->assertFalse(DatabaseFailure::isUnavailable(null));
    }

    private function queryException(string|int $sqlState, int $driverCode, string $message): QueryException
    {
        $previous = new PDOException($message, 0);
        $previous->errorInfo = [(string) $sqlState, $driverCode, $message];

        return new QueryException('select * from `jobs`', [], $previous);
    }
}
