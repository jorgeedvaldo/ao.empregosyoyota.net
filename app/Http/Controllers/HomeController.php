<?php

namespace App\Http\Controllers;

use App\Services\PublicContent;

class HomeController extends Controller
{
    public function __construct(private PublicContent $content)
    {
    }

    public function index()
    {
        return view('home', [
            'jobs' => $this->content->jobsPage(15),
            'articles' => $this->content->articlesPage(8),
        ]);
    }
}
