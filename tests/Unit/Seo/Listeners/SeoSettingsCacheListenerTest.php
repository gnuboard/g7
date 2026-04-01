<?php

namespace Tests\Unit\Seo\Listeners;

use App\Listeners\SeoSettingsCacheListener;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * 코어 SEO 설정 리스너 테스트
 *
 * 코어 환경설정 SEO 탭 저장 시 전체 캐시 삭제를 검증합니다.
 */
class SeoSettingsCacheListenerTest extends TestCase
{
    private SeoSettingsCacheListener $listener;

    private SeoCacheManagerInterface $cacheMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);
        $this->app->instance(SeoCacheManagerInterface::class, $this->cacheMock);

        $this->listener = new SeoSettingsCacheListener;
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
        $hooks = SeoSettingsCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.settings.after_save', $hooks);
        $this->assertEquals('onSettingsSave', $hooks['core.settings.after_save']['method']);
        $this->assertEquals(20, $hooks['core.settings.after_save']['priority']);
    }

    // ─── SEO 탭 저장 ──────────────────────────────────────

    /**
     * SEO 탭 저장 시 전체 캐시 삭제 + sitemap 삭제 확인
     */
    public function test_on_settings_save_clears_all_cache_for_seo_tab(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Cache::shouldReceive('forget')
            ->once()
            ->with('seo:sitemap');

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Core SEO settings changed — all cache cleared', Mockery::type('array'));

        $this->listener->onSettingsSave('seo', ['cache_ttl' => 3600], ['success' => true]);

        $this->addToAssertionCount(1);
    }

    // ─── 비-SEO 탭 저장 ──────────────────────────────────────

    /**
     * SEO 탭이 아닌 경우 아무 동작도 하지 않는지 확인
     */
    public function test_on_settings_save_does_nothing_for_non_seo_tab(): void
    {
        $this->cacheMock->shouldNotReceive('clearAll');

        $this->listener->onSettingsSave('general', ['site_name' => 'Test'], ['success' => true]);

        $this->addToAssertionCount(1);
    }

    /**
     * 탭이 null인 경우 무시하는지 확인
     */
    public function test_on_settings_save_ignores_null_tab(): void
    {
        $this->cacheMock->shouldNotReceive('clearAll');

        $this->listener->onSettingsSave(null);

        $this->addToAssertionCount(1);
    }

    // ─── 예외 처리 ──────────────────────────────────────

    /**
     * 캐시 삭제 중 예외 발생 시 graceful하게 처리되는지 확인
     */
    public function test_handles_exceptions_gracefully(): void
    {
        $this->cacheMock->shouldReceive('clearAll')
            ->andThrow(new \RuntimeException('Cache unavailable'));

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Core SEO settings cache invalidation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Cache unavailable';
            }));

        $this->listener->onSettingsSave('seo', [], []);

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
