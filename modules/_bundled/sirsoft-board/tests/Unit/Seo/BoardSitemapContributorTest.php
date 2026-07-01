<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Seo;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
        $this->contributor = new BoardSitemapContributor;
    }

    /**
     * getIdentifier: 'sirsoft-board' 반환
     */
    public function test_get_identifier_returns_sirsoft_board(): void
    {
        $this->assertSame('sirsoft-board', $this->contributor->getIdentifier());
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
}
