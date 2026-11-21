<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para as consultas das páginas públicas (vaga/artigo por slug, contagens
 * por país, currículo por slug). Só acrescenta índices; nunca toca em dados nem
 * remove colunas.
 *
 * A base de dados é partilhada com outro site e foi alterada à mão (country_id e
 * slug não aparecem nas migrations antigas), por isso esta migration:
 *  - ignora tabelas e colunas que não existam;
 *  - ignora um índice se já houver outro, com qualquer nome (por exemplo criado
 *    pelo site principal), que comece pelas mesmas colunas;
 *  - pode correr várias vezes sem efeito.
 *
 * Não corra "php artisan migrate" simples nesta base partilhada: aplicaria tudo
 * o que o Laravel considerar pendente. Corra só este ficheiro:
 *
 *   php artisan migrate --path=database/migrations/2026_11_20_120000_add_indexes_to_public_queries.php --force
 *
 * ou, sem artisan, o SQL equivalente (no phpMyAdmin; "Duplicate key name" só
 * quer dizer que o índice já existe):
 *
 *   ALTER TABLE `jobs`       ADD INDEX `jobs_country_id_slug_index` (`country_id`, `slug`);
 *   ALTER TABLE `articles`   ADD INDEX `articles_country_id_slug_index` (`country_id`, `slug`);
 *   ALTER TABLE `curriculos` ADD INDEX `curriculos_slug_index` (`slug`);
 */
return new class extends Migration
{
    /** Tamanho que cabe em qualquer MySQL (utf8mb4: 191 x 4 = 764 bytes, abaixo do limite antigo de 767). */
    private const PREFIX_LENGTH = 191;

    /** @var array<string, array<int, string>> tabela => colunas do índice */
    private array $wanted = [
        'jobs' => ['country_id', 'slug'],
        'articles' => ['country_id', 'slug'],
        'curriculos' => ['slug'],
    ];

    public function up(): void
    {
        foreach ($this->wanted as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                continue;
            }

            if ($this->hasIndexStartingWith($table, $columns)) {
                continue;
            }

            $this->addIndex($table, $columns);
        }
    }

    /**
     * Intencionalmente vazio: os índices são partilhados com o site principal e
     * podem ter sido criados por ele, por isso reverter aqui poderia apagar um
     * índice de que o outro site depende. Se for mesmo preciso, DROP INDEX à mão.
     */
    public function down(): void
    {
        //
    }

    private function addIndex(string $table, array $columns): void
    {
        $name = $table.'_'.implode('_', $columns).'_index';

        try {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        } catch (QueryException $e) {
            // Chave demasiado longa (slug varchar(255) em utf8mb4 num formato de linha antigo,
            // limite de 767 bytes): 1071 no MySQL e 1709 no MariaDB. Repete com um prefixo no
            // slug, que serve na mesma para igualdades.
            if (! in_array($e->errorInfo[1] ?? null, [1071, 1709], true) || Schema::getConnection()->getDriverName() !== 'mysql') {
                throw $e;
            }

            $quoted = array_map(
                fn (string $column) => $column === 'slug' ? '`slug`('.self::PREFIX_LENGTH.')' : "`{$column}`",
                $columns
            );

            Schema::getConnection()->statement(
                'ALTER TABLE `'.Schema::getConnection()->getTablePrefix().$table.'` ADD INDEX `'.$name.'` ('.implode(', ', $quoted).')'
            );
        }
    }

    /** Existe algum índice cujas primeiras colunas sejam exactamente estas? */
    private function hasIndexStartingWith(string $table, array $columns): bool
    {
        foreach ($this->indexes($table) as $indexColumns) {
            if (array_slice($indexColumns, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * Colunas de cada índice da tabela, por ordem.
     *
     * @return array<string, array<int, string>>
     */
    private function indexes(string $table): array
    {
        $connection = Schema::getConnection();
        $real = $connection->getTablePrefix().$table;
        $indexes = [];

        if ($connection->getDriverName() === 'sqlite') {
            foreach ($connection->select("PRAGMA index_list('{$real}')") as $index) {
                $indexes[$index->name] = array_map(
                    fn ($column) => $column->name,
                    $connection->select("PRAGMA index_info('{$index->name}')")
                );
            }

            return $indexes;
        }

        foreach ($connection->select("SHOW INDEX FROM `{$real}`") as $row) {
            $indexes[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
        }

        return array_map(function (array $columns) {
            ksort($columns);

            return array_values($columns);
        }, $indexes);
    }
};
