<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
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

    public function show(string $path): SymfonyResponse
    {
        $path = $this->sanitize($path);

        if ($path === null) {
            abort(404);
        }

        $relative = 'images/'.$path;

        if (Storage::disk('public')->exists($relative)) {
            return $this->fileResponse(
                Storage::disk('public')->get($relative),
                $this->contentTypeForPath($path)
            );
        }

        try {
            $response = Http::timeout(10)->get(self::ORIGIN.'/storage/'.$relative);
        } catch (\Throwable $e) {
            Log::warning('Image proxy request failed', ['path' => $path]);
            abort(404);
        }

        if (! $response->successful()) {
            abort(404);
        }

        $body = $response->body();
        $contentType = $response->header('Content-Type') ?: $this->contentTypeForPath($path);

        Storage::disk('public')->put($relative, $body);

        return $this->fileResponse($body, $contentType);
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

    private function fileResponse(string $body, string $contentType): Response
    {
        return response($body, 200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
