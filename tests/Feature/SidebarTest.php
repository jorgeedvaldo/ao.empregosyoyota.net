<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPublicSchema;
use Tests\TestCase;

/**
 * As barras laterais "Últimas Oportunidades" e "Últimas Notícias" têm de
 * mostrar exactamente o que sempre mostraram, agora a partir de listas curtas.
 */
class SidebarTest extends TestCase
{
    use CreatesPublicSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPublicSchema();
    }

    /** @return array{jobs: array<int, string>, articles: array<int, string>} */
    private function sidebars(string $uri): array
    {
        $html = $this->get($uri)->assertOk()->getContent();

        preg_match_all('#<p class="mb-1"><b>(Vaga \d+)</b></p>#', $html, $jobs);
        preg_match_all('#<p class="mb-1"><b>(Notícia \d+)</b></p>#', $html, $articles);

        return ['jobs' => $jobs[1], 'articles' => $articles[1]];
    }

    public function test_job_page_sidebar_skips_the_current_job_and_lists_any_country_articles(): void
    {
        $this->seedContent(jobs: 8, articles: 8);
        DB::table('articles')->where('id', 8)->update(['country_id' => 2]);

        $sidebar = $this->sidebars('/empregos/vaga-8');

        $this->assertSame(['Vaga 7', 'Vaga 6', 'Vaga 5', 'Vaga 4', 'Vaga 3'], $sidebar['jobs']);
        $this->assertSame(['Notícia 8', 'Notícia 7', 'Notícia 6', 'Notícia 5', 'Notícia 4'], $sidebar['articles']);
    }

    public function test_job_page_for_an_old_job_still_shows_the_five_newest_others(): void
    {
        $this->seedContent(jobs: 70, articles: 8);

        // A vaga 5 está fora das 50 mais recentes em cache.
        $sidebar = $this->sidebars('/empregos/vaga-5');

        $this->assertSame(['Vaga 70', 'Vaga 69', 'Vaga 68', 'Vaga 67', 'Vaga 66'], $sidebar['jobs']);
    }

    public function test_the_current_job_is_skipped_when_it_is_among_the_newest(): void
    {
        $this->seedContent(jobs: 70, articles: 8);

        $sidebar = $this->sidebars('/jobs/70');

        $this->assertSame(['Vaga 69', 'Vaga 68', 'Vaga 67', 'Vaga 66', 'Vaga 65'], $sidebar['jobs']);
    }

    public function test_article_page_sidebar_skips_the_current_article_and_only_uses_country_one(): void
    {
        $this->seedContent(jobs: 8, articles: 8);
        DB::table('articles')->where('id', 8)->update(['country_id' => 2]);

        $sidebar = $this->sidebars('/articles/noticia-7');

        $this->assertSame(['Notícia 6', 'Notícia 5', 'Notícia 4', 'Notícia 3', 'Notícia 2'], $sidebar['articles']);
        $this->assertSame(['Vaga 8', 'Vaga 7', 'Vaga 6', 'Vaga 5', 'Vaga 4'], $sidebar['jobs']);
    }

    public function test_article_page_by_id_matches_the_slug_version(): void
    {
        $this->seedContent(jobs: 8, articles: 8);

        $this->assertSame($this->sidebars('/articles/noticia-5'), $this->sidebars('/articles/5'));
    }

    public function test_ocr_page_uses_the_newest_of_each(): void
    {
        $this->seedContent(jobs: 8, articles: 8);

        $sidebar = $this->sidebars('/onlineocr');

        $this->assertSame(['Vaga 8', 'Vaga 7', 'Vaga 6', 'Vaga 5', 'Vaga 4'], $sidebar['jobs']);
        $this->assertSame(['Notícia 8', 'Notícia 7', 'Notícia 6', 'Notícia 5', 'Notícia 4'], $sidebar['articles']);
    }

    public function test_pages_do_not_break_when_there_are_fewer_than_five_items(): void
    {
        $this->seedContent(jobs: 3, articles: 3);

        $this->assertSame(['Vaga 2', 'Vaga 1'], $this->sidebars('/empregos/vaga-3')['jobs']);
        $this->assertSame(['Notícia 2', 'Notícia 1'], $this->sidebars('/articles/noticia-3')['articles']);
        $this->get('/onlineocr')->assertOk();
    }

    public function test_detail_pages_never_load_the_whole_tables(): void
    {
        $this->seedContent(jobs: 120, articles: 120);

        $this->get('/empregos/vaga-120')->assertOk();        // aquece a cache

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/empregos/vaga-120')->assertOk();
        $this->get('/articles/noticia-120')->assertOk();
        $this->get('/onlineocr')->assertOk();
        $log = DB::getQueryLog();

        // Cada consulta tem de ter limite ou procurar uma só linha por chave.
        foreach ($log as $entry) {
            $sql = $entry['query'];
            $bounded = str_contains($sql, 'limit') || str_contains($sql, '"slug" =') || str_contains($sql, '"id" =');
            $this->assertTrue($bounded, 'Consulta sem limite nem chave: '.$sql);
        }
    }
}
