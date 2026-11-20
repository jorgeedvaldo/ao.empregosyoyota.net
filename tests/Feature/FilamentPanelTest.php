<?php

namespace Tests\Feature;

use App\Filament\Resources\JobResource\Pages\CreateJob;
use App\Filament\Resources\JobResource\Pages\EditJob;
use App\Models\Job;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

/**
 * O painel Filament grava pelos mesmos models: as listas e o sitemap em cache
 * têm de reflectir o que se grava lá, sem esperar pela validade.
 */
class FilamentPanelTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedContent(jobs: 8, articles: 8);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // O campo de foto do Filament só guarda o valor se o ficheiro existir no disco.
        Storage::fake('public');
        Storage::disk('public')->put('images/jobs/default.jpg', 'jpg');
        Storage::disk('public')->put('images/jobs/exemplo.jpg', 'jpg');

        $this->app['config']->set('app.env', 'local');
        $this->actingAs(User::forceCreate(['name' => 'Editor', 'email' => 'editor@exemplo.ao', 'password' => bcrypt('x')]));
    }

    public function test_a_job_created_in_the_panel_shows_up_on_the_public_pages_straight_away(): void
    {
        // Aquece tudo o que fica em cache.
        $this->get('/empregos')->assertOk()->assertDontSee('Vaga criada no painel');
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/feed')->assertOk();

        Livewire::test(CreateJob::class)
            ->set('data.title', 'Vaga criada no painel')
            ->set('data.company', 'Empresa Painel')
            ->set('data.province', 'Luanda')
            ->set('data.description', '<p>Texto</p>')
            ->set('data.email_or_link', 'rh@exemplo.ao')
            ->call('create');

        $job = Job::where('title', 'Vaga criada no painel')->first();
        $this->assertNotNull($job, 'A vaga devia ter sido gravada pelo painel.');

        $this->get('/empregos')->assertOk()->assertSee('Vaga criada no painel');
        $this->get('/')->assertOk()->assertSee('Vaga criada no painel');
        $this->get('/sitemap.xml')->assertSee('/empregos/'.$job->slug, false);
        $this->get('/feed')->assertSee('/empregos/'.$job->slug, false);
        $this->get('/empregos/'.$job->slug)->assertOk()->assertSee('Vaga criada no painel');
    }

    public function test_editing_a_job_in_the_panel_refreshes_the_lists(): void
    {
        $this->get('/empregos')->assertOk()->assertSee('Vaga 8');

        Livewire::test(EditJob::class, ['record' => 8])
            ->set('data.title', 'Título editado no painel')
            ->call('save');

        $this->get('/empregos')->assertOk()->assertSee('Título editado no painel')->assertDontSee('<b>Vaga 8</b>', false);
    }
}
