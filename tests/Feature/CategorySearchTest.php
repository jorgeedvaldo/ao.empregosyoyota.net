<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class CategorySearchTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
    }

    private function jobsOnPage(string $uri): array
    {
        $html = $this->get($uri)->assertOk()->getContent();
        preg_match_all('#empregos/(vaga-\d+)#', $html, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_category_page_lists_the_newest_jobs_of_that_category_only(): void
    {
        $this->seedContent(jobs: 10, articles: 6);
        DB::table('category_jobs')->insert([
            ['category_id' => 1, 'job_id' => 2], ['category_id' => 1, 'job_id' => 5],
            ['category_id' => 1, 'job_id' => 9], ['category_id' => 1, 'job_id' => 7],
            ['category_id' => 2, 'job_id' => 3],
        ]);
        DB::table('jobs')->where('id', 7)->update(['country_id' => 2]);

        $slugs = $this->jobsOnPage('/categories/1');

        $this->assertSame(['vaga-9', 'vaga-5', 'vaga-2'], $slugs);
    }

    public function test_category_page_is_capped_to_the_100_newest_jobs(): void
    {
        $this->seedContent(jobs: 130, articles: 6);
        foreach (range(1, 130) as $id) {
            DB::table('category_jobs')->insert(['category_id' => 1, 'job_id' => $id]);
        }

        $slugs = $this->jobsOnPage('/categories/1');

        $this->assertCount(100, $slugs);
        $this->assertSame('vaga-130', $slugs[0]);
        $this->assertSame('vaga-31', $slugs[99]);
    }

    public function test_category_page_does_not_load_descriptions(): void
    {
        $this->seedContent(jobs: 10, articles: 6);
        DB::table('category_jobs')->insert(['category_id' => 1, 'job_id' => 4]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/categories/1')->assertOk();

        foreach (DB::getQueryLog() as $entry) {
            $this->assertStringNotContainsString('"description"', $entry['query']);
            $this->assertStringNotContainsString('jobs".*', $entry['query']);
        }
    }

    public function test_unknown_category_is_a_404_without_touching_the_database_once_warm(): void
    {
        $this->seedContent(jobs: 10, articles: 6);
        $this->get('/categories/1')->assertOk(); // aquece a lista de categorias

        $queries = $this->countQueries(fn () => $this->get('/categories/999')->assertNotFound());

        $this->assertSame(0, $queries);
    }

    public function test_category_page_is_cached_and_refreshed_when_a_job_is_saved(): void
    {
        $this->seedContent(jobs: 10, articles: 6);
        DB::table('category_jobs')->insert(['category_id' => 1, 'job_id' => 4]);

        $this->assertSame(['vaga-4'], $this->jobsOnPage('/categories/1'));
        $this->assertSame(0, $this->countQueries(fn () => $this->get('/categories/1')->assertOk()));

        // Gravar uma vaga (painel) esquece a cache das categorias.
        DB::table('category_jobs')->insert(['category_id' => 1, 'job_id' => 6]);
        Job::find(6)->update(['title' => 'Vaga 6 revista']);

        $this->assertSame(['vaga-6', 'vaga-4'], $this->jobsOnPage('/categories/1'));
    }

    public function test_new_categories_show_up_after_saving(): void
    {
        $this->seedContent(jobs: 10, articles: 6);
        $this->get('/empregos')->assertOk()->assertDontSee('Educação');

        Category::create(['name' => 'Educação']);

        $this->get('/empregos')->assertOk()->assertSee('Educação');
    }

    public function test_search_only_returns_country_one_even_when_the_text_matches_elsewhere(): void
    {
        $this->seedContent(jobs: 10, articles: 6);
        DB::table('jobs')->where('id', 3)->update(['country_id' => 2, 'title' => 'Analista de dados']);
        DB::table('jobs')->where('id', 4)->update(['title' => 'Analista financeiro']);
        DB::table('jobs')->where('id', 5)->update(['description' => '<p>procuramos analista júnior</p>']);

        $slugs = $this->jobsOnPage('/pesquisar?query=analista');

        // 5 e 4, do mais recente para o mais antigo; a 3 é de outro país.
        $this->assertSame(['vaga-5', 'vaga-4'], $slugs);
    }

    public function test_search_is_case_insensitive_and_shares_one_cache_entry(): void
    {
        $this->seedContent(jobs: 10, articles: 6);
        DB::table('jobs')->where('id', 4)->update(['title' => 'Analista financeiro']);

        $this->get('/pesquisar?query=analista')->assertOk();

        $queries = $this->countQueries(fn () => $this->get('/pesquisar?query=ANALISTA')->assertOk());

        $this->assertSame(0, $queries);
    }

    public function test_search_text_is_limited_to_60_characters_and_escaped(): void
    {
        $this->seedContent(jobs: 10, articles: 6);
        $long = str_repeat('a', 59).'<script>alert(1)</script>';

        $html = $this->get('/pesquisar?query='.urlencode($long))->assertOk()->getContent();

        $this->assertStringContainsString(str_repeat('a', 59).'&lt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString(str_repeat('a', 59).'&lt;s', $html);
    }

    public function test_search_with_no_text_or_with_an_array_does_not_break(): void
    {
        $this->seedContent(jobs: 10, articles: 6);

        $this->get('/pesquisar')->assertOk()->assertDontSee('Resultados da pesquisa');
        $this->get('/pesquisar?query[]=x')->assertOk();
    }

    public function test_search_pagination_matches_the_original_total_and_keeps_the_query_string(): void
    {
        $this->seedContent(jobs: 70, articles: 6);

        $html = $this->get('/pesquisar?query=vaga')->assertOk()->getContent();

        $this->assertStringContainsString('query=vaga&amp;page=2', $html);
        $this->assertCount(30, $this->jobsOnPage('/pesquisar?query=vaga'));
        $this->assertCount(30, $this->jobsOnPage('/pesquisar?query=vaga&page=2'));
        $this->assertCount(10, $this->jobsOnPage('/pesquisar?query=vaga&page=3'));
    }

    public function test_deep_search_pages_are_not_cached(): void
    {
        $this->seedContent(jobs: 10, articles: 6);

        $this->get('/pesquisar?query=vaga&page=9')->assertOk();

        $this->assertGreaterThan(0, $this->countQueries(fn () => $this->get('/pesquisar?query=vaga&page=9')->assertOk()));
    }
}
