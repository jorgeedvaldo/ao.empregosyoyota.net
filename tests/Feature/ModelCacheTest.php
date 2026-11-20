<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class ModelCacheTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedContent(jobs: 60, articles: 60);
    }

    public function test_latest_jobs_are_capped_ordered_and_light(): void
    {
        $latest = Job::getCachedLatest();

        $this->assertCount(Job::LATEST_LIMIT, $latest);
        $this->assertSame(60, $latest->first()->id, 'A vaga mais recente vem primeiro.');
        $this->assertSame(11, $latest->last()->id);
        $this->assertArrayNotHasKey('description', $latest->first()->getAttributes());
        $this->assertSame(
            ['id', 'title', 'slug', 'company', 'province', 'created_at'],
            array_keys($latest->first()->getAttributes())
        );
    }

    public function test_only_country_one_is_listed_but_the_sidebar_list_for_articles_ignores_country(): void
    {
        DB::table('jobs')->where('id', 60)->update(['country_id' => 2]);
        DB::table('articles')->where('id', 60)->update(['country_id' => 2]);

        $this->assertSame(59, Job::getCachedLatest()->first()->id);
        $this->assertSame(59, Article::getCachedLatest()->first()->id);
        $this->assertSame(60, Article::getCachedLatestAnyCountry()->first()->id);
    }

    public function test_a_second_read_does_not_touch_the_database(): void
    {
        Job::getCachedLatest();
        Job::getCachedCount();
        Article::getCachedLatest();
        Article::getCachedLatestAnyCountry();
        Article::getCachedCount();
        Category::getCachedAll();

        $queries = $this->countQueries(function () {
            Job::getCachedLatest();
            Job::getCachedCount();
            Article::getCachedLatest();
            Article::getCachedLatestAnyCountry();
            Article::getCachedCount();
            Category::getCachedAll();
        });

        $this->assertSame(0, $queries);
    }

    public function test_counts_are_correct(): void
    {
        $this->assertSame(60, Job::getCachedCount());
        $this->assertSame(60, Article::getCachedCount());
    }

    public function test_saving_a_job_through_eloquent_refreshes_the_cache(): void
    {
        $this->assertSame(60, Job::getCachedLatest()->first()->id);
        $this->assertSame(60, Job::getCachedCount());

        $job = Job::create([
            'title' => 'Vaga nova', 'company' => 'Empresa', 'province' => 'Luanda',
            'description' => 'Texto', 'email_or_link' => 'rh@exemplo.ao', 'photo' => 'job.jpg',
        ]);

        $this->assertSame($job->id, Job::getCachedLatest()->first()->id);
        $this->assertSame(61, Job::getCachedCount());
        $this->assertSame('vaga-nova', Job::getCachedLatest()->first()->slug);
    }

    public function test_updating_and_deleting_a_job_refreshes_the_cache(): void
    {
        Job::getCachedLatest();
        Job::getCachedCount();

        Job::find(60)->update(['title' => 'Título alterado']);
        $this->assertSame('Título alterado', Job::getCachedLatest()->first()->title);

        Job::find(60)->delete();
        $this->assertSame(59, Job::getCachedLatest()->first()->id);
        $this->assertSame(59, Job::getCachedCount());
    }

    public function test_saving_an_article_refreshes_every_article_list(): void
    {
        Article::getCachedLatest();
        Article::getCachedLatestAnyCountry();
        Article::getCachedCount();

        $article = Article::create(['title' => 'Notícia nova', 'description' => 'Texto', 'photo' => 'a.jpg']);

        $this->assertSame($article->id, Article::getCachedLatest()->first()->id);
        $this->assertSame($article->id, Article::getCachedLatestAnyCountry()->first()->id);
        $this->assertSame(61, Article::getCachedCount());

        $article->delete();

        $this->assertSame(60, Article::getCachedLatest()->first()->id);
        $this->assertSame(60, Article::getCachedCount());
    }

    public function test_categories_are_alphabetical_and_refresh_on_save(): void
    {
        $this->assertSame(['Administração', 'Saúde'], Category::getCachedAll()->pluck('name')->all());

        Category::create(['name' => 'Educação']);

        $this->assertSame(['Administração', 'Educação', 'Saúde'], Category::getCachedAll()->pluck('name')->all());
    }
}
