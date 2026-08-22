<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Seo;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Seo\AbstractSitemapContributor;
use App\Seo\Contracts\SitemapContributorInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Seo\BoardSitemapContributor;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * BoardSitemapContributor 단위 테스트
 *
 * 검증 목적:
 * - getIdentifier: 'sirsoft-board' 반환
 * - getUrls: /boards 항목 포함
 * - getUrls: 활성 게시판 URL 포함
 * - getUrls: 비활성 게시판 URL 미포함
 * - getUrls: 공개 게시글 URL 포함
 * - getUrls: 비밀글 URL 미포함
 * - getUrls: blinded/deleted 게시글 URL 미포함
 * - getUrls: 각 항목에 url 키 존재
 *
 * @group board
 * @group unit
 * @group seo
 */
class BoardSitemapContributorTest extends BoardTestCase
{
    private BoardSitemapContributor $contributor;

    protected function getTestBoardSlug(): string
    {
        return 'sitemap-test';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '사이트맵 테스트 게시판', 'en' => 'Sitemap Test Board'],
            'is_active' => true,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Repository 주입이 필요하므로 컨테이너로 해석합니다.
        $this->contributor = $this->app->make(BoardSitemapContributor::class);
    }

    /**
     * SitemapContributorInterface 를 구현한다
     */
    public function test_implements_sitemap_contributor_interface(): void
    {
        $this->assertInstanceOf(SitemapContributorInterface::class, $this->contributor);
    }

    /**
     * getIdentifier: 'sirsoft-board' 반환
     */
    public function test_get_identifier_returns_sirsoft_board(): void
    {
        $this->assertSame('sirsoft-board', $this->contributor->getIdentifier());
    }

    /**
     * getUrls: 활성 게시판이 없으면 정적 목록 URL만 반환한다
     */
    public function test_get_urls_returns_only_static_url_when_no_active_boards(): void
    {
        // "활성 게시판이 0개" 전제를 이 테스트가 직접 만든다.
        // 자기 게시판만 비활성화하면, 트랜잭션을 쓰지 않는 다른 테스트 클래스가 남긴
        // 게시판이 활성 상태로 남아 있어 전제가 성립하지 않는다.
        Board::query()->update(['is_active' => false]);
        $this->board->refresh();

        $urls = $this->contributor->getUrls();

        $this->assertSame(['/boards'], array_column($urls, 'url'));
    }

    /**
     * getUrls: 기여자당 URL 안전 상한을 초과하면 게시글 URL이 잘린다
     */
    public function test_get_urls_truncates_at_max_urls_per_contributor(): void
    {
        $this->createTestPost(['status' => 'published', 'is_secret' => false]);
        $this->createTestPost(['status' => 'published', 'is_secret' => false]);

        // /boards + /board/{slug} 로 이미 2건이므로 게시글은 1건까지만 담긴다.
        // g7_core_settings 는 Config 파사드 기반이므로 Config::set 으로 주입합니다.
        Config::set('g7_settings.core.seo.sitemap_max_urls_per_contributor', 3);

        $this->assertCount(3, $this->contributor->getUrls());
    }

    /**
     * getUrls: 상한이 게시판 루프에도 적용된다 (회귀)
     *
     * 상한 검사가 게시글 루프에만 있으면, 게시판 URL 만으로 상한을 초과해도 잘리지 않는다.
     */
    public function test_get_urls_truncates_within_board_loop(): void
    {
        $this->createTestPost(['status' => 'published', 'is_secret' => false]);

        // /boards 1건만 담기면 상한 도달 → 게시판/게시글 URL 은 하나도 담기지 않아야 한다.
        Config::set('g7_settings.core.seo.sitemap_max_urls_per_contributor', 1);

        $urls = $this->contributor->getUrls();

        $this->assertCount(1, $urls, '게시판 루프에서도 상한이 지켜져야 합니다.');
        $this->assertSame('/boards', $urls[0]['url']);
    }

    /**
     * getUrls: /boards 항목이 반드시 포함된다
     */
    public function test_get_urls_includes_boards_index(): void
    {
        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertContains('/boards', $urlPaths);
    }

    /**
     * getUrls: 활성 게시판 URL이 포함된다
     */
    public function test_get_urls_includes_active_board(): void
    {
        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertContains("/board/{$this->board->slug}", $urlPaths);
    }

    /**
     * getUrls: 비활성 게시판은 포함되지 않는다
     */
    public function test_get_urls_excludes_inactive_board(): void
    {
        $this->updateBoardSettings(['is_active' => false]);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains("/board/{$this->board->slug}", $urlPaths);
    }

    /**
     * getUrls: 공개(published) 게시글 URL이 포함된다
     */
    public function test_get_urls_includes_published_post(): void
    {
        $postId = $this->createTestPost(['status' => 'published', 'is_secret' => false]);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertContains("/board/{$this->board->slug}/{$postId}", $urlPaths);
    }

    /**
     * getUrls: 비밀글은 포함되지 않는다
     */
    public function test_get_urls_excludes_secret_post(): void
    {
        $postId = $this->createTestPost(['status' => 'published', 'is_secret' => true]);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains("/board/{$this->board->slug}/{$postId}", $urlPaths);
    }

    /**
     * getUrls: blinded 게시글은 포함되지 않는다
     */
    public function test_get_urls_excludes_blinded_post(): void
    {
        $postId = $this->createTestPost(['status' => 'blinded', 'is_secret' => false]);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains("/board/{$this->board->slug}/{$postId}", $urlPaths);
    }

    /**
     * getUrls: soft-deleted 게시글은 포함되지 않는다
     */
    public function test_get_urls_excludes_deleted_post(): void
    {
        $postId = $this->createTestPost(['status' => 'published', 'is_secret' => false]);
        DB::table('board_posts')
            ->where('id', $postId)
            ->update(['deleted_at' => now()]);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains("/board/{$this->board->slug}/{$postId}", $urlPaths);
    }

    /**
     * getUrls: 모든 항목에 url 키가 존재한다
     */
    public function test_get_urls_all_items_have_url_key(): void
    {
        $this->createTestPost(['status' => 'published', 'is_secret' => false]);

        $urls = $this->contributor->getUrls();

        foreach ($urls as $item) {
            $this->assertArrayHasKey('url', $item, '모든 항목에 url 키가 있어야 합니다.');
        }
    }

    /**
     * getUrls: 게시판 항목에 changefreq와 priority가 있다
     */
    public function test_get_urls_board_item_has_changefreq_and_priority(): void
    {
        $urls = $this->contributor->getUrls();
        $boardItem = collect($urls)->firstWhere('url', "/board/{$this->board->slug}");

        $this->assertNotNull($boardItem);
        $this->assertArrayHasKey('changefreq', $boardItem);
        $this->assertArrayHasKey('priority', $boardItem);
    }

    /**
     * getUrls: seo_boards 토글 OFF 시 /boards 목록 URL이 제외된다 (회귀)
     */
    public function test_get_urls_excludes_boards_index_when_toggle_off(): void
    {
        Config::set('g7_settings.modules.sirsoft-board.seo.seo_boards', false);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains('/boards', $urlPaths);
    }

    /**
     * getUrls: seo_board 토글 OFF 시 개별 게시판 URL이 제외된다 (회귀)
     */
    public function test_get_urls_excludes_board_detail_when_toggle_off(): void
    {
        Config::set('g7_settings.modules.sirsoft-board.seo.seo_board', false);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains("/board/{$this->board->slug}", $urlPaths);
    }

    /**
     * getUrls: seo_post_detail 토글 OFF 시 게시글 상세 URL이 제외된다 (회귀)
     */
    public function test_get_urls_excludes_post_detail_when_toggle_off(): void
    {
        $postId = $this->createTestPost(['status' => 'published', 'is_secret' => false]);
        Config::set('g7_settings.modules.sirsoft-board.seo.seo_post_detail', false);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertNotContains("/board/{$this->board->slug}/{$postId}", $urlPaths);
        // 게시판 URL 자체는 토글이 켜져 있으므로 유지된다
        $this->assertContains("/board/{$this->board->slug}", $urlPaths);
    }

    /**
     * getUrls: 토글이 모두 켜진 기본 상태에서는 모든 URL 유형이 포함된다 (비파괴 회귀)
     */
    public function test_get_urls_includes_all_when_toggles_default_on(): void
    {
        $postId = $this->createTestPost(['status' => 'published', 'is_secret' => false]);

        $urls = $this->contributor->getUrls();
        $urlPaths = array_column($urls, 'url');

        $this->assertContains('/boards', $urlPaths);
        $this->assertContains("/board/{$this->board->slug}", $urlPaths);
        $this->assertContains("/board/{$this->board->slug}/{$postId}", $urlPaths);
    }

    /**
     * getUrlsLazy: 배열을 실체화하지 않는 지연 제너레이터로 URL 을 흘려보낸다 (⑭ 스트리밍)
     *
     * 소비 경로는 getUrls() 가 아니라 getUrlsLazy() 이므로, 이것이 Traversable 로
     * 한 건씩 yield 되어야 대용량 게시글에서 메모리가 유계로 유지된다.
     */
    public function test_get_urls_lazy_streams_entries(): void
    {
        $postId = $this->createTestPost(['status' => 'published', 'is_secret' => false]);

        $this->assertInstanceOf(AbstractSitemapContributor::class, $this->contributor);

        $lazy = $this->contributor->getUrlsLazy();
        $this->assertInstanceOf(\Traversable::class, $lazy);

        $urlPaths = array_column(iterator_to_array($lazy, false), 'url');
        $this->assertContains("/board/{$this->board->slug}/{$postId}", $urlPaths);
    }
}
