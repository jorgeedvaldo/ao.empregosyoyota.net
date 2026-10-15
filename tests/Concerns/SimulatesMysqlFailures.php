<?php

namespace Tests\Concerns;

use App\Models\Article;
use App\Models\Category;
use App\Models\Curriculo;
use App\Models\Job;
use App\Services\DatabaseFallbackCache;
use App\Services\PublicPageCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;

/**
 * Test scaffolding for the MySQL fallback.
 *
 * The repository's migrations predate columns that exist in production
 * (country_id, slug), so the tables are built here to match what the
 * controllers and views actually read, instead of running migrate.
 */
trait SimulatesMysqlFailures
{
    private ?PDO $realPdo = null;

    /**
     * How many times the application tried to open a connection since
     * breakDatabase() was called.
     */
    protected int $connectionAttempts = 0;

    protected function createPublicSchema(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('company');
            $table->string('province');
            $table->text('description');
            $table->string('email_or_link')->nullable();
            $table->string('photo')->default('job.jpg');
            $table->integer('country_id')->default(1);
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('description');
            $table->string('photo');
            $table->integer('country_id')->default(1);
            $table->timestamps();
        });

        Schema::create('category_jobs', function (Blueprint $table) {
            $table->integer('category_id');
            $table->integer('job_id');
        });

        Schema::create('curriculos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('author');
            $table->text('description');
            $table->string('link')->nullable();
            $table->string('photo');
            $table->timestamps();
        });

        Schema::create('social_media_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('job_id');
            $table->integer('post_status')->default(0);
            $table->timestamps();
        });

        Schema::create('drafts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('company');
            $table->string('province');
            $table->text('description');
            $table->string('email_or_link')->nullable();
            $table->string('photo')->default('job.jpg');
            $table->integer('country_id')->default(1);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    /**
     * Enough content for the sidebars, which read five entries.
     */
    protected function seedPublicContent(int $count = 8): void
    {
        Category::create(['name' => 'Administração']);

        for ($i = 1; $i <= $count; $i++) {
            Job::create([
                'title' => "Vaga {$i}",
                'company' => "Empresa {$i}",
                'province' => 'Luanda',
                'description' => "Descrição da vaga {$i}",
                'email_or_link' => 'rh@exemplo.ao',
                'photo' => 'images/jobs/exemplo.jpg',
            ]);

            Article::create([
                'title' => "Notícia {$i}",
                'description' => "Texto da notícia {$i}",
                'photo' => 'images/articles/default.jpg',
            ]);
        }

        Curriculo::create([
            'title' => 'Modelo de CV',
            'slug' => 'modelo-de-cv',
            'author' => 'Equipa',
            'description' => 'Um modelo simples',
            'photo' => 'images/curriculos/cv.jpg',
        ]);
    }

    /**
     * Applies fallback configuration inside a test.
     *
     * The services are singletons that read their configuration once, and
     * seeding already resolved them (saving a model flushes the caches), so
     * the instances have to be dropped for new settings to apply.
     *
     * @param  array<string, mixed>  $config
     */
    protected function reconfigureFallback(array $config): void
    {
        config($config);

        $this->app->forgetInstance(DatabaseFallbackCache::class);
        $this->app->forgetInstance(PublicPageCache::class);
    }

    /**
     * Makes every subsequent query fail the way the shared host does when the
     * user runs out of connections.
     *
     * The PDO instance is replaced with a closure that throws, which is how
     * Laravel surfaces a connection that cannot be established: the exception
     * is wrapped in a QueryException with the driver code preserved.
     */
    protected function breakDatabase(int $driverCode = 1226, string $message = null): void
    {
        $connection = DB::connection();

        $this->realPdo ??= $connection->getPdo();

        $message ??= "SQLSTATE[42000] [1226] User 'yqqnyhrgnt_edv' has exceeded the 'max_user_connections' resource (current value: 30)";

        $this->connectionAttempts = 0;

        $connection->setPdo(function () use ($driverCode, $message) {
            // Laravel só guarda o PDO quando a closure devolve: cada tentativa
            // de consulta volta a passar por aqui, o que permite contá-las.
            $this->connectionAttempts++;

            $exception = new PDOException($message, 0);
            $exception->errorInfo = ['42000', $driverCode, $message];

            throw $exception;
        });
    }

    protected function repairDatabase(): void
    {
        if ($this->realPdo !== null) {
            DB::connection()->setPdo($this->realPdo);
        }
    }
}
