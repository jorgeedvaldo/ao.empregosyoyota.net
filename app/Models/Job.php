<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Job extends Model
{
    use HasFactory;

    /** Quantas vagas recentes ficam em cache (cobre barras laterais e a 1.ª página das listagens). */
    public const LATEST_LIMIT = 50;

    /** Validade da cache em segundos. Curta porque a base de dados pode ser escrita por outro site. */
    public const CACHE_TTL = 600;

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
                ->get(['id', 'title', 'slug', 'company', 'province', 'created_at']);
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

    public static function forgetCache(): void
    {
        Cache::forget('jobs:latest');
        Cache::forget('jobs:count');
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
