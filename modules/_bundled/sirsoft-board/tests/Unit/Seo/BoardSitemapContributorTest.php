<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Seo;

require_once __DIR__.'/../../ModuleTestCase.php';

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
        $this->contributor = new BoardSitemapContributor();
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
        \Illuminate\Support\Facades\DB::table('board_posts')
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
}
