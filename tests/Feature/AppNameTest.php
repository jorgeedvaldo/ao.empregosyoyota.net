<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class AppNameTest extends TestCase
{
    use CreatesPublicSchema;

    public function test_the_app_name_appears_in_the_title_and_social_tags(): void
    {
        $this->createPublicSchema();
        $this->seedContent();
        config(['app.name' => 'Nome definido so na config']);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Início - Nome definido so na config</title>', false)
            ->assertSee('property="og:title" content="Início - Nome definido so na config"', false)
            ->assertSee('name="twitter:text:title" content="Início - Nome definido so na config"', false);
    }

    /**
     * Com "php artisan config:cache" o Laravel deixa de ler o .env e env() fora
     * de config/ devolve null (foi assim que o nome da aplicação desapareceu do
     * título). Fora de config/ lê-se sempre com config().
     */
    public function test_env_is_only_called_inside_the_config_directory(): void
    {
        $offenders = [];

        foreach (['app', 'resources', 'routes', 'database'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if (preg_match('/\benv\s*\(/', $file->getContents())) {
                    $offenders[] = $directory.'/'.$file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'Use config() em vez de env() fora de config/.');
    }
}
