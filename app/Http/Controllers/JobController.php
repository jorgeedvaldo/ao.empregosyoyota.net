<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Job;
use Exception;
use Illuminate\Http\Request;

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
        try
        {
            $job = Job::with('categories')->where('country_id', 1)->where('id', $id)->get()[0];

            $LastArticles = Article::getCachedLatestAnyCountry();
            $LastJobs = Job::getCachedLatest()->where('id', '<>', $job->id)->values();
            $previousJob = Job::nextOlder($job);

            return view('job', compact('job', 'LastArticles', 'LastJobs', 'previousJob'));
        }
        catch(Exception $ex)
        {
            abort(404);
        }
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
        try
        {
            $job = Job::with('categories')->where('country_id', 1)->where('slug', $slug)->get()[0];

            $LastArticles = Article::getCachedLatestAnyCountry();
            $LastJobs = Job::getCachedLatest()->where('id', '<>', $job->id)->values();
            $previousJob = Job::nextOlder($job);

            return view('job', compact('job', 'LastArticles', 'LastJobs', 'previousJob'));
        }
        catch(Exception $ex)
        {
            abort(404);
        }
    }
	
	public function getBySlugAMP($slug)
    {
        try
        {
            $job = Job::with('categories')->where('country_id', 1)->where('slug', $slug)->get()[0];

            return view('amp.job', compact('job'));
        }
        catch(Exception $ex)
        {
            abort(404);
        }
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
        $jobs = Job::orderByRaw('id DESC')->paginate(500);
		$articles = Article::orderByRaw('id DESC')->paginate(300);
        return response()->view('xml.sitemap', compact('jobs', 'articles'))->header('Content-Type', 'text/xml');
    }
	
	public function feedGenerator()
    {
        $jobs = Job::orderByRaw('id DESC')->paginate(10);
        return response()->view('xml.feed', compact('jobs'))->header('Content-Type', 'text/xml');
    }
}
