<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Keeps the fallback and page caches from growing without bound on shared
 * hosting: expired entries are deleted first and, if a store is still over its
 * configured size, the least recently used entries go too.
 *
 * Laravel's file cache only removes an expired entry when something asks for
 * that exact key again, so pages that are never revisited would otherwise stay
 * on disk forever.
 */
class PruneFallbackCache extends Command
{
    protected $signature = 'cache:prune-fallback {--store= : Limitar a um único store}';

    protected $description = 'Remove entradas expiradas das caches de fallback e aplica o limite de espaço configurado';

    public function handle(): int
    {
        $limits = (array) config('fallback.retention.max_megabytes', []);

        if ($only = $this->option('store')) {
            $limits = array_intersect_key($limits, [$only => true]);

            if ($limits === []) {
                $this->error("Store [{$only}] não tem limite configurado em fallback.retention.");

                return self::FAILURE;
            }
        }

        foreach ($limits as $store => $megabytes) {
            $this->pruneStore((string) $store, (int) $megabytes);
        }

        return self::SUCCESS;
    }

    private function pruneStore(string $store, int $megabytes): void
    {
        $path = config("cache.stores.{$store}.path");

        if (! is_string($path) || ! is_dir($path)) {
            $this->line("Store [{$store}]: sem directório em disco, ignorado.");

            return;
        }

        $expired = 0;
        $expiredBytes = 0;
        $kept = [];
        $keptBytes = 0;

        foreach ($this->files($path) as $file) {
            $size = $file->getSize();

            if ($this->hasExpired($file)) {
                if (@unlink($file->getPathname())) {
                    $expired++;
                    $expiredBytes += $size;
                }

                continue;
            }

            $kept[] = ['path' => $file->getPathname(), 'size' => $size, 'time' => $file->getMTime()];
            $keptBytes += $size;
        }

        [$evicted, $evictedBytes] = $this->enforceSizeLimit($kept, $keptBytes, $megabytes * 1024 * 1024);

        $this->removeEmptyDirectories($path);

        $this->info(sprintf(
            'Store [%s]: %d expiradas (%s), %d removidas por limite de espaço (%s), %s em uso.',
            $store,
            $expired,
            $this->humanize($expiredBytes),
            $evicted,
            $this->humanize($evictedBytes),
            $this->humanize(max(0, $keptBytes - $evictedBytes))
        ));
    }

    /**
     * @param  array<int, array{path: string, size: int, time: int}>  $entries
     * @return array{0: int, 1: int}
     */
    private function enforceSizeLimit(array $entries, int $currentBytes, int $maxBytes): array
    {
        if ($maxBytes <= 0 || $currentBytes <= $maxBytes) {
            return [0, 0];
        }

        // Oldest first: the entries least likely to still be serving traffic.
        usort($entries, fn (array $a, array $b) => $a['time'] <=> $b['time']);

        $evicted = 0;
        $evictedBytes = 0;

        foreach ($entries as $entry) {
            if ($currentBytes - $evictedBytes <= $maxBytes) {
                break;
            }

            if (@unlink($entry['path'])) {
                $evicted++;
                $evictedBytes += $entry['size'];
            }
        }

        return [$evicted, $evictedBytes];
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function files(string $path): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                yield $file;
            }
        }
    }

    /**
     * A file cache entry starts with a 10 digit expiry timestamp.
     */
    private function hasExpired(SplFileInfo $file): bool
    {
        $handle = @fopen($file->getPathname(), 'rb');

        if ($handle === false) {
            return false;
        }

        $expiry = fread($handle, 10);
        fclose($handle);

        if (! is_string($expiry) || ! ctype_digit($expiry)) {
            return false;
        }

        return time() >= (int) $expiry;
    }

    private function removeEmptyDirectories(string $path): void
    {
        $directories = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($directories as $directory) {
            if ($directory instanceof SplFileInfo && $directory->isDir()) {
                @rmdir($directory->getPathname());
            }
        }
    }

    private function humanize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return $bytes < 1048576
            ? round($bytes / 1024, 1).' KB'
            : round($bytes / 1048576, 1).' MB';
    }
}
