<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Board\Listeners\SeoBoardSettingsCacheListener;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 설정 SEO 캐시 리스너 테스트
 *
 * 게시판 모듈 설정 변경 시 관련 SEO 캐시 무효화를 검증합니다.
 */
class SeoBoardSettingsCacheListenerTest extends ModuleTestCase
{
    private SeoBoardSettingsCacheListener $listener;

    private SeoCacheManagerInterface $cacheMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);
        $this->app->instance(SeoCacheManagerInterface::class, $this->cacheMock);

        $this->listener = new SeoBoardSettingsCacheListener;
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
        $hooks = SeoBoardSettingsCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.module_settings.after_save', $hooks);
        $this->assertEquals('onModuleSettingsSave', $hooks['core.module_settings.after_save']['method']);
        $this->assertEquals(20, $hooks['core.module_settings.after_save']['priority']);
    }

    // ─── 게시판 모듈 설정 변경 ──────────────────────────────

    /**
     * 게시판 모듈 설정 변경 시 관련 레이아웃 캐시가 무효화되는지 확인합니다.
     */
    public function test_board_settings_change_invalidates_board_layouts(): void
    {
        $invokedLayouts = [];
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->andReturnUsing(function (string $layout) use (&$invokedLayouts) {
                $invokedLayouts[] = $layout;

                return 1;
            });

        Cache::shouldReceive('forget')
            ->once()
            ->with('seo:sitemap');

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Board module settings changed — board cache cleared');

        $this->listener->onModuleSettingsSave('sirsoft-board', ['some_setting' => 'value'], []);

        $this->assertContains('board/index', $invokedLayouts);
        $this->assertContains('board/show', $invokedLayouts);
        $this->assertContains('board/boards', $invokedLayouts);
    }

    // ─── 다른 모듈 필터링 ──────────────────────────────

    /**
     * 게시판 모듈이 아닌 경우 무시하는지 확인합니다.
     */
    public function test_ignores_non_board_module(): void
    {
        $this->cacheMock->shouldNotReceive('invalidateByLayout');

        $this->listener->onModuleSettingsSave('sirsoft-ecommerce', ['key' => 'val'], []);

        $this->addToAssertionCount(1);
    }

    /**
     * identifier가 null인 경우 무시하는지 확인합니다.
     */
    public function test_ignores_null_identifier(): void
    {
        $this->cacheMock->shouldNotReceive('invalidateByLayout');

        $this->listener->onModuleSettingsSave(null);

        $this->addToAssertionCount(1);
    }

    // ─── 예외 처리 ──────────────────────────────────────

    /**
     * 예외 발생 시 graceful하게 처리되는지 확인합니다.
     */
    public function test_handles_exceptions_gracefully(): void
    {
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->andThrow(new \RuntimeException('Cache failed'));

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Board settings cache invalidation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Cache failed';
            }));

        $this->listener->onModuleSettingsSave('sirsoft-board', [], []);

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
}
