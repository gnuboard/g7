<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Listeners;

use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Page\Listeners\SeoPageCacheListener;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 SEO 캐시 리스너 단위 테스트
 *
 * SeoPageCacheListener의 캐시 무효화 로직을 검증합니다.
 * - 훅 구독 등록 확인 (create/update/delete → onPageChange)
 * - onPageChange: 페이지 URL(slug 기반), page/show, home 캐시 무효화
 * - page가 null이거나 slug가 없는 경우 URL 무효화 건너뛰기
 * - 예외 발생 시 graceful 처리
 */
class SeoPageCacheListenerTest extends ModuleTestCase
{
    private SeoPageCacheListener $listener;

    private SeoCacheManagerInterface $cacheMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);
        $this->app->instance(SeoCacheManagerInterface::class, $this->cacheMock);

        $this->listener = new SeoPageCacheListener;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── 훅 구독 등록 ──────────────────────────────────────

    /**
     * 훅 구독이 올바르게 등록되어 있는지 확인합니다.
     * 모든 훅이 onPageChange 메서드를 호출해야 합니다.
     */
    public function test_get_subscribed_hooks_all_hooks_map_to_on_page_change(): void
    {
        $hooks = SeoPageCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-page.page.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-page.page.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-page.page.after_delete', $hooks);

        // 모든 훅이 onPageChange를 호출
        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_create']['method']);
        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_update']['method']);
        $this->assertEquals('onPageChange', $hooks['sirsoft-page.page.after_delete']['method']);

        // 모든 우선순위 20
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_create']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_update']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_delete']['priority']);
    }

    // ─── onPageChange ──────────────────────────────────────

    /**
     * onPageChange가 페이지 URL(slug), page/show, home 캐시를 무효화하는지 확인합니다.
     */
    public function test_on_page_change_invalidates_page_url_and_layouts(): void
    {
        $page = (object) ['id' => 5, 'slug' => 'about-us'];

        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->once()
            ->with('*/pages/about-us');

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->with('page/show');

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->with('home');

        Log::shouldReceive('debug')
            ->once()
            ->with('[SEO] Page change cache invalidated', Mockery::on(function ($context) {
                return $context['page_id'] === 5
                    && $context['page_slug'] === 'about-us';
            }));

        $this->listener->onPageChange($page);

        $this->addToAssertionCount(1);
    }

    /**
     * onPageChange에 page가 null인 경우 URL 무효화를 건너뛰는지 확인합니다.
     */
    public function test_on_page_change_skips_url_invalidation_when_page_is_null(): void
    {
        $this->cacheMock->shouldNotReceive('invalidateByUrl');

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->with('page/show');

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->with('home');

        Log::shouldReceive('debug')
            ->once();

        $this->listener->onPageChange(null);

        $this->addToAssertionCount(1);
    }

    /**
     * onPageChange에 page에 slug가 없는 경우 URL 무효화를 건너뛰는지 확인합니다.
     */
    public function test_on_page_change_skips_url_invalidation_when_page_has_no_slug(): void
    {
        $page = (object) ['id' => 7];

        $this->cacheMock->shouldNotReceive('invalidateByUrl');

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->with('page/show');

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->with('home');

        Log::shouldReceive('debug')
            ->once();

        $this->listener->onPageChange($page);

        $this->addToAssertionCount(1);
    }

    /**
     * onPageChange에서 예외 발생 시 graceful하게 처리되는지 확인합니다.
     */
    public function test_on_page_change_handles_exceptions_gracefully(): void
    {
        $page = (object) ['id' => 3, 'slug' => 'contact'];

        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andThrow(new \RuntimeException('Cache service unavailable'));

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Page cache invalidation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Cache service unavailable'
                    && $context['page_id'] === 3;
            }));

        // 예외가 외부로 전파되지 않아야 함
        $this->listener->onPageChange($page);

        $this->addToAssertionCount(1);
    }

    // ─── handle (인터페이스 준수) ───────────────────────────

    /**
     * handle 메서드가 존재하는지 확인합니다 (HookListenerInterface 준수).
     */
    public function test_handle_method_exists(): void
    {
        $this->assertTrue(method_exists($this->listener, 'handle'));

        // 호출 시 예외 없이 실행됨
        $this->listener->handle();
    }
}
