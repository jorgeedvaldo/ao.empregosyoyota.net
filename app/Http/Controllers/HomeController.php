<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Job;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        $jobs = Job::cachedPage(15);
		$articles = Article::cachedPage(8);
        return view('home', compact('jobs', 'articles'));
    }
}
