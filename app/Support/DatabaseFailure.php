<?php

namespace App\Support;

use PDOException;
use Throwable;

/**
 * Distinguishes "the database is unreachable or out of connections" from a
 * genuine SQL bug. Only the former may be masked by cached content; a syntax
 * error or an unknown column must keep bubbling up as a 500 so it gets fixed.
 */
final class DatabaseFailure
{
    /**
     * MySQL driver error codes that mean the server could not be reached or
     * refused the connection. 1226 is the shared-hosting
     * 'max_user_connections' limit, which arrives with SQLSTATE 42000 - the
     * same SQLSTATE as a syntax error, hence the match on the driver code.
     */
    private const DRIVER_CODES = [
        1040,  // Too many connections
        1203,  // User already has more than 'max_user_connections' active connections
        1226,  // User has exceeded the 'max_user_connections' resource
        1053,  // Server shutdown in progress
        1152,  // Aborted connection
        1927,  // Connection was killed
        2002,  // Can't connect to local MySQL server (socket missing / refused)
        2003,  // Can't connect to MySQL server (TCP)
        2006,  // MySQL server has gone away
        2013,  // Lost connection to MySQL server during query
    ];

    private const MESSAGE_PATTERNS = [
        'max_user_connections',
        'max_questions',
        'max_updates',
        'too many connections',
        'server has gone away',
        'lost connection',
        'connection refused',
        'connection timed out',
        'server shutdown in progress',
        'no such file or directory',
        'php_network_getaddresses',
        'name or service not known',
    ];

    public static function isUnavailable(?Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof PDOException) {
                continue;
            }

            if (self::matchesDriverCode($current) || self::matchesMessage($current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The driver code of the failure, for logging. Null when unknown.
     */
    public static function driverCode(?Throwable $e): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && ($code = self::codeOf($current)) !== null) {
                return $code;
            }
        }

        return null;
    }

    private static function matchesDriverCode(PDOException $e): bool
    {
        $code = self::codeOf($e);

        return $code !== null && in_array($code, self::DRIVER_CODES, true);
    }

    private static function matchesMessage(PDOException $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (self::MESSAGE_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function codeOf(PDOException $e): ?int
    {
        // PDOException::$errorInfo is [SQLSTATE, driver code, driver message].
        // QueryException does not copy it from the wrapped PDOException, which
        // is why the whole chain is walked.
        if (is_array($e->errorInfo ?? null) && isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
            return (int) $e->errorInfo[1];
        }

        // Connection failures raised by the PDO constructor carry the driver
        // code in getCode() instead.
        $code = $e->getCode();

        return is_numeric($code) ? (int) $code : null;
    }
}
