<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class IndexMigrationTest extends TestCase
{
    use CreatesPublicSchema;

    private const FILE = 'database/migrations/2026_11_20_120000_add_indexes_to_public_queries.php';

    private function migrate(): void
    {
        (require base_path(self::FILE))->up();
    }

    /** @return array<int, string> nomes dos índices criados à mão (sem os automáticos do SQLite) */
    private function indexNames(string $table): array
    {
        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->reject(fn ($index) => str_starts_with($index->name, 'sqlite_autoindex'))
            ->pluck('name')->sort()->values()->all();
    }

    public function test_it_adds_the_three_indexes(): void
    {
        $this->createPublicSchema();

        $this->migrate();

        $this->assertSame(['jobs_country_id_slug_index'], $this->indexNames('jobs'));
        $this->assertSame(['articles_country_id_slug_index'], $this->indexNames('articles'));
        $this->assertSame(['curriculos_slug_index'], $this->indexNames('curriculos'));
    }

    public function test_it_can_run_twice_without_creating_duplicates_or_touching_data(): void
    {
        $this->createPublicSchema();
        $this->seedContent(jobs: 5, articles: 5);

        $this->migrate();
        $this->migrate();

        $this->assertSame(['jobs_country_id_slug_index'], $this->indexNames('jobs'));
        $this->assertSame(5, DB::table('jobs')->count());
        $this->assertSame(5, DB::table('articles')->count());
        $this->assertSame('vaga-3', DB::table('jobs')->where('id', 3)->value('slug'));
    }

    public function test_it_leaves_alone_a_table_that_already_has_an_equivalent_index_under_another_name(): void
    {
        $this->createPublicSchema();
        // Como o site principal poderia ter criado, com outro nome e mais uma coluna.
        Schema::table('jobs', fn (Blueprint $table) => $table->index(['country_id', 'slug', 'id'], 'idx_do_site_principal'));

        $this->migrate();

        $this->assertSame(['idx_do_site_principal'], $this->indexNames('jobs'));
        $this->assertSame(['articles_country_id_slug_index'], $this->indexNames('articles'));
    }

    public function test_it_skips_tables_and_columns_that_do_not_exist(): void
    {
        $this->createPublicSchema();
        Schema::drop('curriculos');
        Schema::table('articles', fn (Blueprint $table) => $table->dropColumn('slug'));

        $this->migrate();

        $this->assertSame(['jobs_country_id_slug_index'], $this->indexNames('jobs'));
        $this->assertSame([], $this->indexNames('articles'));
        $this->assertFalse(Schema::hasTable('curriculos'));
    }

    public function test_rolling_back_never_drops_an_index_it_may_not_have_created(): void
    {
        $this->createPublicSchema();
        $this->migrate();

        (require base_path(self::FILE))->down();

        $this->assertSame(['jobs_country_id_slug_index'], $this->indexNames('jobs'));
    }
}
