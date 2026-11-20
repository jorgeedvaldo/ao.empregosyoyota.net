<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

class CacheHeadersTest extends TestCase
{
    use CreatesPublicSchema;

    private const SHARED = [
        '/', '/about', '/terms', '/empregos', '/empregos/vaga-8', '/jobs', '/jobs/8', '/categories/1',
        '/articles', '/articles/noticia-8', '/articles/8', '/modelos-de-curriculos',
        '/modelos-de-curriculos/modelo-de-cv', '/onlineocr', '/sitemap.xml', '/feed',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
        $this->seedContent(jobs: 8, articles: 8);
        \DB::table('category_jobs')->insert(['category_id' => 1, 'job_id' => 4]);
    }

    public function test_public_read_pages_can_be_cached_for_five_minutes(): void
    {
        foreach (self::SHARED as $url) {
            $response = $this->get($url)->assertOk();

            $cacheControl = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('public', $cacheControl, $url);
            $this->assertStringContainsString('max-age=300', $cacheControl, $url);
            $response->assertHeader('X-LiteSpeed-Cache-Control', 'public,max-age=300');
        }
    }

    public function test_pages_that_must_not_be_shared_keep_the_private_default(): void
    {
        foreach (['/dashboard', '/en/dashboard', '/quiz', '/en/onlineocr', '/pesquisar?query=vaga', '/api/jobs', '/api/jobs/all', '/admin/login'] as $url) {
            $response = $this->get($url);

            $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'), $url);
            $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'), $url);
        }
    }

    public function test_errors_and_redirects_are_never_shared(): void
    {
        foreach (['/empregos/nao-existe', '/categories/9999', '/rota/inexistente'] as $url) {
            $response = $this->get($url)->assertNotFound();
            $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'), $url);
        }

        $redirect = $this->get('/empregos/aberto-o-concurso-publico-da-policia-nacional-de-angola');
        $this->assertStringNotContainsString('public', (string) $redirect->headers->get('Cache-Control'));
    }

    public function test_a_visitor_with_a_session_cookie_gets_no_public_headers(): void
    {
        $response = $this->withUnencryptedCookie(config('session.cookie'), 'qualquer-valor')->get('/')->assertOk();

        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
    }

    public function test_other_cookies_such_as_analytics_do_not_prevent_sharing(): void
    {
        $response = $this->withUnencryptedCookie('_ga', 'GA1.2.123')->get('/')->assertOk();

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_response_that_sets_a_cookie_is_never_shared(): void
    {
        Route::middleware('cache.public')->get('/_com-cookie', fn () => response('x')->cookie('a', 'b'));

        $response = $this->get('/_com-cookie')->assertOk();

        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function test_requests_with_credentials_and_non_cacheable_methods_are_never_shared(): void
    {
        $this->withHeader('Authorization', 'Bearer x')->get('/')->assertOk()
            ->assertHeaderMissing('X-LiteSpeed-Cache-Control');

        Route::middleware('cache.public')->post('/_escrita', fn () => response('x'));

        $this->post('/_escrita')->assertOk()->assertHeaderMissing('X-LiteSpeed-Cache-Control');
    }

    public function test_head_requests_get_the_same_headers_as_get(): void
    {
        $response = $this->call('HEAD', '/');

        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
    }
}
