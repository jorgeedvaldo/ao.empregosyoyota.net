<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deixa as páginas públicas de leitura serem guardadas por caches partilhadas
 * (navegadores, Cloudflare e o LiteSpeed do alojamento) durante 5 minutos.
 *
 * Só se aplica quando é seguro: resposta 200 a um GET/HEAD, sem cookies a
 * sair, sem sessão iniciada e sem sinais de um visitante autenticado. Nesses
 * casos mantém-se o cabeçalho por omissão do Laravel (no-cache, private).
 */
class PublicCacheHeaders
{
    public const MAX_AGE = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->isShareable($request, $response)) {
            $response->headers->set('Cache-Control', 'public, max-age='.self::MAX_AGE);
            $response->headers->set('X-LiteSpeed-Cache-Control', 'public,max-age='.self::MAX_AGE);
        }

        return $response;
    }

    private function isShareable(Request $request, Response $response): bool
    {
        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200) {
            return false;
        }

        // Qualquer cookie a sair (sessão, XSRF, outro) torna a resposta pessoal.
        if ($response->headers->getCookies() !== []) {
            return false;
        }

        if ($request->hasSession() && $request->session()->isStarted()) {
            return false;
        }

        // Quem traz cookie de sessão do Laravel ou credenciais não recebe cabeçalhos públicos.
        return ! $request->cookies->has(config('session.cookie'))
            && ! $request->headers->has('Authorization');
    }
}
