<?php

namespace App\Http\Controllers;

use App\Exceptions\DatabaseUnavailableException;
use App\Models\Job;
use App\Services\PublicContent;
use Exception;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function __construct(private PublicContent $content)
    {
    }

    public function index()
    {
        return view('jobs', [
            'jobs' => $this->content->jobsPage(30),
            'categories' => $this->content->categories(),
        ]);
    }

    public function getById($id)
    {
        try
        {
            return $this->jobView($this->content->jobById((int) $id));
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

    public function getByCategoryId($id)
    {
        try
        {
            $category = $this->content->categoryWithJobs((int) $id);
        }
        catch(DatabaseUnavailableException $ex)
        {
            throw $ex;
        }
        catch(Exception $ex)
        {
            abort(404);
        }

        abort_if($category === null, 404);

        return view('category', [
            'category' => $category,
            'categoryJobs' => $category->jobs,
            'categories' => $this->content->categories(),
        ]);
    }

    public function getBySlug($slug)
    {
        try
        {
            return $this->jobView($this->content->jobBySlug($slug));
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
            return view('amp.job', ['job' => $this->content->jobBySlug($slug)]);
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

    public function search(Request $request)
    {
        $query = $request->input('query');

        $jobs = Job::where('title', 'LIKE', "%{$query}%")
                    ->orWhere('description', 'LIKE', "%{$query}%")
                    ->orderByRaw('id DESC')
                    ->paginate(30);

        $categories = $this->content->categories();

        return view('search', compact('categories', 'jobs', 'query'));
    }

	public function siteMapGenerator()
    {
        return response()->view('xml.sitemap', [
            'jobs' => $this->content->sitemapJobs(500),
            'articles' => $this->content->sitemapArticles(300),
        ])->header('Content-Type', 'text/xml');
    }

	public function feedGenerator()
    {
        return response()->view('xml.feed', [
            'jobs' => $this->content->feedJobs(10),
        ])->header('Content-Type', 'text/xml');
    }

    /**
     * The job page and its sidebars. $LastJobs must keep the contract the view
     * relies on: entries 0..4 plus the next older job for the "ver próxima
     * vaga" link.
     */
    private function jobView(Job $job)
    {
        return view('job', [
            'job' => $job,
            'categories' => $this->content->categories(),
            'LastArticles' => $this->content->latestArticlesAnyCountry(),
            'LastJobs' => $this->content->sidebarJobsFor($job),
        ]);
    }
}
