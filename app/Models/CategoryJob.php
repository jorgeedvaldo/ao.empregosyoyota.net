<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryJob extends Model
{
    use HasFactory;
	
	/**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $fillable = [
        'category_id', 'job_id'
    ];

    protected static function boot()
    {
        parent::boot();

        // A ligação vaga-categoria é gravada à parte (API e painel); as páginas
        // de categoria em cache têm de a refletir.
        static::saved(fn () => Category::forgetJobsCache());
        static::deleted(fn () => Category::forgetJobsCache());
    }
}
