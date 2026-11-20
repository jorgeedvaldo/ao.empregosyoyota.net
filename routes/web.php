<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group (sessão, cookies, CSRF).
|
| As páginas públicas de leitura estão em routes/public.php, sem sessão.
|
*/

Route::get('/linkstorage', function () {
    Artisan::call('storage:link');
});
