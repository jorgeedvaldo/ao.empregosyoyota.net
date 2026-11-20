<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;

class JobController extends Controller
{
    public function index()
    {
        $jobs = Job::cachedPage(30);
        $categories = Category::getCachedAll();
        return view('jobs', compact('jobs', 'categories'));
    }

    public function getById($id)
    {
        return $this->showJob(
            Job::where('country_id', 1)->where('id', $id)->firstOrFail()
        );
    }

    public function getByCategoryId($id)
    {
        // Ids que não existem dão 404 sem tocar na base de dados: a lista de
        // categorias vem da cache.
        $categories = Category::getCachedAll();
        $category = $categories->firstWhere('id', (int) $id);

        abort_if($category === null, 404);

        $categoryJobs = $category->getCachedJobs();

        return view('category', compact('category', 'categoryJobs', 'categories'));
    }

    public function getBySlug($slug)
    {
        return $this->showJob(
            Job::where('country_id', 1)->where('slug', $slug)->firstOrFail()
        );
    }

    public function getBySlugAMP($slug)
    {
        $job = Job::where('country_id', 1)->where('slug', $slug)->firstOrFail();

        return view('amp.job', compact('job'));
    }

    public function search(Request $request)
    {
        $query = $request->input('query');

        // Texto livre vindo de fora: sem arrays e com no máximo 60 caracteres.
        $query = is_string($query) ? mb_substr($query, 0, 60) : null;

        $jobs = Job::search($query);

        $categories = Category::getCachedAll();

        return view('search', compact('categories', 'jobs', 'query'));
    }

    public function siteMapGenerator()
    {
        $page = Paginator::resolveCurrentPage();

        $render = function () use ($page) {
            $columns = ['id', 'slug', 'photo', 'updated_at'];

            return view('xml.sitemap', [
                'jobs' => Job::orderByDesc('id')->forPage($page, 500)->get($columns),
                'articles' => Article::orderByDesc('id')->forPage($page, 300)->get($columns),
            ])->render();
        };

        // O XML ficou igual; só a 1.ª página (a que o Google conhece) vai para a cache.
        $xml = $page === 1 ? Cache::remember(Job::SITEMAP_CACHE_KEY, Job::XML_CACHE_TTL, $render) : $render();

        return response($xml)->header('Content-Type', 'text/xml');
    }

    public function feedGenerator()
    {
        $page = Paginator::resolveCurrentPage();

        $render = function () use ($page) {
            return view('xml.feed', [
                'jobs' => Job::orderByDesc('id')->forPage($page, 10)->get(),
            ])->render();
        };

        $xml = $page === 1 ? Cache::remember(Job::FEED_CACHE_KEY, Job::XML_CACHE_TTL, $render) : $render();

        return response($xml)->header('Content-Type', 'text/xml');
    }

    private function showJob(Job $job)
    {
        $LastArticles = Article::getCachedLatestAnyCountry();
        $LastJobs = Job::getCachedLatest()->where('id', '<>', $job->id)->values();
        $previousJob = Job::nextOlder($job);

        return view('job', compact('job', 'LastArticles', 'LastJobs', 'previousJob'));
    }
}
