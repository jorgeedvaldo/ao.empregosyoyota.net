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

    public static function forgetCache(): void
    {
        Cache::forget('categories:all');
    }

    public function jobs()
    {
        return $this->belongsToMany('App\Models\Job', 'category_jobs');
    }
}
