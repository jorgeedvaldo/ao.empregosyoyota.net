<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
    }

    public function test_jobs_all_keeps_the_original_json_format_when_the_table_fits_one_window(): void
    {
        $this->seedContent(jobs: 150, articles: 5);

        $expected = response()->json(Job::all())->getContent();

        $this->assertSame($expected, $this->get('/api/jobs/all')->assertOk()->getContent());
    }

    public function test_jobs_all_returns_the_newest_window_in_ascending_order(): void
    {
        $this->seedContent(jobs: 260, articles: 5);

        $ids = collect($this->get('/api/jobs/all')->assertOk()->json())->pluck('id');

        $this->assertCount(200, $ids);
        $this->assertSame(61, $ids->first());
        $this->assertSame(260, $ids->last());
        $this->assertSame($ids->sort()->values()->all(), $ids->all());
    }

    public function test_jobs_all_can_page_back(): void
    {
        $this->seedContent(jobs: 260, articles: 5);

        $second = collect($this->get('/api/jobs/all?limit=50&page=2')->assertOk()->json())->pluck('id');

        $this->assertSame(161, $second->first());
        $this->assertSame(210, $second->last());
    }

    public function test_jobs_all_limit_is_capped_at_500(): void
    {
        $this->seedContent(jobs: 520, articles: 5);

        $ids = collect($this->get('/api/jobs/all?limit=100000')->assertOk()->json())->pluck('id');

        $this->assertCount(500, $ids);
        $this->assertSame(21, $ids->first());
        $this->assertSame(520, $ids->last());
    }

    public function test_jobs_listing_keeps_its_envelope_and_pages_of_50(): void
    {
        $this->seedContent(jobs: 120, articles: 5);
        DB::table('jobs')->where('id', 120)->update(['country_id' => 2]);
        DB::table('categories')->insert(['name' => 'Extra', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('category_jobs')->insert(['category_id' => 1, 'job_id' => 119]);

        $json = $this->get('/api/jobs')->assertOk()->json();

        $this->assertSame(['message', 'data', 'license'], array_keys($json));
        $this->assertSame('Operation performed successfully.', $json['message']);
        $this->assertSame(50, $json['data']['per_page']);
        $this->assertSame(119, $json['data']['total']);
        $this->assertCount(50, $json['data']['data']);
        $this->assertArrayHasKey('categories', $json['data']['data'][0]);
        $this->assertSame(1, $json['data']['data'][0]['categories'][0]['id'] ?? null);

        $this->assertCount(19, $this->get('/api/jobs?page=3')->assertOk()->json('data.data'));
    }

    public function test_job_by_id_is_identical_to_the_original_response(): void
    {
        $this->seedContent(jobs: 5, articles: 5);

        $expected = response()->json([
            'message' => 'Operation performed successfully.',
            'data' => Job::with('categories')->where('id', 3)->get(),
            'license' => 'This API was developed by Edivaldo Jorge (https://github.com/jorgeedvaldo)',
        ])->getContent();

        $this->assertSame($expected, $this->get('/api/jobs/3')->assertOk()->getContent());
    }

    public function test_get_responses_are_cached_and_a_job_save_refreshes_its_own_entry(): void
    {
        $this->seedContent(jobs: 5, articles: 5);

        $this->get('/api/jobs')->assertOk();
        $this->get('/api/jobs/all')->assertOk();
        $this->get('/api/jobs/3')->assertOk();

        $this->assertSame(0, $this->countQueries(function () {
            $this->get('/api/jobs')->assertOk();
            $this->get('/api/jobs/all')->assertOk();
            $this->get('/api/jobs/3')->assertOk();
        }));

        Job::find(3)->update(['title' => 'Título novo']);

        $this->assertSame('Título novo', $this->get('/api/jobs/3')->json('data.0.title'));
    }

    public function test_unknown_ids_are_not_cached(): void
    {
        $this->seedContent(jobs: 5, articles: 5);

        $this->assertSame([], $this->get('/api/jobs/999')->assertOk()->json('data'));
        $this->assertGreaterThan(0, $this->countQueries(fn () => $this->get('/api/jobs/999')->assertOk()));

        $this->get('/api/jobs/abc')->assertOk();
    }

    public function test_writes_are_untouched_and_category_links_refresh_category_pages(): void
    {
        $this->seedContent(jobs: 5, articles: 5);

        $this->postJson('/api/job/create', [
            'title' => 'Rascunho', 'company' => 'Empresa', 'province' => 'Luanda',
            'description' => 'Texto', 'country_id' => 1,
        ])->assertCreated();
        $this->assertSame(1, Draft::count());

        $this->get('/categories/1')->assertOk()->assertDontSee('vaga-2');

        $this->postJson('/api/category_job/create', ['category_id' => 1, 'job_id' => 2])->assertCreated();

        $this->get('/categories/1')->assertOk()->assertSee('vaga-2');
    }
}
