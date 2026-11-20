<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * As migrations do repositório não têm country_id nem slug (a base de dados de
 * produção foi alterada à mão), por isso os testes criam o esquema que o código
 * realmente lê, em SQLite em memória.
 */
trait CreatesPublicSchema
{
    protected function createPublicSchema(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('company');
            $table->string('province');
            $table->text('description');
            $table->string('email_or_link')->nullable();
            $table->string('photo')->default('job.jpg');
            $table->integer('country_id')->default(1);
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('description');
            $table->string('photo');
            $table->integer('country_id')->default(1);
            $table->timestamps();
        });

        Schema::create('category_jobs', function (Blueprint $table) {
            $table->integer('category_id');
            $table->integer('job_id');
        });

        Schema::create('curriculos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('author');
            $table->text('description');
            $table->string('link')->nullable();
            $table->string('photo');
            $table->timestamps();
        });

        Schema::create('social_media_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('job_id');
            $table->integer('post_status')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Cria vagas e artigos directamente na tabela (sem eventos do model), com
     * ids e datas previsíveis. Devolve nada: os testes lêem o que precisam.
     */
    protected function seedContent(int $jobs = 8, int $articles = 8): void
    {
        for ($i = 1; $i <= $jobs; $i++) {
            DB::table('jobs')->insert([
                'title' => "Vaga {$i}",
                'slug' => "vaga-{$i}",
                'company' => "Empresa {$i}",
                'province' => 'Luanda',
                'description' => "<p>Descrição longa da vaga {$i}</p>",
                'email_or_link' => 'rh@exemplo.ao',
                'photo' => 'images/jobs/exemplo.jpg',
                'country_id' => 1,
                'created_at' => now()->subDays($jobs - $i),
                'updated_at' => now()->subDays($jobs - $i),
            ]);
        }

        for ($i = 1; $i <= $articles; $i++) {
            DB::table('articles')->insert([
                'title' => "Notícia {$i}",
                'slug' => "noticia-{$i}",
                'description' => "<p>Texto da notícia {$i}</p>",
                'photo' => 'images/articles/default.jpg',
                'country_id' => 1,
                'created_at' => now()->subDays($articles - $i),
                'updated_at' => now()->subDays($articles - $i),
            ]);
        }

        DB::table('categories')->insert([
            ['name' => 'Saúde', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Administração', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('curriculos')->insert([
            'title' => 'Modelo de CV', 'slug' => 'modelo-de-cv', 'author' => 'Equipa',
            'description' => 'Um modelo simples', 'photo' => 'images/curriculos/cv.jpg',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Conta as consultas SQL executadas durante o callback. */
    protected function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $callback();

        return $count;
    }
}
