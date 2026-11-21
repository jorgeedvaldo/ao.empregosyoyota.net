<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedContent(jobs: 8, articles: 8);
    }

    public function test_records_that_do_not_exist_are_a_404(): void
    {
        foreach ([
            '/empregos/nao-existe', '/jobs/9999', '/articles/nao-existe', '/articles/9999',
            '/modelos-de-curriculos/nao-existe', '/categories/9999',
        ] as $url) {
            $this->get($url)->assertNotFound()->assertSee('Oops! Página Não Encontrada');
        }
    }

    public function test_a_missing_curriculo_is_a_404_and_no_longer_a_500(): void
    {
        // CurriculoController não importava Exception, por isso o catch nunca apanhava nada.
        $this->get('/modelos-de-curriculos/nao-existe')->assertNotFound();
        $this->get('/modelos-de-curriculos/modelo-de-cv')->assertOk()->assertSee('Modelo de CV');
    }

    public function test_jobs_and_articles_from_other_countries_stay_hidden(): void
    {
        DB::table('jobs')->where('id', 3)->update(['country_id' => 2]);
        DB::table('articles')->where('id', 3)->update(['country_id' => 2]);

        $this->get('/empregos/vaga-3')->assertNotFound();
        $this->get('/jobs/3')->assertNotFound();
        $this->get('/articles/noticia-3')->assertNotFound();
        $this->get('/articles/3')->assertNotFound();
    }

    public function test_a_real_database_error_is_no_longer_disguised_as_a_404(): void
    {
        Schema::drop('jobs');

        $this->get('/empregos/vaga-1')->assertStatus(500);
        $this->get('/jobs/1')->assertStatus(500);
    }

    public function test_real_errors_on_articles_and_curriculos_surface_as_500(): void
    {
        Schema::drop('articles');
        Schema::drop('curriculos');

        $this->get('/articles/noticia-1')->assertStatus(500);
        $this->get('/articles/1')->assertStatus(500);
        $this->get('/modelos-de-curriculos/modelo-de-cv')->assertStatus(500);
    }

    public function test_the_ocr_page_no_longer_hides_a_database_error(): void
    {
        $this->get('/onlineocr')->assertOk();

        Schema::drop('jobs');
        \Illuminate\Support\Facades\Cache::flush();

        $this->get('/onlineocr')->assertStatus(500);
    }
}
