<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Board\Listeners\SeoBoardCacheListener;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 SEO 캐시 리스너 단위 테스트
 *
 * SeoBoardCacheListener의 캐시 무효화 로직을 검증합니다.
 * - 훅 구독 등록 확인 (create → onPostCreate, update → onPostUpdate, delete → onPostDelete)
 * - 게시판별 독립 무효화: 해당 게시글 URL + 해당 게시판 목록 URL만 무효화
 * - 전역 레이아웃(home, search/index)은 invalidateByLayout 유지
 * - 생성/수정 시 단건 캐시 재생성
 * - 삭제 시 재생성 없이 무효화만
 * - 예외 발생 시 graceful 처리
 */
class SeoBoardCacheListenerTest extends ModuleTestCase
{
    private SeoBoardCacheListener $listener;

    private SeoCacheManagerInterface $cacheMock;

    private SeoCacheRegenerator $regeneratorMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);
        $this->app->instance(SeoCacheManagerInterface::class, $this->cacheMock);

        $this->regeneratorMock = Mockery::mock(SeoCacheRegenerator::class);
        $this->app->instance(SeoCacheRegenerator::class, $this->regeneratorMock);

        $this->listener = new SeoBoardCacheListener;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── 훅 구독 등록 ──────────────────────────────────────

    /**
     * 훅 구독이 올바르게 등록되어 있는지 확인합니다.
     */
    public function test_get_subscribed_hooks_returns_correct_mapping(): void
    {
        $hooks = SeoBoardCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-board.post.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-board.post.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-board.post.after_delete', $hooks);

        // create → onPostCreate, update → onPostUpdate, delete → onPostDelete
        $this->assertEquals('onPostCreate', $hooks['sirsoft-board.post.after_create']['method']);
        $this->assertEquals('onPostUpdate', $hooks['sirsoft-board.post.after_update']['method']);
        $this->assertEquals('onPostDelete', $hooks['sirsoft-board.post.after_delete']['method']);

        // 모든 우선순위 20
        $this->assertEquals(20, $hooks['sirsoft-board.post.after_create']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-board.post.after_update']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-board.post.after_delete']['priority']);
    }

    /**
     * 게시판 수정 훅(board.after_update) 구독이 등록되어 있는지 확인합니다. (#413-17)
     *
     * 이 훅 미구독이 "게시판 정보 수정 후 SEO 메타 캐시 미무효화" 버그의 원인이었습니다.
     *
     * @scenario name_changed=no, description_changed=no
     * @effects board_after_update_hook_subscribed
     */
    public function test_subscribes_board_after_update_hook(): void
    {
        $hooks = SeoBoardCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-board.board.after_update', $hooks);
        $this->assertEquals('onBoardUpdate', $hooks['sirsoft-board.board.after_update']['method']);
        $this->assertEquals(20, $hooks['sirsoft-board.board.after_update']['priority']);
    }

    // ─── onBoardUpdate (#413-17) ───────────────────────────

    /**
     * 게시판 수정 시 해당 게시판 SEO 캐시가 무효화되는지 확인합니다. (#413-17)
     *
     * 게시판 이름/설명 변경은 게시판 목록(/board/{slug})과 인덱스(/boards)
     * 메타에 노출되므로, 이 두 URL + 전역 레이아웃(home, search/index) +
     * sitemap 캐시를 무효화해야 합니다. (게시글 상세는 board name 미노출이라 제외)
     *
     * @scenario name_changed=yes, description_changed=no
     * @effects board_update_invalidates_board_list_url, board_update_invalidates_boards_index_url, board_update_invalidates_home_and_search_layouts, board_update_invalidates_sitemap_cache
     */
    public function test_on_board_update_invalidates_board_seo_caches(): void
    {
        $this->assertBoardUpdateInvalidatesAll('free');
    }

    /**
     * 이름만 미변경·설명만 변경된 경우에도 동일하게 무효화된다. (cross product 커버)
     *
     * @scenario name_changed=no, description_changed=yes
     */
    public function test_on_board_update_invalidates_when_only_description_changed(): void
    {
        $this->assertBoardUpdateInvalidatesAll('notice');
    }

    /**
     * 이름·설명 모두 변경된 경우에도 동일하게 무효화된다. (cross product 커버)
     *
     * @scenario name_changed=yes, description_changed=yes
     */
    public function test_on_board_update_invalidates_when_both_changed(): void
    {
        $this->assertBoardUpdateInvalidatesAll('qna');
    }

    /**
     * 게시판 수정 무효화 중 예외 발생 시 graceful하게 처리되는지 확인합니다. (#413-17)
     *
     * @effects board_update_invalidation_graceful_on_exception
     */
    public function test_on_board_update_handles_exceptions_gracefully(): void
    {
        $board = (object) ['id' => 1, 'slug' => 'free'];

        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andThrow(new \RuntimeException('Cache service unavailable'));
        $this->cacheMock->shouldReceive('invalidateByLayout')->andReturn(1);

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        // 예외가 외부로 전파되지 않아야 함
        $this->listener->onBoardUpdate($board, [], []);

        $this->addToAssertionCount(1);
    }

    // ─── onPostCreate ──────────────────────────────────────

    /**
     * 게시글 생성 시 해당 게시판의 캐시만 무효화 + 단건 재생성이 수행되는지 확인합니다.
     */
    public function test_on_post_create_invalidates_caches_and_regenerates_detail(): void
    {
        $post = (object) ['id' => 42];
        $slug = 'free';

        $this->expectPerBoardInvalidations($post, $slug);

        // 단건 재생성 호출 확인
        $this->regeneratorMock->shouldReceive('renderAndCache')
            ->once()
            ->with('/board/free/42')
            ->andReturn(true);

        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onPostCreate($post, $slug);

        $this->addToAssertionCount(1);
    }

    // ─── onPostUpdate ──────────────────────────────────────

    /**
     * 게시글 수정 시 해당 게시판의 캐시만 무효화 + 단건 재생성이 수행되는지 확인합니다.
     */
    public function test_on_post_update_invalidates_caches_and_regenerates_detail(): void
    {
        $post = (object) ['id' => 10];
        $slug = 'notice';

        $this->expectPerBoardInvalidations($post, $slug);

        // 단건 재생성 호출 확인
        $this->regeneratorMock->shouldReceive('renderAndCache')
            ->once()
            ->with('/board/notice/10')
            ->andReturn(true);

        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onPostUpdate($post, $slug);

        $this->addToAssertionCount(1);
    }

    /**
     * 게시글 수정 시 home과 search/index 전역 캐시도 무효화되는지 확인합니다.
     */
    public function test_on_post_update_invalidates_home_and_search(): void
    {
        $post = (object) ['id' => 5];
        $slug = 'free';

        $invokedUrls = [];
        $invokedLayouts = [];

        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andReturnUsing(function (string $url) use (&$invokedUrls) {
                $invokedUrls[] = $url;

                return 1;
            });

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->andReturnUsing(function (string $layout) use (&$invokedLayouts) {
                $invokedLayouts[] = $layout;

                return 1;
            });

        $this->regeneratorMock->shouldReceive('renderAndCache')->once()->andReturn(true);
        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onPostUpdate($post, $slug);

        // 게시판별 URL 무효화 확인
        $this->assertContains('/board/free/5', $invokedUrls);
        $this->assertContains('/board/free', $invokedUrls);

        // 전역 레이아웃 무효화 확인
        $this->assertContains('home', $invokedLayouts);
        $this->assertContains('search/index', $invokedLayouts);

        // board/show, board/index는 호출되지 않아야 함 (게시판별 독립 무효화)
        $this->assertNotContains('board/show', $invokedLayouts);
        $this->assertNotContains('board/index', $invokedLayouts);
    }

    /**
     * 다른 게시판(notice)의 게시글 수정 시 해당 게시판 URL만 무효화되는지 확인합니다.
     */
    public function test_on_post_update_only_invalidates_own_board(): void
    {
        $post = (object) ['id' => 15];
        $slug = 'notice';

        $invokedUrls = [];

        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andReturnUsing(function (string $url) use (&$invokedUrls) {
                $invokedUrls[] = $url;

                return 1;
            });

        $this->cacheMock->shouldReceive('invalidateByLayout')->andReturn(1);
        $this->regeneratorMock->shouldReceive('renderAndCache')->once()->andReturn(true);
        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onPostUpdate($post, $slug);

        // notice 게시판 URL만 무효화
        $this->assertContains('/board/notice/15', $invokedUrls);
        $this->assertContains('/board/notice', $invokedUrls);

        // free 게시판 URL은 무효화되지 않음
        foreach ($invokedUrls as $url) {
            $this->assertStringNotContainsString('/board/free', $url);
        }
    }

    // ─── onPostDelete ──────────────────────────────────────

    /**
     * 게시글 삭제 시 해당 게시판 캐시만 무효화되고 재생성은 하지 않는지 확인합니다.
     */
    public function test_on_post_delete_invalidates_caches_without_regeneration(): void
    {
        $post = (object) ['id' => 77];
        $slug = 'free';

        $this->expectPerBoardInvalidations($post, $slug);

        // 재생성은 호출되지 않아야 함
        $this->regeneratorMock->shouldNotReceive('renderAndCache');

        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onPostDelete($post, $slug);

        $this->addToAssertionCount(1);
    }

    // ─── 예외 처리 ──────────────────────────────────────

    /**
     * 캐시 무효화 중 예외 발생 시 graceful하게 처리되는지 확인합니다.
     */
    public function test_handles_invalidation_exceptions_gracefully(): void
    {
        $post = (object) ['id' => 10];
        $slug = 'free';

        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andThrow(new \RuntimeException('Cache service unavailable'));

        // invalidateByUrl 예외 후에도 regeneration은 시도됨
        $this->regeneratorMock->shouldReceive('renderAndCache')->once()->andReturn(true);

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Board post cache invalidation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Cache service unavailable';
            }));
        Log::shouldReceive('debug')->atLeast()->once();

        // 예외가 외부로 전파되지 않아야 함
        $this->listener->onPostUpdate($post, $slug);

        $this->addToAssertionCount(1);
    }

    /**
     * 재생성 중 예외 발생 시 graceful하게 처리되는지 확인합니다.
     */
    public function test_handles_regeneration_exceptions_gracefully(): void
    {
        $post = (object) ['id' => 10];
        $slug = 'free';

        $this->expectPerBoardInvalidations($post, $slug);

        $this->regeneratorMock->shouldReceive('renderAndCache')
            ->andThrow(new \RuntimeException('Render failed'));

        Log::shouldReceive('debug')->atLeast()->once();
        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Board post detail cache regeneration failed', Mockery::type('array'));

        // 예외가 외부로 전파되지 않아야 함
        $this->listener->onPostCreate($post, $slug);

        $this->addToAssertionCount(1);
    }

    // ─── handle (인터페이스 준수) ───────────────────────────

    /**
     * handle 메서드가 존재하는지 확인합니다 (HookListenerInterface 준수).
     */
    public function test_handle_method_exists(): void
    {
        $this->assertTrue(method_exists($this->listener, 'handle'));
        $this->listener->handle();
    }

    // ─── 헬퍼 ──────────────────────────────────────────────

    /**
     * 게시판 수정 시 해당 게시판 SEO 캐시 전체가 무효화되는지 단언합니다. (#413-17)
     *
     * 무효화 대상: /board/{slug} · /boards URL + home·search/index 레이아웃 + seo.sitemap 캐시.
     *
     * @param  string  $slug  대상 게시판 슬러그
     */
    private function assertBoardUpdateInvalidatesAll(string $slug): void
    {
        $board = (object) ['id' => 1, 'slug' => $slug];

        $invokedUrls = [];
        $invokedLayouts = [];

        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andReturnUsing(function (string $url) use (&$invokedUrls) {
                $invokedUrls[] = $url;

                return 1;
            });

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->andReturnUsing(function (string $layout) use (&$invokedLayouts) {
                $invokedLayouts[] = $layout;

                return 1;
            });

        // sitemap 캐시(seo.sitemap) forget 검증 — CacheInterface
        $cacheInterfaceMock = Mockery::mock(\App\Contracts\Extension\CacheInterface::class);
        $cacheInterfaceMock->shouldReceive('forget')->once()->with('seo.sitemap');
        $this->app->instance(\App\Contracts\Extension\CacheInterface::class, $cacheInterfaceMock);

        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onBoardUpdate($board, [], []);

        // 게시판 목록 + 인덱스 URL 무효화 (정확 매칭 + 쿼리스트링 변종 와일드카드)
        $this->assertContains("/board/{$slug}", $invokedUrls);
        $this->assertContains("/board/{$slug}?*", $invokedUrls);
        $this->assertContains('/boards', $invokedUrls);
        $this->assertContains('/boards?*', $invokedUrls);

        // 전역 레이아웃 무효화 (게시판명이 목록/검색에 노출될 수 있음)
        $this->assertContains('home', $invokedLayouts);
        $this->assertContains('search/index', $invokedLayouts);
    }

    /**
     * 게시판별 캐시 무효화 기대값을 설정합니다.
     *
     * URL 기반 무효화: 해당 게시글 상세 + 해당 게시판 목록
     * 레이아웃 기반 무효화: home, search/index (전역)
     *
     * @param  object  $post  게시글 객체
     * @param  string  $slug  게시판 슬러그
     */
    private function expectPerBoardInvalidations(object $post, string $slug): void
    {
        // 해당 게시글 상세 URL만 무효화
        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->once()
            ->with("/board/{$slug}/{$post->id}");

        // 해당 게시판 목록 URL만 무효화
        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->once()
            ->with("/board/{$slug}");

        // 전역 레이아웃 무효화
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()->with('home');
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()->with('search/index');
    }
}
