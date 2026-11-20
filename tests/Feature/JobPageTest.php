<?php

namespace Tests\Feature;

use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class JobPageTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedContent(jobs: 8, articles: 8);
    }

    public function test_a_job_page_links_to_the_next_older_job_twice(): void
    {
        $html = $this->get('/empregos/vaga-8')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'VER PROXIMA VAGA'));
        $this->assertSame(2, substr_count($html, 'https://ao.empregosyoyota.net/empregos/vaga-7?utm_campaign=next_button&utm_source=next_button'));
    }

    public function test_the_oldest_job_loads_without_a_next_button(): void
    {
        // Antes dava erro 500: a view usava $previousJob->slug com $previousJob nulo.
        $this->get('/empregos/vaga-1')
            ->assertOk()
            ->assertSee('Vaga 1')
            ->assertDontSee('VER PROXIMA VAGA');
    }

    public function test_the_same_holds_when_the_job_is_opened_by_id(): void
    {
        $this->get('/jobs/1')->assertOk()->assertDontSee('VER PROXIMA VAGA');
        $this->get('/jobs/8')->assertOk()->assertSee('VER PROXIMA VAGA');
    }

    public function test_next_older_skips_jobs_from_other_countries(): void
    {
        DB::table('jobs')->where('id', 7)->update(['country_id' => 2]);

        $this->assertSame('vaga-6', Job::nextOlder(Job::find(8))->slug);
    }

    public function test_next_older_is_null_for_the_oldest_job_and_does_one_light_query(): void
    {
        $job = Job::find(1);

        $queries = $this->countQueries(function () use ($job, &$next) {
            $next = Job::nextOlder($job);
        });

        $this->assertNull($next);
        $this->assertSame(1, $queries);
    }

    public function test_next_older_loads_only_what_the_button_needs(): void
    {
        $next = Job::nextOlder(Job::find(8));

        $this->assertSame(['id', 'slug'], array_keys($next->getAttributes()));
    }
}
