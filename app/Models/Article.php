<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Article extends Model
{
    use HasFactory;

    /** Quantos artigos recentes ficam em cache (barras laterais e 1.ª página das listagens). */
    public const LATEST_LIMIT = 50;

    /** Validade da cache em segundos. Curta porque a base de dados pode ser escrita por outro site. */
    public const CACHE_TTL = 600;

    protected $fillable = [
        'title', 'slug', 'description', 'photo'
    ];

	protected static function boot()
    {
        parent::boot();

        static::created(function ($article) {
            $article->slug = $article->generateSlug($article->title, $article->id);
            $article->save();
        });

        static::saved(fn () => static::forgetCache());
        static::deleted(fn () => static::forgetCache());
    }

    /**
     * Os artigos mais recentes do país 1 (listagens e página de artigo), só
     * com as colunas que as views usam.
     */
    public static function getCachedLatest()
    {
        return Cache::remember('articles:latest', self::CACHE_TTL, function () {
            return static::where('country_id', 1)
                ->orderByDesc('id')
                ->limit(self::LATEST_LIMIT)
                ->get(['id', 'title', 'slug', 'created_at']);
        });
    }

    /**
     * Os mais recentes de qualquer país. A barra lateral das páginas de vaga e
     * do OCR sempre mostrou artigos sem filtrar por país.
     */
    public static function getCachedLatestAnyCountry()
    {
        return Cache::remember('articles:latest-any', self::CACHE_TTL, function () {
            return static::orderByDesc('id')
                ->limit(self::LATEST_LIMIT)
                ->get(['id', 'title', 'slug', 'created_at']);
        });
    }

    public static function getCachedCount(): int
    {
        return (int) Cache::remember('articles:count', self::CACHE_TTL, function () {
            return static::where('country_id', 1)->count();
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('articles:latest');
        Cache::forget('articles:latest-any');
        Cache::forget('articles:count');
    }

    private function generateSlug($title, $id)
    {
        if (static::whereSlug($slug = Str::slug($title))->exists()) {
            $max = static::whereTitle($title)->latest('id');
            $slug = $slug . '-' . $id;
        }
        return $slug;
    }
}
