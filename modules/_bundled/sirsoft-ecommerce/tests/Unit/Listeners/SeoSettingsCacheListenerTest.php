<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\SeoSettingsCacheListener;
use Tests\TestCase;

/**
 * 이커머스 SEO 설정 리스너 테스트
 *
 * 이커머스 모듈 설정 변경 시 SEO 관련 캐시 선별 무효화를 검증합니다.
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

        $this->assertArrayHasKey('core.module_settings.after_save', $hooks);
        $this->assertEquals('onModuleSettingsSave', $hooks['core.module_settings.after_save']['method']);
        $this->assertEquals(20, $hooks['core.module_settings.after_save']['priority']);
    }

    // ─── 이커머스 모듈 필터링 ──────────────────────────────

    /**
     * 이커머스 모듈이 아닌 경우 무시하는지 확인
     */
    public function test_ignores_non_ecommerce_module(): void
    {
        $this->cacheMock->shouldNotReceive('invalidateByLayout');

        $this->listener->onModuleSettingsSave('sirsoft-board', ['seo' => ['meta_product_title' => 'test']], []);

        $this->addToAssertionCount(1);
    }

    // ─── 상품 메타 설정 변경 ──────────────────────────────

    /**
     * 상품 메타 설정 변경 시 shop/show 레이아웃만 무효화되는지 확인
     */
    public function test_product_meta_change_invalidates_shop_show(): void
    {
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->with('shop/show');

        Cache::shouldReceive('forget')
            ->once()
            ->with('seo:sitemap');

        Log::shouldReceive('info')->once();

        $this->listener->onModuleSettingsSave('sirsoft-ecommerce', [
            'seo' => ['meta_product_title' => '{{name}} - 쇼핑몰'],
        ], []);

        $this->addToAssertionCount(1);
    }

    // ─── 카테고리 메타 설정 변경 ──────────────────────────────

    /**
     * 카테고리 메타 설정 변경 시 shop/category + shop/index 무효화되는지 확인
     */
    public function test_category_meta_change_invalidates_shop_category_and_index(): void
    {
        $invokedLayouts = [];
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->andReturnUsing(function (string $layout) use (&$invokedLayouts) {
                $invokedLayouts[] = $layout;

                return 1;
            });

        Cache::shouldReceive('forget')->once()->with('seo:sitemap');
        Log::shouldReceive('info')->once();

        $this->listener->onModuleSettingsSave('sirsoft-ecommerce', [
            'seo' => ['meta_category_title' => '{{name}} 카테고리'],
        ], []);

        $this->assertContains('shop/category', $invokedLayouts);
        $this->assertContains('shop/index', $invokedLayouts);
    }

    // ─── 검색 메타 설정 변경 ──────────────────────────────

    /**
     * 검색 메타 설정 변경 시 search/index 무효화되는지 확인
     */
    public function test_search_meta_change_invalidates_search_index(): void
    {
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->with('search/index');

        Cache::shouldReceive('forget')->once()->with('seo:sitemap');
        Log::shouldReceive('info')->once();

        $this->listener->onModuleSettingsSave('sirsoft-ecommerce', [
            'seo' => ['meta_search_title' => '검색: {{query}}'],
        ], []);

        $this->addToAssertionCount(1);
    }

    // ─── SEO 토글 설정 변경 ──────────────────────────────

    /**
     * seo_product_detail 토글 변경 시 shop/show + sitemap 무효화 확인
     */
    public function test_seo_product_detail_toggle_invalidates_shop_show_and_sitemap(): void
    {
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->with('shop/show');

        Cache::shouldReceive('forget')->once()->with('seo:sitemap');
        Log::shouldReceive('info')->once();

        $this->listener->onModuleSettingsSave('sirsoft-ecommerce', [
            'seo' => ['seo_product_detail' => false],
        ], []);

        $this->addToAssertionCount(1);
    }

    /**
     * seo_category 토글 변경 시 shop/category + shop/index + sitemap 무효화 확인
     */
    public function test_seo_category_toggle_invalidates_shop_layouts_and_sitemap(): void
    {
        $invokedLayouts = [];
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->andReturnUsing(function (string $layout) use (&$invokedLayouts) {
                $invokedLayouts[] = $layout;

                return 1;
            });

        Cache::shouldReceive('forget')->once()->with('seo:sitemap');
        Log::shouldReceive('info')->once();

        $this->listener->onModuleSettingsSave('sirsoft-ecommerce', [
            'seo' => ['seo_category' => false],
        ], []);

        $this->assertContains('shop/category', $invokedLayouts);
        $this->assertContains('shop/index', $invokedLayouts);
    }

    // ─── SEO 관련 없는 설정 변경 ──────────────────────────

    /**
     * SEO 관련 없는 설정만 변경 시 무효화 없음 확인
     */
    public function test_non_seo_settings_change_does_not_invalidate(): void
    {
        $this->cacheMock->shouldNotReceive('invalidateByLayout');

        $this->listener->onModuleSettingsSave('sirsoft-ecommerce', [
            'basic_info' => ['route_path' => 'shop'],
        ], []);

        $this->addToAssertionCount(1);
    }

    // ─── 예외 처리 ──────────────────────────────────────

    /**
     * 예외 발생 시 graceful하게 처리되는지 확인
     */
    public function test_handles_exceptions_gracefully(): void
    {
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->andThrow(new \RuntimeException('Cache error'));

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Ecommerce SEO settings cache invalidation failed', Mockery::type('array'));

        $this->listener->onModuleSettingsSave('sirsoft-ecommerce', [
            'seo' => ['meta_product_title' => 'test'],
        ], []);

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
