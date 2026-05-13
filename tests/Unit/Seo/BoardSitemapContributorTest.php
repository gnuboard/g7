<?php

namespace Tests\Unit\Seo;

use App\Seo\Contracts\SitemapContributorInterface;
use Mockery;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Seo\BoardSitemapContributor;
use Tests\TestCase;

/**
 * BoardSitemapContributor 단위 테스트
 *
 * Board 모듈의 Sitemap 기여자가 올바른 식별자를 반환하고,
 * 게시판/게시글 URL을 올바르게 생성하는지 검증합니다.
 */
class BoardSitemapContributorTest extends TestCase
{
    private BoardSitemapContributor $contributor;

    /**
     * 테스트 초기화 - BoardSitemapContributor 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->contributor = new BoardSitemapContributor;
    }

    /**
     * 테스트 종료 - Mockery 리소스를 정리합니다.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * SitemapContributorInterface를 구현하는지 확인합니다.
     */
    public function test_implements_sitemap_contributor_interface(): void
    {
        $this->assertInstanceOf(SitemapContributorInterface::class, $this->contributor);
    }

    /**
     * getIdentifier()가 'sirsoft-board'를 반환하는지 확인합니다.
     */
    public function test_get_identifier_returns_correct_value(): void
    {
        $this->assertSame('sirsoft-board', $this->contributor->getIdentifier());
    }

    /**
     * getUrls()가 게시판/게시글 URL을 포함한 배열을 반환하는지 확인합니다.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_get_urls_returns_board_and_post_urls(): void
    {
        // Board::where('is_active', true)->get() Mock
        $boardCollection = collect([
            (object) ['id' => 1, 'slug' => 'notice', 'updated_at' => now()],
            (object) ['id' => 2, 'slug' => 'free', 'updated_at' => now()],
        ]);

        $boardQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $boardQuery->shouldReceive('get')
            ->with(['id', 'slug', 'updated_at'])
            ->andReturn($boardCollection);

        $boardMock = Mockery::mock('alias:'.Board::class);
        $boardMock->shouldReceive('where')
            ->with('is_active', true)
            ->andReturn($boardQuery);

        // Post::where(...)->where(...)->where(...)->get() Mock - notice 게시판
        $noticePostCollection = collect([
            (object) ['id' => 100, 'updated_at' => now()],
        ]);

        // Post::where(...)->where(...)->where(...)->get() Mock - free 게시판
        $freePostCollection = collect([
            (object) ['id' => 200, 'updated_at' => now()],
            (object) ['id' => 201, 'updated_at' => now()],
        ]);

        // Post 쿼리 빌더 체이닝 Mock
        $postQueryNotice = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $postQueryNotice->shouldReceive('where')
            ->with('status', PostStatus::Published)
            ->andReturnSelf();
        $postQueryNotice->shouldReceive('where')
            ->with('is_secret', false)
            ->andReturnSelf();
        $postQueryNotice->shouldReceive('get')
            ->with(['id', 'updated_at'])
            ->andReturn($noticePostCollection);

        $postQueryFree = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $postQueryFree->shouldReceive('where')
            ->with('status', PostStatus::Published)
            ->andReturnSelf();
        $postQueryFree->shouldReceive('where')
            ->with('is_secret', false)
            ->andReturnSelf();
        $postQueryFree->shouldReceive('get')
            ->with(['id', 'updated_at'])
            ->andReturn($freePostCollection);

        $postMock = Mockery::mock('alias:'.Post::class);
        $postMock->shouldReceive('where')
            ->with('board_id', 1)
            ->andReturn($postQueryNotice);
        $postMock->shouldReceive('where')
            ->with('board_id', 2)
            ->andReturn($postQueryFree);

        $contributor = new BoardSitemapContributor;
        $urls = $contributor->getUrls();

        $this->assertIsArray($urls);

        // 1(정적 목록) + 2(게시판) + 1(notice 게시글) + 2(free 게시글) = 6
        $this->assertCount(6, $urls);

        // 정적 게시판 목록 URL
        $this->assertSame('/boards', $urls[0]['url']);
        $this->assertSame('weekly', $urls[0]['changefreq']);
        $this->assertSame(0.5, $urls[0]['priority']);

        // notice 게시판 URL
        $this->assertSame('/board/notice', $urls[1]['url']);
        $this->assertSame('daily', $urls[1]['changefreq']);
        $this->assertSame(0.6, $urls[1]['priority']);

        // notice 게시판의 게시글 URL
        $this->assertSame('/board/notice/100', $urls[2]['url']);
        $this->assertSame('monthly', $urls[2]['changefreq']);
        $this->assertSame(0.5, $urls[2]['priority']);

        // free 게시판 URL
        $this->assertSame('/board/free', $urls[3]['url']);

        // free 게시판의 게시글 URL
        $this->assertSame('/board/free/200', $urls[4]['url']);
        $this->assertSame('/board/free/201', $urls[5]['url']);
    }

    /**
     * 활성 게시판이 없을 때 정적 URL만 반환하는지 확인합니다.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_get_urls_returns_only_static_url_when_no_active_boards(): void
    {
        $boardQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $boardQuery->shouldReceive('get')
            ->with(['id', 'slug', 'updated_at'])
            ->andReturn(collect([]));

        $boardMock = Mockery::mock('alias:'.Board::class);
        $boardMock->shouldReceive('where')
            ->with('is_active', true)
            ->andReturn($boardQuery);

        $contributor = new BoardSitemapContributor;
        $urls = $contributor->getUrls();

        $this->assertCount(1, $urls);
        $this->assertSame('/boards', $urls[0]['url']);
    }
}
