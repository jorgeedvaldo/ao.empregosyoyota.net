<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    use HasFactory;

    /** As categorias mudam raramente; uma hora chega (e gravar uma limpa a cache). */
    public const CACHE_TTL = 3600;

    /** Máximo de vagas mostradas numa página de categoria. */
    public const JOBS_LIMIT = 100;

    protected $fillable = [
        'name',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saved(fn () => static::forgetCache());
        static::deleted(fn () => static::forgetCache());
    }

    /**
     * Todas as categorias por ordem alfabética, só com o que o menu usa.
     */
    public static function getCachedAll()
    {
        return Cache::remember('categories:all', self::CACHE_TTL, function () {
            return static::orderBy('name')->get(['id', 'name']);
        });
    }

    /**
     * As vagas mais recentes desta categoria (país 1), só com as colunas da
     * listagem. A página de categoria nunca teve paginação; o limite impede
     * que uma categoria grande carregue centenas de descrições completas.
     */
    public function getCachedJobs()
    {
        return Cache::remember(static::jobsCacheKey($this->id), Job::CACHE_TTL, function () {
            return $this->jobs()
                ->where('country_id', 1)
                ->orderByDesc('jobs.id')
                ->limit(self::JOBS_LIMIT)
                ->get(array_map(fn ($column) => "jobs.{$column}", Job::LIST_COLUMNS));
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('categories:all');
        static::forgetJobsCache();
    }

    /** Esquece as vagas em cache de todas as categorias (uma vaga pode pertencer a várias). */
    public static function forgetJobsCache(): void
    {
        foreach (static::query()->pluck('id') as $id) {
            Cache::forget(static::jobsCacheKey($id));
        }
    }

    protected static function jobsCacheKey($id): string
    {
        return "category:{$id}:jobs";
    }

    public function jobs()
    {
        return $this->belongsToMany('App\Models\Job', 'category_jobs');
    }
}
