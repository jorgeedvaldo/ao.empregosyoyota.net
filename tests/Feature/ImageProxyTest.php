<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageProxyTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_fetches_once_stores_the_image_and_serves_it_as_a_file_with_long_cache_headers(): void
    {
        Http::fake(['empregosyoyota.net/*' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg'])]);

        $first = $this->get('/storage/images/jobs/foto.jpg')->assertOk();
        $first->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('max-age=31536000', $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', $first->headers->get('Cache-Control'));

        // Serve o ficheiro do disco em vez de o carregar para memória.
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $first->baseResponse);
        $this->assertSame(self::JPEG, Storage::disk('public')->get('images/jobs/foto.jpg'));

        $this->get('/storage/images/jobs/foto.jpg')->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://empregosyoyota.net/storage/images/jobs/foto.jpg');
    }

    public function test_the_origin_request_uses_short_timeouts(): void
    {
        $options = null;
        Http::fake(function ($request, $given) use (&$options) {
            $options = $given;

            return Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']);
        });

        $this->get('/storage/images/articles/default.jpg')->assertOk();

        $this->assertEquals(3, $options['timeout']);
        $this->assertEquals(2, $options['connect_timeout']);
    }

    public function test_an_image_already_on_disk_is_served_without_asking_the_origin(): void
    {
        Http::fake();
        Storage::disk('public')->put('images/logo.png', 'png-bytes');

        $this->get('/storage/images/logo.png')->assertOk()->assertHeader('Content-Type', 'image/png');

        Http::assertNothingSent();
    }

    public function test_content_type_follows_the_extension(): void
    {
        Http::fake(['*' => Http::response('x', 200, ['Content-Type' => 'image/anything'])]);

        foreach (['a.png' => 'image/png', 'b.webp' => 'image/webp', 'c.gif' => 'image/gif', 'd.svg' => 'image/svg+xml', 'e.jpeg' => 'image/jpeg'] as $file => $type) {
            $this->get('/storage/images/'.$file)->assertOk()->assertHeader('Content-Type', $type);
        }
    }

    public function test_a_missing_image_is_remembered_for_an_hour(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->get('/storage/images/jobs/nao-existe.jpg')->assertNotFound();
        $this->get('/storage/images/jobs/nao-existe.jpg')->assertNotFound();

        Http::assertSentCount(1);
        $this->assertTrue(Cache::has('img404:jobs/nao-existe.jpg'));

        $this->travel(61)->minutes();

        $this->get('/storage/images/jobs/nao-existe.jpg')->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_origin_errors_are_remembered_only_briefly(): void
    {
        // A origem falha uma vez e depois recupera.
        Http::fake(['*' => Http::sequence()
            ->pushStatus(503)
            ->push(self::JPEG, 200, ['Content-Type' => 'image/jpeg'])]);

        $this->get('/storage/images/jobs/foto.jpg')->assertNotFound();
        $this->get('/storage/images/jobs/foto.jpg')->assertNotFound();
        Http::assertSentCount(1);

        $this->travel(2)->minutes();

        $this->get('/storage/images/jobs/foto.jpg')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_a_timeout_gives_a_404_instead_of_an_error_and_is_not_retried_straight_away(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->get('/storage/images/jobs/lenta.jpg')->assertNotFound();
        $this->get('/storage/images/jobs/lenta.jpg')->assertNotFound();

        $this->assertSame(1, $attempts, 'O segundo pedido não devia voltar a esperar pela origem.');
        $this->assertTrue(Cache::has('img404:jobs/lenta.jpg'));
    }

    public function test_a_200_that_is_not_an_image_is_never_stored(): void
    {
        Http::fake(['*' => Http::response('<html>Não encontrado</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->get('/storage/images/jobs/falsa.jpg')->assertNotFound();

        Storage::disk('public')->assertMissing('images/jobs/falsa.jpg');
        $this->assertTrue(Cache::has('img404:jobs/falsa.jpg'));
    }

    public function test_unsafe_paths_never_reach_the_origin(): void
    {
        Http::fake();

        $this->get('/storage/images/..%2f..%2fetc%2fpasswd')->assertNotFound();
        $this->get('/storage/images/jobs/../../.env')->assertNotFound();
        $this->get('/storage/images/jobs/ficheiro.php')->assertNotFound();
        $this->get('/storage/images/jobs/.htaccess')->assertNotFound();
        $this->get('/storage/images/jobs/a%00.jpg')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_origin_host_is_fixed(): void
    {
        Http::fake(['*' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg'])]);

        $this->get('/storage/images/http%3A%2F%2Fevil.example%2Fx.jpg')->assertNotFound();
        $this->get('/storage/images/jobs/ok.jpg', ['Host' => 'evil.example'])->assertOk();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://empregosyoyota.net/storage/images/'));
        Http::assertSentCount(1);
    }
}
