<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

/**
 * Meta do projecto: de 0 a 2 consultas por página pública com a cache quente.
 */
class QueryBudgetTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedContent(jobs: 60, articles: 40);
        DB::table('category_jobs')->insert([['category_id' => 1, 'job_id' => 60], ['category_id' => 2, 'job_id' => 59]]);
    }

    /** @return array<int, string> as consultas feitas por um pedido, depois de o ter aquecido */
    private function warmQueries(string $url): array
    {
        $this->get($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();

        return array_column(DB::getQueryLog(), 'query');
    }

    public function test_pages_served_from_cache_make_no_queries(): void
    {
        foreach (['/', '/empregos', '/articles', '/onlineocr', '/categories/1', '/sitemap.xml', '/feed', '/pesquisar?query=vaga', '/api/jobs', '/api/jobs/all'] as $url) {
            $this->assertSame([], $this->warmQueries($url), "{$url} devia vir só da cache");
        }
    }

    public function test_a_job_page_makes_at_most_two_light_queries_and_never_loads_categories(): void
    {
        $queries = $this->warmQueries('/empregos/vaga-60');

        $this->assertLessThanOrEqual(2, count($queries), implode("\n", $queries));

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('category_jobs', $sql);
            $this->assertStringNotContainsString('categories', $sql);
        }
    }

    public function test_an_article_page_makes_a_single_query(): void
    {
        $this->assertCount(1, $this->warmQueries('/articles/noticia-40'));
    }

    public function test_the_job_page_still_has_its_next_button_after_removing_the_eager_load(): void
    {
        $this->get('/empregos/vaga-60')->assertOk()->assertSee('/empregos/vaga-59?utm_campaign=next_button', false);
    }

    public function test_curriculos_list_keeps_newest_first(): void
    {
        DB::table('curriculos')->insert([
            'title' => 'Mais recente', 'slug' => 'mais-recente', 'author' => 'Equipa', 'description' => 'x',
            'photo' => 'cv.jpg', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get('/modelos-de-curriculos')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'modelo-de-cv'), strpos($html, 'mais-recente'));
    }
}
