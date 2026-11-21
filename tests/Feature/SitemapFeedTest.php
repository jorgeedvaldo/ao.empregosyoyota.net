<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class SitemapFeedTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedContent(jobs: 12, articles: 9);
        // O sitemap e o feed nunca filtraram por país; têm de continuar assim.
        DB::table('jobs')->where('id', 3)->update(['country_id' => 2]);
    }

    public function test_sitemap_is_byte_identical_to_what_the_original_code_generated(): void
    {
        $expected = view('xml.sitemap', [
            'jobs' => Job::orderByRaw('id DESC')->paginate(500),
            'articles' => Article::orderByRaw('id DESC')->paginate(300),
        ])->render();

        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertSame($expected, $response->getContent());
        $this->assertStringContainsString('text/xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('/empregos/vaga-3<', $response->getContent());
    }

    public function test_feed_is_byte_identical_to_what_the_original_code_generated(): void
    {
        $expected = view('xml.feed', ['jobs' => Job::orderByRaw('id DESC')->paginate(10)])->render();

        $response = $this->get('/feed')->assertOk();

        $this->assertSame($expected, $response->getContent());
        $this->assertStringContainsString('text/xml', $response->headers->get('Content-Type'));
    }

    public function test_both_are_served_without_queries_once_warm(): void
    {
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/feed')->assertOk();

        $this->assertSame(0, $this->countQueries(function () {
            $this->get('/sitemap.xml')->assertOk();
            $this->get('/feed')->assertOk();
        }));
    }

    public function test_sitemap_does_not_load_descriptions(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/sitemap.xml')->assertOk();

        foreach (DB::getQueryLog() as $entry) {
            $this->assertStringNotContainsString('description', $entry['query']);
            $this->assertStringNotContainsString('*', $entry['query']);
        }
    }

    public function test_saving_a_job_refreshes_sitemap_and_feed(): void
    {
        $this->get('/sitemap.xml');
        $this->get('/feed');

        $job = Job::create([
            'title' => 'Vaga acabada de publicar', 'company' => 'Empresa', 'province' => 'Luanda',
            'description' => 'Texto', 'email_or_link' => 'rh@exemplo.ao', 'photo' => 'job.jpg',
        ]);

        $this->get('/sitemap.xml')->assertSee('/empregos/'.$job->slug, false);
        $this->get('/feed')->assertSee('/empregos/'.$job->slug, false);
    }

    public function test_saving_an_article_refreshes_the_sitemap_only(): void
    {
        $this->get('/sitemap.xml');
        $this->get('/feed');

        $article = Article::create(['title' => 'Notícia acabada de publicar', 'description' => 'Texto', 'photo' => 'a.jpg']);

        $this->get('/sitemap.xml')->assertSee('/articles/'.$article->slug, false);
        $this->assertSame(0, $this->countQueries(fn () => $this->get('/feed')->assertOk()));
    }

    public function test_deleting_a_job_removes_it_from_the_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertSee('/empregos/vaga-12<', false);

        Job::find(12)->delete();

        $this->get('/sitemap.xml')->assertDontSee('/empregos/vaga-12<', false);
    }

    public function test_pages_other_than_the_first_are_rendered_but_not_cached(): void
    {
        $this->get('/sitemap.xml?page=2')->assertOk();

        $this->assertGreaterThan(0, $this->countQueries(fn () => $this->get('/sitemap.xml?page=2')->assertOk()));
    }
}
