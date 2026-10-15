<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Retention policy: expired entries go first, then the least recently used
 * ones until the store fits in its configured size.
 */
class PruneFallbackCacheTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/cache-prune');

        File::deleteDirectory($this->path);
        File::makeDirectory($this->path, 0755, true);

        config([
            'cache.stores.pages.path' => $this->path,
            'fallback.retention.max_megabytes' => ['pages' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    public function test_it_deletes_expired_entries_and_keeps_valid_ones(): void
    {
        $this->writeEntry('expirada-1', time() - 60, 1024);
        $this->writeEntry('expirada-2', time() - 1, 1024);
        $this->writeEntry('valida', time() + 3600, 1024);

        $this->artisan('cache:prune-fallback', ['--store' => 'pages'])->assertSuccessful();

        $this->assertFileDoesNotExist($this->entryPath('expirada-1'));
        $this->assertFileDoesNotExist($this->entryPath('expirada-2'));
        $this->assertFileExists($this->entryPath('valida'));
    }

    public function test_it_enforces_the_size_limit_starting_with_the_oldest(): void
    {
        // 1,5 MB em entradas válidas, para um limite de 1 MB.
        foreach (range(1, 6) as $index) {
            $this->writeEntry("pagina-{$index}", time() + 3600, 256 * 1024);
            touch($this->entryPath("pagina-{$index}"), time() - (600 - $index * 60));
        }

        $this->assertGreaterThan(1024 * 1024, $this->totalSize());

        $this->artisan('cache:prune-fallback', ['--store' => 'pages'])->assertSuccessful();

        $this->assertLessThanOrEqual(1024 * 1024, $this->totalSize());

        // As mais antigas saem primeiro; as mais recentes sobrevivem.
        $this->assertFileDoesNotExist($this->entryPath('pagina-1'));
        $this->assertFileExists($this->entryPath('pagina-6'));
    }

    public function test_it_does_not_touch_a_store_within_its_limit(): void
    {
        $this->writeEntry('pagina', time() + 3600, 2048);

        $this->artisan('cache:prune-fallback', ['--store' => 'pages'])->assertSuccessful();

        $this->assertFileExists($this->entryPath('pagina'));
    }

    /**
     * A file cache entry is a 10 digit expiry timestamp followed by the
     * serialised payload.
     */
    private function writeEntry(string $name, int $expiresAt, int $bytes): void
    {
        File::put($this->entryPath($name), $expiresAt.str_repeat('x', $bytes));
    }

    private function entryPath(string $name): string
    {
        return $this->path.'/'.$name;
    }

    private function totalSize(): int
    {
        return array_sum(array_map('filesize', File::files($this->path)));
    }
}
