<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SimulatesMysqlFailures;
use Tests\TestCase;

/**
 * End to end behaviour of the public pages while MySQL refuses connections
 * with error 1226 ('max_user_connections').
 */
class PublicPagesFallbackTest extends TestCase
{
    use SimulatesMysqlFailures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedPublicContent();
    }

    public function test_pages_work_normally_while_mysql_is_available(): void
    {
        $this->get('/')->assertOk()->assertSee('Vaga 8');
        $this->get('/empregos')->assertOk()->assertSee('Vaga 8');
        $this->get('/articles')->assertOk()->assertSee('Notícia 8');
    }

    public function test_a_cached_page_is_served_without_touching_the_database(): void
    {
        $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'miss');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'hit');

        $this->assertSame(0, $queries, 'A página em cache não devia ter consultado o MySQL.');
    }

    public function test_the_home_page_survives_error_1226(): void
    {
        $this->get('/')->assertOk();

        $this->breakDatabase(1226);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Vaga 8')
            ->assertDontSee('max_user_connections')
            ->assertDontSee('SQLSTATE');
    }

    public function test_a_job_page_survives_error_1226_without_the_html_cache(): void
    {
        // Sem cache de HTML, a página tem de ser reconstruída a partir da
        // cópia de emergência dos dados.
        $this->reconfigureFallback(['fallback.pages.enabled' => false]);

        $slug = Job::orderByRaw('id DESC')->first()->slug;

        $this->get('/empregos/'.$slug)->assertOk()->assertSee('Vaga 8');

        // As cópias normais expiram (10 a 15 min); as de emergência ficam.
        $this->travel(20)->minutes();

        $this->breakDatabase(1226);

        $this->get('/empregos/'.$slug)
            ->assertOk()
            ->assertSee('Vaga 8')
            ->assertDontSee('SQLSTATE');
    }

    public function test_the_last_page_served_is_used_when_even_the_data_cache_is_gone(): void
    {
        // Retenção curta nos dados para que, passado o tempo, só sobre a
        // última página guardada (a cópia "stale" do HTML, 2 dias).
        $this->reconfigureFallback([
            'fallback.profiles.listing' => ['ttl' => 300, 'emergency' => 600],
            'fallback.profiles.shared' => ['ttl' => 300, 'emergency' => 600],
        ]);

        $this->get('/')->assertOk();

        $this->travel(20)->minutes();

        $this->breakDatabase(1226);

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Page-Cache', 'stale')
            ->assertSee('Vaga 8')
            ->assertDontSee('SQLSTATE');
    }

    public function test_it_serves_the_maintenance_page_when_nothing_is_cached(): void
    {
        $this->breakDatabase(1226);

        $response = $this->get('/');

        $response->assertStatus(503)
            ->assertHeader('Retry-After', (string) config('fallback.retry_after'))
            ->assertSee('Serviço temporariamente indisponível')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('max_user_connections')
            ->assertDontSee('yqqnyhrgnt_edv');
    }

    public function test_pages_recover_automatically_once_mysql_returns(): void
    {
        $this->breakDatabase(1226);

        $this->get('/')->assertStatus(503);

        $this->repairDatabase();

        // Passado o intervalo do disjuntor, a recuperação é tentada.
        $this->travel(11)->seconds();

        $this->get('/')->assertOk()->assertSee('Vaga 8');
    }

    public function test_concurrent_requests_do_not_each_open_a_connection(): void
    {
        $this->breakDatabase(1226);

        for ($i = 0; $i < 10; $i++) {
            $this->get('/')->assertStatus(503);
        }

        // Três falhas abrem o disjuntor; a partir daí só a tentativa de
        // recuperação (uma por intervalo) chega a ligar ao MySQL.
        $this->assertLessThanOrEqual(
            4,
            $this->connectionAttempts,
            'O disjuntor devia ter impedido que cada pedido tentasse ligar ao MySQL.'
        );
    }

    public function test_pages_for_authenticated_visitors_are_never_cached(): void
    {
        $user = User::create([
            'name' => 'Editor',
            'email' => 'editor@exemplo.ao',
            'password' => bcrypt('segredo'),
        ]);

        $this->actingAs($user)->get('/')->assertOk()->assertHeaderMissing('X-Page-Cache');

        Auth::logout();

        // Nada do pedido autenticado ficou na cache pública: o visitante
        // seguinte volta a gerar a página.
        $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    }

    public function test_a_write_never_reports_a_false_success(): void
    {
        $this->breakDatabase(1226);

        $response = $this->postJson('/api/job/create', [
            'title' => 'Vaga nova',
            'company' => 'Empresa',
            'province' => 'Luanda',
            'description' => 'Descrição',
            'country_id' => 1,
        ]);

        $response->assertStatus(503);

        $this->repairDatabase();

        $this->assertSame(0, Draft::count(), 'Nenhum registo devia ter sido criado.');
    }

    public function test_search_does_not_expose_errors_when_mysql_is_down(): void
    {
        $this->breakDatabase(1226);

        $this->get('/pesquisar?query=contabilista')
            ->assertStatus(503)
            ->assertDontSee('SQLSTATE');
    }
}
