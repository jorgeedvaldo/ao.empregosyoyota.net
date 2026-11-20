<?php

namespace App\Http\Controllers;

use App\Models\Curriculo;
use Illuminate\Http\Request;

class CurriculoController extends Controller
{
    public function index()
    {
        $curriculos = Curriculo::orderByDesc('id')->paginate(15);
        return view('curriculos', compact('curriculos'));
    }

    public function getBySlug($slug)
    {
        $curriculo = Curriculo::where('slug', $slug)->firstOrFail();

        return view('curriculo', compact('curriculo'));
    }
}
