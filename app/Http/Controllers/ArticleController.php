<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function index()
    {
        $articles = Article::cachedPage(16);
        return view('articles', compact('articles'));
    }

    public function getById($id)
    {
        return $this->showArticle(
            Article::where('country_id', 1)->where('id', $id)->firstOrFail()
        );
    }

    public function getBySlug($slug)
    {
        return $this->showArticle(
            Article::where('country_id', 1)->where('slug', $slug)->firstOrFail()
        );
    }

    public function getBySlugAMP($slug)
    {
        $article = Article::where('country_id', 1)->where('slug', $slug)->firstOrFail();

        return view('amp.article', compact('article'));
    }

    private function showArticle(Article $article)
    {
        $LastArticles = Article::getCachedLatest()->where('id', '<>', $article->id)->values();
        $LastJobs = Job::getCachedLatest();

        return view('article', compact('article', 'LastArticles', 'LastJobs'));
    }
}
