<?php

namespace App\Http\Controllers;

use App\Services\PublicContent;

class OcrController extends Controller
{
    public function __construct(private PublicContent $content)
    {
    }

    public function index()
    {
        return view('tools.ocr', [
            'categories' => $this->content->categories(),
            'LastArticles' => $this->content->latestArticlesAnyCountry(),
            'LastJobs' => $this->content->latestJobs(),
        ]);
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
