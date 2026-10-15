<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns the public pages filter and sort by. Fewer full
 * table scans means shorter queries, which means connections are returned to
 * the pool before the 'max_user_connections' limit is reached.
 *
 * Written defensively: the repository's migrations predate columns that exist
 * in production (country_id, slug), so every index is skipped unless its table
 * and columns are really there, and skipped again if it already exists. Safe to
 * run more than once.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'jobs' => [
            'jobs_country_id_id_index' => ['country_id', 'id'],
            'jobs_slug_index' => ['slug'],
        ],
        'articles' => [
            'articles_country_id_id_index' => ['country_id', 'id'],
            'articles_slug_index' => ['slug'],
        ],
        'curriculos' => [
            'curriculos_slug_index' => ['slug'],
        ],
        'category_jobs' => [
            'category_jobs_job_id_index' => ['job_id'],
            'category_jobs_category_id_index' => ['category_id'],
        ],
    ];

    public function up(): void
    {
        $this->eachIndex(function (string $table, string $name, array $columns) {
            if ($this->indexExists($table, $name)) {
                return;
            }

            try {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            } catch (Throwable $e) {
                // Most likely an equivalent index already exists under another
                // name; never block a deploy over an optimisation.
            }
        });
    }

    public function down(): void
    {
        $this->eachIndex(function (string $table, string $name) {
            if (! $this->indexExists($table, $name)) {
                return;
            }

            try {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            } catch (Throwable $e) {
                //
            }
        });
    }

    private function eachIndex(callable $callback): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if (Schema::hasColumns($table, $columns)) {
                    $callback($table, $name, $columns);
                }
            }
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', $connection->getDatabaseName())
            ->where('table_name', $connection->getTablePrefix().$table)
            ->where('index_name', $name)
            ->exists();
    }
};
