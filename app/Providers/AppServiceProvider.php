<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Curriculo;
use App\Models\Job;
use App\Services\DatabaseCircuitBreaker;
use App\Services\DatabaseFallbackCache;
use App\Services\PublicPageCache;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(DatabaseCircuitBreaker::class, function ($app) {
            return new DatabaseCircuitBreaker(
                Cache::store($app['config']->get('fallback.stores.data')),
                $app['config']->get('fallback.breaker', [])
            );
        });

        $this->app->singleton(DatabaseFallbackCache::class, function ($app) {
            return new DatabaseFallbackCache(
                Cache::store($app['config']->get('fallback.stores.data')),
                $app->make(DatabaseCircuitBreaker::class),
                $app['config']->get('fallback.profiles', []),
                (bool) $app['config']->get('fallback.enabled', true)
            );
        });

        $this->app->singleton(PublicPageCache::class, function ($app) {
            return new PublicPageCache(
                Cache::store($app['config']->get('fallback.stores.pages')),
                $app['config']->get('fallback.pages', [])
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);
        Paginator::useBootstrap();

        $this->invalidateCachesOnContentChange();
    }

    /**
     * Content edited in the admin panel must show up straight away, so every
     * save or delete drops the fresh copies of the data and page caches.
     *
     * The emergency and stale copies keep their own keys and survive: right
     * after a change they are still the best thing to serve if MySQL goes
     * down before the pages are rendered again.
     */
    private function invalidateCachesOnContentChange(): void
    {
        $flush = function () {
            $this->app->make(DatabaseFallbackCache::class)->flushFresh();
            $this->app->make(PublicPageCache::class)->flush();
        };

        foreach ([Job::class, Article::class, Category::class, Curriculo::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }
    }
}
