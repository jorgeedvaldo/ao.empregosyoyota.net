<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Category;
use App\Models\Curriculo;
use App\Models\Job;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Every read the public pages need, routed through the fallback cache so the
 * pages keep working when MySQL refuses new connections.
 *
 * Lists shared by all pages (categories, latest jobs, latest articles) are
 * cached once under a shared key instead of being copied into every page's
 * entry, which keeps the cache small on shared hosting.
 */
class PublicContent
{
    /**
     * The sidebars show 5 entries; one spare is fetched so the current item can
     * be removed from the list without leaving a gap.
     */
    private const SIDEBAR_SIZE = 5;

    private const COUNTRY_ID = 1;

    public function __construct(private DatabaseFallbackCache $cache)
    {
    }

    public function categories(): EloquentCollection
    {
        return $this->cache->remember(
            'categories',
            fn () => Category::orderBy('name')->get(),
            'shared'
        );
    }

    public function latestJobs(int $limit = self::SIDEBAR_SIZE + 1): EloquentCollection
    {
        return $this->cache->remember(
            'jobs:latest:'.$limit,
            fn () => Job::where('country_id', self::COUNTRY_ID)->orderByRaw('id DESC')->limit($limit)->get(),
            'shared'
        );
    }

    /**
     * Latest articles for the Angola edition, as used by the article pages.
     */
    public function latestArticles(int $limit = self::SIDEBAR_SIZE + 1): EloquentCollection
    {
        return $this->cache->remember(
            'articles:latest:'.$limit,
            fn () => Article::where('country_id', self::COUNTRY_ID)->orderByRaw('id DESC')->limit($limit)->get(),
            'shared'
        );
    }

    /**
     * Latest articles without the country filter, which is what the job and
     * tool sidebars have always shown.
     */
    public function latestArticlesAnyCountry(int $limit = self::SIDEBAR_SIZE): EloquentCollection
    {
        return $this->cache->remember(
            'articles:latest-any:'.$limit,
            fn () => Article::orderByRaw('id DESC')->limit($limit)->get(),
            'shared'
        );
    }

    public function jobsPage(int $perPage): LengthAwarePaginator
    {
        return $this->paginated(
            'jobs:page:'.$perPage,
            fn () => Job::where('country_id', self::COUNTRY_ID)->orderByRaw('id DESC')->paginate($perPage)
        );
    }

    public function articlesPage(int $perPage): LengthAwarePaginator
    {
        return $this->paginated(
            'articles:page:'.$perPage,
            fn () => Article::where('country_id', self::COUNTRY_ID)->orderByRaw('id DESC')->paginate($perPage)
        );
    }

    public function curriculosPage(int $perPage): LengthAwarePaginator
    {
        return $this->paginated(
            'curriculos:page:'.$perPage,
            fn () => Curriculo::orderByRaw('id DESC')->paginate($perPage)
        );
    }

    public function jobBySlug(string $slug): Job
    {
        return $this->cache->remember(
            'job:slug:'.sha1($slug),
            fn () => Job::with('categories')->where('country_id', self::COUNTRY_ID)->where('slug', $slug)->get()[0],
            'detail'
        );
    }

    public function jobById(int $id): Job
    {
        return $this->cache->remember(
            'job:id:'.$id,
            fn () => Job::with('categories')->where('country_id', self::COUNTRY_ID)->where('id', $id)->get()[0],
            'detail'
        );
    }

    public function articleBySlug(string $slug): Article
    {
        return $this->cache->remember(
            'article:slug:'.sha1($slug),
            fn () => Article::where('country_id', self::COUNTRY_ID)->where('slug', $slug)->get()[0],
            'detail'
        );
    }

    public function articleById(int $id): Article
    {
        return $this->cache->remember(
            'article:id:'.$id,
            fn () => Article::where('country_id', self::COUNTRY_ID)->where('id', $id)->get()[0],
            'detail'
        );
    }

    public function curriculoBySlug(string $slug): Curriculo
    {
        return $this->cache->remember(
            'curriculo:slug:'.sha1($slug),
            fn () => Curriculo::where('slug', $slug)->get()[0],
            'detail'
        );
    }

    /**
     * A category with its jobs. Unknown ids are rejected against the cached
     * category list, so a crawler walking numeric ids neither reaches MySQL
     * nor creates cache entries.
     */
    public function categoryWithJobs(int $id): ?Category
    {
        if (! $this->categories()->contains(fn (Category $category) => (int) $category->getKey() === $id)) {
            return null;
        }

        return $this->cache->remember(
            'category:'.$id,
            fn () => Category::with(['jobs' => function ($query) {
                $query->where('country_id', self::COUNTRY_ID)->orderByRaw('id DESC');
            }])->where('id', $id)->first(),
            'detail'
        );
    }

    /**
     * The "Últimas Oportunidades" sidebar for a job page.
     *
     * The job view reads entries 0..4 and also looks for the next older job
     * (`$LastJobs->where('id', '<', $job['id'])->first()`) to build the "ver
     * próxima vaga" link. The list is therefore the newest jobs plus, when the
     * page being shown is older than all of them, the one job that link needs.
     */
    public function sidebarJobsFor(Model $current): EloquentCollection
    {
        $latest = $this->latestJobs()
            ->reject(fn (Model $job) => $job->getKey() === $current->getKey())
            ->take(self::SIDEBAR_SIZE)
            ->values();

        if ($latest->contains(fn (Model $job) => $job->id < $current->id)) {
            return $latest;
        }

        $previous = $this->previousJob((int) $current->id);

        return $previous === null ? $latest : $latest->push($previous)->values();
    }

    /**
     * The "Últimas Notícias" sidebar for an article page: the newest articles
     * without the one being read.
     */
    public function sidebarArticlesFor(Model $current): EloquentCollection
    {
        return $this->latestArticles()
            ->reject(fn (Model $article) => $article->getKey() === $current->getKey())
            ->take(self::SIDEBAR_SIZE)
            ->values();
    }

    /**
     * Newest job published before the given one.
     */
    public function previousJob(int $id): ?Job
    {
        return $this->cache->remember(
            'job:previous:'.$id,
            fn () => Job::where('country_id', self::COUNTRY_ID)
                ->where('id', '<', $id)
                ->orderByRaw('id DESC')
                ->first(),
            'detail'
        );
    }

    public function sitemapJobs(int $perPage = 500): LengthAwarePaginator
    {
        return $this->paginated(
            'sitemap:jobs:'.$perPage,
            fn () => Job::orderByRaw('id DESC')->paginate($perPage),
            'feed'
        );
    }

    public function sitemapArticles(int $perPage = 300): LengthAwarePaginator
    {
        return $this->paginated(
            'sitemap:articles:'.$perPage,
            fn () => Article::orderByRaw('id DESC')->paginate($perPage),
            'feed'
        );
    }

    public function feedJobs(int $perPage = 10): LengthAwarePaginator
    {
        return $this->paginated(
            'feed:jobs:'.$perPage,
            fn () => Job::orderByRaw('id DESC')->paginate($perPage),
            'feed'
        );
    }

    /**
     * Paginated reads get the page number in the key. Deep pages bypass the
     * cache entirely so a crawler asking for ?page=999999 cannot fill the disk
     * with entries nobody will read again.
     */
    private function paginated(string $key, Closure $query, string $profile = 'listing', int $maxCachedPage = 20): LengthAwarePaginator
    {
        $page = (int) Paginator::resolveCurrentPage();

        if ($page < 1 || $page > $maxCachedPage) {
            return $query();
        }

        return $this->cache->remember($key.':p'.$page, $query, $profile);
    }
}
