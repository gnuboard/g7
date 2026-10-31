<?php

namespace Tests\Unit\Seo\Listeners;

use App\Listeners\SeoExtensionCacheListener;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * 확장 라이프사이클 SEO 캐시 무효화 리스너 테스트
 *
 * 모듈/플러그인/템플릿 설치·활성화·업데이트 시
 * 전체 SEO 캐시 삭제를 검증합니다.
 */
class SeoExtensionCacheListenerTest extends TestCase
{
    private SeoExtensionCacheListener $listener;

    private SeoCacheManagerInterface $cacheMock;

    /**
     * SEO 캐시 매니저를 목으로 바꾸고 리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);
        $this->app->instance(SeoCacheManagerInterface::class, $this->cacheMock);

        $this->listener = new SeoExtensionCacheListener;
    }

    /**
     * 목 기대값을 검증하고 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── 훅 구독 등록 ──────────────────────────────────────

    /**
     * 정규 생명주기 훅 15개(설치·활성화·비활성화·업데이트·제거 × 3종)가 동기 실행으로 등록되어 있는지 확인합니다.
     *
     * 옛 이름(installed·activated·updated)은 deprecated 라 구독하지 않는다 — 비활성화·제거가 빠져 있어
     * 끈 확장의 페이지가 SEO 캐시에 남았다(#138 후속).
     *
     * @scenario lifecycle=subscription_contract
     *
     * @effects seo_cache_listener_is_sync
     *
     * @return void 반환값 없음
     */
    public function test_get_subscribed_hooks_returns_all_15_lifecycle_hooks(): void
    {
        $hooks = SeoExtensionCacheListener::getSubscribedHooks();

        $expectedHooks = [];
        foreach (['modules', 'plugins', 'templates'] as $type) {
            $update = $type === 'templates' ? 'after_version_update' : 'after_update';
            foreach (['after_install', 'after_activate', 'after_deactivate', $update, 'after_uninstall'] as $event) {
                $expectedHooks[] = "core.{$type}.{$event}";
            }
        }

        $this->assertCount(15, $hooks);

        foreach ($expectedHooks as $hookName) {
            $this->assertArrayHasKey($hookName, $hooks);
            $this->assertEquals('onExtensionChanged', $hooks[$hookName]['method']);
            $this->assertEquals(30, $hooks[$hookName]['priority']);
            $this->assertTrue($hooks[$hookName]['sync'] ?? false);
        }

        foreach (['installed', 'activated', 'updated'] as $legacy) {
            $this->assertArrayNotHasKey("core.modules.{$legacy}", $hooks);
        }
    }

    // ─── 모듈 라이프사이클 ──────────────────────────────────────

    /**
     * 모듈 설치 시 전체 SEO 캐시 + sitemap 삭제 확인
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_cleared_on_extension_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_on_module_install_clears_all_seo_cache(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Extension changed — all cache cleared', Mockery::on(function ($context) {
                return $context['identifier'] === 'sirsoft-ecommerce';
            }));

        $this->listener->onExtensionChanged('sirsoft-ecommerce', ['name' => 'Ecommerce']);

        $this->addToAssertionCount(1);
    }

    /**
     * 모듈 활성화 시 전체 SEO 캐시 + sitemap 삭제 확인
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_cleared_on_extension_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_on_module_activate_clears_all_seo_cache(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Log::shouldReceive('info')->once();

        $this->listener->onExtensionChanged('sirsoft-ecommerce', ['name' => 'Ecommerce']);

        $this->addToAssertionCount(1);
    }

    /**
     * 모듈 업데이트 시 전체 SEO 캐시 + sitemap 삭제 확인
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_cleared_on_extension_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_on_module_update_clears_all_seo_cache(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Log::shouldReceive('info')->once();

        $this->listener->onExtensionChanged('sirsoft-ecommerce', ['success' => true], ['name' => 'Ecommerce']);

        $this->addToAssertionCount(1);
    }

    // ─── 플러그인 라이프사이클 ──────────────────────────────────────

    /**
     * 플러그인 설치 시 전체 SEO 캐시 + sitemap 삭제 확인
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_cleared_on_extension_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_on_plugin_install_clears_all_seo_cache(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Log::shouldReceive('info')->once();

        $this->listener->onExtensionChanged('sirsoft-payment', ['name' => 'Payment']);

        $this->addToAssertionCount(1);
    }

    // ─── 템플릿 라이프사이클 ──────────────────────────────────────

    /**
     * 템플릿 활성화 시 전체 SEO 캐시 + sitemap 삭제 확인
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_cleared_on_extension_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_on_template_activate_clears_all_seo_cache(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Log::shouldReceive('info')->once();

        // 템플릿 활성화는 Template model 객체가 전달됨
        $templateModel = Mockery::mock();
        $templateModel->shouldReceive('getIdentifier')
            ->andReturn('sirsoft-basic');

        $this->listener->onExtensionChanged($templateModel);

        $this->addToAssertionCount(1);
    }

    /**
     * 템플릿 버전 업데이트 시 전체 SEO 캐시 + sitemap 삭제 확인
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_cleared_on_extension_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_on_template_version_update_clears_all_seo_cache(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Log::shouldReceive('info')->once();

        $this->listener->onExtensionChanged('sirsoft-basic', ['success' => true], ['name' => 'Basic']);

        $this->addToAssertionCount(1);
    }

    // ─── 예외 처리 ──────────────────────────────────────

    /**
     * 캐시 삭제 중 예외 발생 시 graceful하게 처리되는지 확인
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_failure_does_not_break_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_handles_exceptions_gracefully(): void
    {
        $this->cacheMock->shouldReceive('clearAll')
            ->andThrow(new \RuntimeException('Cache unavailable'));

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Extension cache invalidation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Cache unavailable'
                    && $context['identifier'] === 'sirsoft-ecommerce';
            }));

        $this->listener->onExtensionChanged('sirsoft-ecommerce', ['name' => 'Ecommerce']);

        $this->addToAssertionCount(1);
    }

    // ─── handle (인터페이스 준수) ───────────────────────────

    /**
     * handle 메서드가 존재하는지 확인합니다 (HookListenerInterface 준수).
     *
     * @scenario lifecycle=subscription_contract
     *
     * @effects seo_cache_listener_is_sync
     *
     * @return void 반환값 없음
     */
    public function test_handle_method_exists(): void
    {
        $this->assertTrue(method_exists($this->listener, 'handle'));
        $this->listener->handle();
    }

    // ─── 로그 검증 ──────────────────────────────────────

    /**
     * 로그에 확장 식별자 정보가 포함되는지 확인
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_cleared_on_extension_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_log_includes_extension_context(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Extension changed — all cache cleared', Mockery::on(function ($context) {
                return isset($context['identifier'])
                    && $context['identifier'] === 'sirsoft-payment';
            }));

        $this->listener->onExtensionChanged('sirsoft-payment', ['name' => 'Payment']);

        $this->addToAssertionCount(1);
    }

    // ─── 빈 인자 처리 ──────────────────────────────────────

    /**
     * 인자 없이 호출해도 정상 동작하는지 확인
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_failure_does_not_break_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_does_not_fail_with_empty_args(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Extension changed — all cache cleared', Mockery::on(function ($context) {
                return $context['identifier'] === 'unknown';
            }));

        $this->listener->onExtensionChanged();

        $this->addToAssertionCount(1);
    }
}
