<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Board\Listeners\SeoBoardRegenerateListener;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 SEO 무거운 후속 작업 리스너 (큐) 테스트
 *
 * 비우기는 SeoBoardCacheListener 가 sync 로 하고, 상세 다시 그리기와 게시판 재활성 재색인만 이 리스너가
 * 큐에서 맡는다(#724). 재색인 묶음·레이스 가드는 BoardSitemapLifecycleTest 가 이 리스너로 검증한다.
 */
class SeoBoardRegenerateListenerTest extends ModuleTestCase
{
    /**
     * 다시 그리기 대역
     *
     * @var MockInterface&SeoCacheRegenerator
     */
    private $regenerator;

    /**
     * 다시 그리기 서비스를 대역으로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->regenerator = Mockery::mock(SeoCacheRegenerator::class);
        $this->app->instance(SeoCacheRegenerator::class, $this->regenerator);
    }

    /**
     * 게시글 4훅과 게시판 수정 훅을 구독하고 sync 를 선언하지 않는다 — 무거운 일은 큐에 남는다.
     *
     * @scenario listener=seo_board, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_heavy_hooks_as_queued(): void
    {
        $hooks = SeoBoardRegenerateListener::getSubscribedHooks();

        foreach (['after_create', 'after_update', 'after_blind', 'after_restore'] as $event) {
            $this->assertSame('onPostChange', $hooks["sirsoft-board.post.{$event}"]['method']);
        }
        $this->assertSame('onBoardUpdate', $hooks['sirsoft-board.board.after_update']['method']);
        foreach ($hooks as $hook => $config) {
            $this->assertArrayNotHasKey('sync', $config, "{$hook} 는 큐로 돌아야 합니다.");
        }
    }

    /**
     * 게시글이 바뀌면 그 글 상세 URL 을 다시 그린다.
     *
     * @scenario listener=seo_board, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_post_change_regenerates_detail_url(): void
    {
        $this->regenerator->shouldReceive('renderAndCache')->once()->with('/board/free/42')->andReturn(true);
        Log::shouldReceive('debug')->atLeast()->once();

        (new SeoBoardRegenerateListener)->onPostChange((object) ['id' => 42], 'free');

        $this->addToAssertionCount(1);
    }

    /**
     * 다시 그리기가 실패해도 예외를 밖으로 내지 않고 경고만 남긴다.
     *
     * @scenario listener=seo_board, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_regeneration_failure_is_logged(): void
    {
        $this->regenerator->shouldReceive('renderAndCache')->andThrow(new \RuntimeException('Render failed'));
        Log::shouldReceive('warning')->once()->with('[SEO] Board post detail cache regeneration failed', Mockery::type('array'));

        (new SeoBoardRegenerateListener)->onPostChange((object) ['id' => 10], 'free');

        $this->addToAssertionCount(1);
    }

    /**
     * 글이나 게시판 slug 가 없으면 다시 그리지 않는다.
     *
     * @scenario listener=seo_board, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_post_change_without_post_or_slug_does_nothing(): void
    {
        $this->regenerator->shouldNotReceive('renderAndCache');

        $listener = new SeoBoardRegenerateListener;
        $listener->onPostChange(null, 'free');
        $listener->onPostChange((object) ['id' => 1], null);

        $this->addToAssertionCount(1);
    }
}
