<?php

namespace App\Http\Controllers;

use App\Exceptions\DatabaseUnavailableException;
use App\Models\Article;
use App\Services\PublicContent;
use Exception;

class ArticleController extends Controller
{
    public function __construct(private PublicContent $content)
    {
    }

    public function index()
    {
        return view('articles', [
            'articles' => $this->content->articlesPage(16),
        ]);
    }

    public function getById($id)
    {
        try
        {
            return $this->articleView($this->content->articleById((int) $id));
        }
        catch(DatabaseUnavailableException $ex)
        {
            throw $ex;
        }
        catch(Exception $ex)
        {
            abort(404);
        }
    }

	public function getBySlug($slug)
    {
        try
        {
            return $this->articleView($this->content->articleBySlug($slug));
        }
        catch(DatabaseUnavailableException $ex)
        {
            throw $ex;
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
            return view('amp.article', ['article' => $this->content->articleBySlug($slug)]);
        }
        catch(DatabaseUnavailableException $ex)
        {
            throw $ex;
        }
        catch(Exception $ex)
        {
            abort(404);
        }
    }

    private function articleView(Article $article)
    {
        return view('article', [
            'article' => $article,
            'LastArticles' => $this->content->sidebarArticlesFor($article),
            'LastJobs' => $this->content->latestJobs(),
        ]);
    }
}
