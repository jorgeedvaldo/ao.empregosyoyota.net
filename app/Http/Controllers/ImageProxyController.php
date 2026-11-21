<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Transparent proxy that mirrors /storage/images/* from the main domain
 * (empregosyoyota.net) so images are reachable under this subdomain without
 * a redirect and without duplicating files on disk.
 *
 * Flow: request -> local copy (storage/app/public/images/...) if present
 * (served again as a plain static file by Apache from then on) -> fetch
 * from the fixed origin, cache it locally, return it.
 */
class ImageProxyController extends Controller
{
    /**
     * The only host this proxy is allowed to fetch from. Never taken from
     * user input, so this can't be turned into an open proxy.
     */
    private const ORIGIN = 'https://empregosyoyota.net';

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];

    /** Falhas definitivas (404, resposta que não é imagem): não se volta a perguntar durante 1 hora. */
    private const MISSING_TTL = 3600;

    /** Falhas que podem ser passageiras (timeout, erro 5xx): só 1 minuto, para recuperar depressa. */
    private const TRANSIENT_TTL = 60;

    public function show(string $path): SymfonyResponse
    {
        $path = $this->sanitize($path);

        if ($path === null) {
            abort(404);
        }

        $relative = 'images/'.$path;
        $disk = Storage::disk('public');

        if (! $disk->exists($relative)) {
            // Já se perguntou à origem e a imagem não existe: não prende outro processo.
            abort_if(Cache::has("img404:{$path}"), 404);

            abort_unless($this->download($path, $relative), 404);
        }

        // response()->file() lê o ficheiro aos blocos, em vez de o carregar todo para memória.
        return response()->file($disk->path($relative), [
            'Content-Type' => $this->contentTypeForPath($path),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Vai buscar a imagem à origem e guarda-a em disco. Devolve false (e
     * lembra-se disso) se não existir ou se a origem falhar.
     */
    private function download(string $path, string $relative): bool
    {
        try {
            $response = Http::timeout(3)->connectTimeout(2)->get(self::ORIGIN.'/storage/'.$relative);
        } catch (\Throwable $e) {
            Log::warning('Image proxy request failed', ['path' => $path]);
            Cache::put("img404:{$path}", true, self::TRANSIENT_TTL);

            return false;
        }

        // Uma página de erro com estado 200 não pode ficar guardada como se fosse uma imagem.
        $isImage = $response->successful() && str_contains(strtolower((string) $response->header('Content-Type')), 'image/');

        if (! $isImage) {
            $definitive = $response->successful() || in_array($response->status(), [404, 410], true);
            Cache::put("img404:{$path}", true, $definitive ? self::MISSING_TTL : self::TRANSIENT_TTL);

            return false;
        }

        return Storage::disk('public')->put($relative, $response->body());
    }

    /**
     * Normalizes and validates the requested path. Returns null when the
     * path is unsafe (traversal attempts, unexpected characters, or an
     * extension outside the allow-list).
     */
    private function sanitize(string $path): ?string
    {
        $path = rawurldecode($path);

        if (str_contains($path, "\0")) {
            return null;
        }

        // Only letters, digits, dot, dash, underscore and forward slash as
        // path separators between segments.
        if (! preg_match('#^[A-Za-z0-9_\-./]+$#', $path)) {
            return null;
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        return implode('/', $segments);
    }

    private function contentTypeForPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::CONTENT_TYPES[$extension] ?? 'application/octet-stream';
    }
}
