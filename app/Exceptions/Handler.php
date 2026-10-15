<?php

namespace App\Exceptions;

use App\Services\DatabaseCircuitBreaker;
use App\Support\DatabaseFailure;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        // Já é registado, com contexto e uma vez por minuto, pelos serviços de
        // fallback. Reportar cada ocorrência durante uma falha prolongada
        // encheria o disco de stack traces iguais.
        DatabaseUnavailableException::class,
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        /*
         * Connection-level database failures become a 503 maintenance page
         * instead of a 500. Only failures classified by DatabaseFailure are
         * handled here, so a genuine SQL bug still surfaces as an error.
         */
        $this->renderable(function (DatabaseUnavailableException $e, Request $request) {
            return $this->databaseUnavailableResponse($request);
        });

        $this->renderable(function (Throwable $e, Request $request) {
            if (! DatabaseFailure::isUnavailable($e)) {
                return null;
            }

            // A query that did not go through the fallback cache failed; count
            // it so the breaker opens for everyone else too.
            try {
                app(DatabaseCircuitBreaker::class)->recordFailure();
            } catch (Throwable $ignored) {
                // The cache store is unavailable as well; nothing to do.
            }

            return $this->databaseUnavailableResponse($request);
        });
    }

    /**
     * The maintenance response. Carries no SQL detail, no credentials and no
     * stack trace, and is marked so the page cache can replace it with a stale
     * copy of the requested page when one exists.
     */
    protected function databaseUnavailableResponse(Request $request): Response
    {
        $retryAfter = (int) config('fallback.retry_after', 60);

        $headers = [
            'Retry-After' => $retryAfter,
            'Cache-Control' => 'no-store, private',
            'X-Database-Unavailable' => '1',
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Serviço temporariamente indisponível. Por favor, tente novamente dentro de alguns instantes.',
            ], 503, $headers);
        }

        $page = public_path('database-unavailable.html');

        $content = is_file($page)
            ? (string) file_get_contents($page)
            : 'Serviço temporariamente indisponível. Por favor, tente novamente dentro de alguns instantes.';

        return response($content, 503, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
