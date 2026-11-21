<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Job;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedContent(jobs: 70, articles: 40);
        // Um item de outro país não pode entrar nas listagens nem nas contagens.
        DB::table('jobs')->where('id', 35)->update(['country_id' => 2]);
        DB::table('articles')->where('id', 20)->update(['country_id' => 2]);
    }

    /**
     * @dataProvider pages
     */
    public function test_job_listing_matches_the_original_paginate(int $perPage, int $page): void
    {
        Paginator::currentPageResolver(fn () => $page);

        $original = Job::where('country_id', 1)->orderByRaw('id DESC')->paginate($perPage);
        $cached = Job::cachedPage($perPage);

        $this->assertSame($original->pluck('id')->all(), $cached->pluck('id')->all());
        $this->assertSame($original->total(), $cached->total());
        $this->assertSame($original->lastPage(), $cached->lastPage());
        $this->assertSame($original->currentPage(), $cached->currentPage());
        $this->assertSame($original->links()->toHtml(), $cached->links()->toHtml());
    }

    /**
     * @dataProvider pages
     */
    public function test_article_listing_matches_the_original_paginate(int $perPage, int $page): void
    {
        Paginator::currentPageResolver(fn () => $page);

        $original = Article::where('country_id', 1)->orderByRaw('id DESC')->paginate($perPage);
        $cached = Article::cachedPage($perPage);

        $this->assertSame($original->pluck('id')->all(), $cached->pluck('id')->all());
        $this->assertSame($original->total(), $cached->total());
        $this->assertSame($original->links()->toHtml(), $cached->links()->toHtml());
    }

    public static function pages(): array
    {
        return [
            'home, 1.ª página' => [15, 1],
            'home, 2.ª página' => [15, 2],
            'lista de 30, 1.ª' => [30, 1],
            'lista de 30, última' => [30, 3],
            'lista de 16, 2.ª' => [16, 2],
            'lista de 8, 1.ª' => [8, 1],
            'página inexistente' => [30, 99],
        ];
    }

    public function test_first_pages_are_served_without_queries_once_warm(): void
    {
        foreach (['/', '/empregos', '/articles'] as $uri) {
            $this->get($uri)->assertOk();
        }

        foreach (['/', '/empregos', '/articles'] as $uri) {
            $this->assertSame(0, $this->countQueries(fn () => $this->get($uri)->assertOk()), "{$uri} devia vir só da cache");
        }
    }

    public function test_following_pages_cost_a_single_light_query(): void
    {
        $this->get('/empregos')->assertOk(); // aquece contagem e categorias

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/empregos?page=2')->assertOk()->assertSee('Vaga');
        $log = DB::getQueryLog();

        $this->assertCount(1, $log);
        $this->assertStringContainsString('limit 30 offset 30', $log[0]['query']);
        $this->assertStringNotContainsString('"description"', $log[0]['query']);
        $this->assertStringNotContainsString('*', $log[0]['query']);
    }

    public function test_very_high_pages_do_not_query_or_cache_anything(): void
    {
        $this->get('/empregos')->assertOk();
        $this->get('/articles')->assertOk();

        $queries = $this->countQueries(function () {
            $this->get('/empregos?page=99999')->assertOk()->assertDontSee('Vaga 1<');
            $this->get('/articles?page=99999')->assertOk();
            $this->get('/?page=99999')->assertOk();
        });

        $this->assertSame(0, $queries);
    }

    public function test_page_links_keep_working_on_the_cached_first_page(): void
    {
        $html = $this->get('/empregos')->assertOk()->getContent();

        $this->assertStringContainsString('/empregos?page=2', $html);
    }

    public function test_listings_show_the_same_jobs_in_the_same_order_as_before(): void
    {
        $html = $this->get('/empregos')->assertOk()->getContent();

        preg_match_all('#empregos/(vaga-\d+)#', $html, $m);
        $slugs = array_values(array_unique($m[1]));

        $expected = DB::table('jobs')->where('country_id', 1)->orderByDesc('id')->limit(30)->pluck('slug')->all();
        $this->assertSame($expected, array_slice($slugs, 0, 30));
        $this->assertNotContains('vaga-35', $slugs);
    }
}
