<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AboutController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\CurriculoController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImageProxyController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\OcrController;
use App\Http\Controllers\TermController;

/*
|--------------------------------------------------------------------------
| Rotas públicas de leitura
|--------------------------------------------------------------------------
|
| Carregadas pelo RouteServiceProvider com o grupo de middleware "public"
| (ver App\Http\Kernel): sem sessão, sem cookies e sem CSRF, por isso os
| robôs que as percorrem já não criam um ficheiro de sessão por pedido.
|
| Só rotas GET, sem formulários com estado. Qualquer rota que precise de
| sessão, de CSRF ou de utilizador autenticado (um POST, um formulário com
| mensagens de erro, uma área de conta) NÃO deve ficar aqui: vai para
| routes/web.php, que continua a usar o grupo "web". O painel Filament
| (/admin) e a API têm os seus próprios middleware e não passam por aqui.
|
| Cabeçalhos de cache HTTP (middleware "cache.public") só nas páginas de leitura
| que não dependem do visitante; não na pesquisa, em /dashboard, /quiz nem na API.
|
| A ordem importa: os redirecionamentos têm de vir antes de /articles/{slug}
| e /empregos/{slug}.
|
*/


Route::get('/empregos/aberto-o-concurso-publico-da-policia-nacional-de-angola', function () {
    return redirect('/articles/policia-nacional-de-angola-desmente-boatos-de-concurso-publico');
});

Route::get('/articles/novo-portal-de-recrutamento-da-policia-nacional-de-angola-revela-um-possivel-recrutamento', function () {
    return redirect('/articles/policia-nacional-de-angola-desmente-boatos-de-concurso-publico');
});

// Transparent proxy for images physically hosted on the main domain
// (empregosyoyota.net). Apache only forwards here when the file does not
// already exist under public/storage (see public/.htaccess), so a cached
// copy is served as a static file on subsequent requests without
// involving Laravel at all.
Route::get('/storage/images/{path}', [ImageProxyController::class, 'show'])
    ->where('path', '.*');

Route::get('/', [HomeController::class, 'index'])->name('home')->middleware('cache.public');
Route::get('/about', [AboutController::class, 'index'])->name('about')->middleware('cache.public');
Route::get('/terms', [TermController::class, 'index'])->name('terms')->middleware('cache.public');

Route::get('/categories/{id}', [JobController::class, 'getByCategoryId'])
    ->where('id', '[0-9]+')->middleware('cache.public');

Route::get('/empregos', [JobController::class, 'index'])->middleware('cache.public');
Route::get('/empregos/{slug}', [JobController::class, 'getBySlug'])->middleware('cache.public');


Route::get('/jobs', [JobController::class, 'index'])->middleware('cache.public');
Route::get('/jobs/{id}', [JobController::class, 'getById'])
    ->where('id', '[0-9]+')->middleware('cache.public');

Route::get('/pesquisar', [JobController::class, 'search'])->name('search');

Route::get('/articles', [ArticleController::class, 'index'])->middleware('cache.public');
Route::get('/articles/{id}', [ArticleController::class, 'getById'])
    ->where('id', '[0-9]+')->middleware('cache.public');
Route::get('/articles/{slug}', [ArticleController::class, 'getBySlug'])->middleware('cache.public');
//Route::get('/articles/{slug}/amp', [ArticleController::class, 'getBySlugAMP']);

Route::get('/modelos-de-curriculos', [CurriculoController::class, 'index'])->middleware('cache.public');
Route::get('/modelos-de-curriculos/{slug}', [CurriculoController::class, 'getBySlug'])->middleware('cache.public');

Route::get('/onlineocr', [OcrController::class, 'index'])->name('ocr')->middleware('cache.public');
Route::get('/quiz', [OcrController::class, 'indexQuizPt'])->name('quiz');
Route::get('/en/onlineocr', [OcrController::class, 'indexEn'])->name('ocren');
Route::get('/en/dashboard', [OcrController::class, 'indexDashboardEn'])->name('dashboardEn');
Route::get('/dashboard', [OcrController::class, 'indexDashboardPt'])->name('dashboardPt');

Route::get('/sitemap.xml', [JobController::class, 'siteMapGenerator'])->name('sitemap')->middleware('cache.public');
Route::get('/feed', [JobController::class, 'feedGenerator'])->name('feed')->middleware('cache.public');
