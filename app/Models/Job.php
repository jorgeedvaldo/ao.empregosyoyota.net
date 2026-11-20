<?php

namespace App\Models;

use App\Models\Concerns\PaginatesFromCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Job extends Model
{
    use HasFactory, PaginatesFromCache;

    /** Colunas que as listagens e barras laterais mostram (a descrição fica de fora). */
    public const LIST_COLUMNS = ['id', 'title', 'slug', 'company', 'province', 'created_at'];

    /** Quantas vagas recentes ficam em cache (cobre barras laterais e a 1.ª página das listagens). */
    public const LATEST_LIMIT = 50;

    /** Validade da cache em segundos. Curta porque a base de dados pode ser escrita por outro site. */
    public const CACHE_TTL = 600;

    /** Validade dos resultados de pesquisa e quantas páginas de cada pesquisa se guardam. */
    public const SEARCH_TTL = 300;
    public const SEARCH_CACHED_PAGES = 5;

    /** XML do sitemap e do feed, já renderizado. Uma hora; gravar uma vaga ou artigo apaga-o. */
    public const SITEMAP_CACHE_KEY = 'xml:sitemap';
    public const FEED_CACHE_KEY = 'xml:feed';
    public const XML_CACHE_TTL = 3600;

    protected $fillable = [
        'title', 'slug', 'company', 'province', 'description', 'email_or_link', 'photo'
    ];

	protected static function boot()
    {
        parent::boot();

        static::created(function ($job) {
            $job->slug = $job->generateSlug($job->title, $job->id);
            $job->save();

			SocialMediaJob::create([
                'job_id' => $job->id,
                'post_status' => 0,
            ]);
        });

        static::saved(fn () => static::forgetCache());
        static::deleted(fn () => static::forgetCache());
    }

    /**
     * As vagas mais recentes do país 1, só com as colunas que as listagens e
     * barras laterais mostram (a descrição fica de fora de propósito).
     */
    public static function getCachedLatest()
    {
        return Cache::remember('jobs:latest', self::CACHE_TTL, function () {
            return static::where('country_id', 1)
                ->orderByDesc('id')
                ->limit(self::LATEST_LIMIT)
                ->get(self::LIST_COLUMNS);
        });
    }

    public static function getCachedCount(): int
    {
        return (int) Cache::remember('jobs:count', self::CACHE_TTL, function () {
            return static::where('country_id', 1)->count();
        });
    }

    /**
     * A vaga publicada imediatamente antes desta (para o botão "ver próxima
     * vaga"). Consulta própria e leve; devolve null para a vaga mais antiga.
     */
    public static function nextOlder(self $job): ?self
    {
        return static::where('country_id', 1)
            ->where('id', '<', $job->id)
            ->orderByDesc('id')
            ->first(['id', 'slug']);
    }

    /**
     * Pesquisa por título ou descrição nas vagas do país 1. O resultado de
     * cada termo e página fica 5 minutos em cache: os robôs repetem as mesmas
     * pesquisas e cada uma varre a tabela inteira. Páginas fundas não ficam em
     * cache para que ninguém encha o disco com ?page=N.
     */
    public static function search(?string $term, int $perPage = 30): LengthAwarePaginator
    {
        $term = (string) $term;
        $page = Paginator::resolveCurrentPage();

        $run = function () use ($term, $perPage) {
            $found = static::where('country_id', 1)
                ->where(function ($query) use ($term) {
                    $query->where('title', 'LIKE', "%{$term}%")
                        ->orWhere('description', 'LIKE', "%{$term}%");
                })
                ->orderByDesc('id')
                ->paginate($perPage, self::LIST_COLUMNS);

            return ['items' => $found->getCollection(), 'total' => $found->total()];
        };

        $result = $page <= self::SEARCH_CACHED_PAGES
            ? Cache::remember('search:'.sha1(mb_strtolower($term)).':'.$perPage.':'.$page, self::SEARCH_TTL, $run)
            : $run();

        return new LengthAwarePaginator($result['items'], $result['total'], $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    public static function forgetCache(): void
    {
        Cache::forget('jobs:latest');
        Cache::forget('jobs:count');
        Cache::forget(self::SITEMAP_CACHE_KEY);
        Cache::forget(self::FEED_CACHE_KEY);
        // As páginas de categoria listam vagas; a ligação vaga-categoria é
        // gravada pelo painel logo a seguir, por isso também têm validade curta.
        Category::forgetJobsCache();
    }

    private function generateSlug($title, $id)
    {
        if (static::whereSlug($slug = Str::slug($title))->exists()) {
            $max = static::whereTitle($title)->latest('id');
            $slug = $slug . '-' . $id;
        }
        return $slug;
    }
	
    public function categories()
    {
        return $this->belongsToMany('App\Models\Category', 'category_jobs');
    }
	
	public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
