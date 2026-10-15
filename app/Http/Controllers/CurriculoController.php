<?php

namespace App\Http\Controllers;

use App\Exceptions\DatabaseUnavailableException;
use App\Services\PublicContent;
use Exception;

class CurriculoController extends Controller
{
    public function __construct(private PublicContent $content)
    {
    }

    public function index()
    {
        return view('curriculos', [
            'curriculos' => $this->content->curriculosPage(15),
        ]);
    }

    public function getBySlug($slug)
    {
        try
        {
            $curriculo = $this->content->curriculoBySlug($slug);
        }
        catch(DatabaseUnavailableException $ex)
        {
            throw $ex;
        }
        catch(Exception $ex)
        {
            abort(404);
        }

        return view('curriculo', compact('curriculo'));
    }
}
