<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the database is unreachable and no cached copy of the requested
 * data exists. Rendered as a 503 maintenance page; the message is internal and
 * never shown to visitors.
 */
class DatabaseUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Base de dados indisponível e sem cópia em cache.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
