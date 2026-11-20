<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Job;
use Illuminate\Http\Request;

class OcrController extends Controller
{
    public function index()
    {
        $LastArticles = Article::getCachedLatestAnyCountry();
        $LastJobs = Job::getCachedLatest();

        return view('tools.ocr', compact('LastArticles', 'LastJobs'));
    }
	
	public function indexEn()
    {
    	return view('tools.en.ocr');   
    }
	
	public function indexDashboardEn()
    {
    	return view('tools.en.dashboard');   
    }
	public function indexDashboardPt()
    {
    	return view('tools.dashboard');   
    }
    public function indexQuizPt()
    {
    	return view('tools.quiz');   
    }
}
