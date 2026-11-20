<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use App\Models\Job;
use App\Models\Draft;
use App\Models\CategoryJob;

class JobController extends Controller
{
    /** Respostas GET em cache durante 5 minutos, até esta página. */
    private const CACHE_TTL = 300;
    private const CACHED_PAGES = 20;

    /**
     * Lista de vagas em array JSON (o formato de sempre), agora por janelas: as
     * `limit` mais recentes (200 por omissão, no máximo 500) e `page` para recuar.
     * Dentro de cada janela mantém-se a ordem crescente de id que Job::all() dava.
     * Antes devolvia a tabela inteira, com todas as descrições, num só pedido.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 200), 1), 500);
        $page = max((int) $request->query('page', 1), 1);

        $build = fn () => Job::orderByDesc('id')->forPage($page, $limit)->get()->sortBy('id')->values()->toArray();

        $jobs = $page <= self::CACHED_PAGES
            ? Cache::remember("api:jobs-all:{$limit}:{$page}", self::CACHE_TTL, $build)
            : $build();

        return response()->json($jobs);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        return Draft::create($request->all());
    }

    public function get()
    {
        $page = Paginator::resolveCurrentPage();

        $build = fn () => Job::where('country_id','1')->with('categories')->latest()->paginate(50)->toArray();

        $data = $page <= self::CACHED_PAGES
            ? Cache::remember("api:jobs:{$page}", self::CACHE_TTL, $build)
            : $build();

        $license = 'This API was developed by Edivaldo Jorge (https://github.com/jorgeedvaldo)';
        $message = 'Operation performed successfully.';
        return response()->json(
            [
                'message' => $message,
                'data' => $data,
                'license' => $license
            ]);
    }

    public function getById($id)
    {
        $build = fn () => Job::with('categories')->where('id', $id)->get()->toArray();

        // Só se guardam vagas que existem e ids numéricos: quem percorre ids
        // inexistentes não enche a cache.
        $data = ctype_digit((string) $id) ? Cache::get("api:job:{$id}") : null;

        if ($data === null) {
            $data = $build();

            if ($data !== [] && ctype_digit((string) $id)) {
                Cache::put("api:job:{$id}", $data, self::CACHE_TTL);
            }
        }

        $license = 'This API was developed by Edivaldo Jorge (https://github.com/jorgeedvaldo)';
        $message = 'Operation performed successfully.';
        return response()->json(
            [
                'message' => $message,
                'data' => $data,
                'license' => $license
            ]);
    }

    public function setCategories(Request $request)
    {
        return CategoryJob::create($request->all());
    }
}
