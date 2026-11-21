<?php

namespace App\Models\Concerns;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Listagens paginadas (home, /empregos, /articles) que substituem
 * ->paginate(): a 1.ª página sai da lista em cache e a contagem total também,
 * por isso o caso mais pedido (robôs e visitantes) não faz consultas.
 *
 * O model tem de definir LIST_COLUMNS, getCachedLatest() e getCachedCount().
 */
trait PaginatesFromCache
{
    public static function cachedPage(int $perPage): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();
        $total = static::getCachedCount();

        if ($page === 1 && $perPage <= static::LATEST_LIMIT) {
            $items = static::getCachedLatest()->take($perPage)->values();
        } elseif ($page > max(1, (int) ceil($total / $perPage))) {
            // Páginas para lá da última (?page=99999): nada a consultar, nada a guardar.
            $items = (new static)->newCollection();
        } else {
            $items = static::where('country_id', 1)
                ->orderByDesc('id')
                ->forPage($page, $perPage)
                ->get(static::LIST_COLUMNS);
        }

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }
}
