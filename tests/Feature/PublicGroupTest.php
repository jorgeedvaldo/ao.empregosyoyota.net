<?php

namespace Tests\Feature;

use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class PublicGroupTest extends TestCase
{
    use CreatesPublicSchema;

    private const PUBLIC_URLS = [
        '/', '/about', '/terms', '/empregos', '/empregos/vaga-8', '/jobs', '/jobs/8', '/categories/1',
        '/pesquisar?query=vaga', '/articles', '/articles/noticia-8', '/articles/8',
        '/modelos-de-curriculos', '/modelos-de-curriculos/modelo-de-cv',
        '/onlineocr', '/quiz', '/en/onlineocr', '/dashboard', '/en/dashboard', '/sitemap.xml', '/feed',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedContent(jobs: 8, articles: 8);
        \DB::table('category_jobs')->insert(['category_id' => 1, 'job_id' => 4]);
    }

    /** @return array<int, Route> */
    private function publicRoutes(): array
    {
        return array_values(array_filter(
            app('router')->getRoutes()->getRoutes(),
            fn (Route $route) => in_array('public', $route->middleware(), true)
        ));
    }

    public function test_public_pages_start_no_session_and_set_no_cookies(): void
    {
        foreach (self::PUBLIC_URLS as $url) {
            $response = $this->get($url);

            $this->assertContains($response->getStatusCode(), [200], "{$url} devia abrir");
            $this->assertSame([], $response->headers->getCookies(), "{$url} não devia enviar cookies");
            $this->assertFalse(app('session.store')->isStarted(), "{$url} não devia iniciar sessão");
        }
    }

    public function test_the_public_group_has_none_of_the_session_middleware(): void
    {
        $routes = $this->publicRoutes();
        $this->assertGreaterThanOrEqual(20, count($routes));

        foreach ($routes as $route) {
            $resolved = app('router')->gatherRouteMiddleware($route);

            foreach ([StartSession::class, EncryptCookies::class, ShareErrorsFromSession::class, VerifyCsrfToken::class] as $session) {
                $this->assertNotContains($session, $resolved, "{$route->uri()} não devia usar {$session}");
            }
        }
    }

    public function test_public_routes_are_read_only(): void
    {
        // Sem CSRF, uma rota que escreva não pode viver neste grupo.
        foreach ($this->publicRoutes() as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods(), "{$route->uri()} escreve? Deve ficar em routes/web.php");
        }
    }

    public function test_not_found_pages_still_render_without_a_session(): void
    {
        foreach (['/empregos/nao-existe', '/articles/nao-existe', '/jobs/9999', '/categories/9999', '/modelos-de-curriculos/nao-existe', '/rota/que-nao-existe'] as $url) {
            $response = $this->get($url);

            $response->assertNotFound()->assertSee('Oops! Página Não Encontrada');
            $this->assertSame([], $response->headers->getCookies(), "{$url}");
        }
    }

    public function test_the_old_redirects_still_work_and_come_before_the_slug_routes(): void
    {
        $this->get('/empregos/aberto-o-concurso-publico-da-policia-nacional-de-angola')
            ->assertRedirect('/articles/policia-nacional-de-angola-desmente-boatos-de-concurso-publico');

        $this->get('/articles/novo-portal-de-recrutamento-da-policia-nacional-de-angola-revela-um-possivel-recrutamento')
            ->assertRedirect('/articles/policia-nacional-de-angola-desmente-boatos-de-concurso-publico');
    }

    public function test_the_admin_panel_still_uses_sessions_and_cookies(): void
    {
        $login = $this->get('/admin/login')->assertOk();

        $names = array_map(fn ($cookie) => $cookie->getName(), $login->headers->getCookies());
        $this->assertContains('laravel_session', $names, 'O painel tem de continuar a ter sessão.');
        $this->assertContains('XSRF-TOKEN', $names);

        $this->get('/admin')->assertRedirect();
    }

    public function test_the_web_group_still_covers_what_is_not_public(): void
    {
        $linkstorage = collect(app('router')->getRoutes()->getRoutes())->first(fn (Route $route) => $route->uri() === 'linkstorage');

        $this->assertContains(StartSession::class, app('router')->gatherRouteMiddleware($linkstorage));
    }
}
