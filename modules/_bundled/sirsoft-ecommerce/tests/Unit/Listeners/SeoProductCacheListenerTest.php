<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\SeoProductCacheListener;
use Tests\TestCase;

/**
 * SeoProductCacheListener 테스트
 *
 * 상품 변경 시 SEO 캐시 무효화 리스너의 동작을 검증합니다.
 * - 훅 구독 등록 확인 (create → onProductCreate, update → onProductUpdate, delete → onProductDelete)
 * - 공통 무효화: URL, shop/index, shop/category, home, search/index, sitemap
 * - 생성/수정 시 단건 캐시 재생성
 * - 삭제 시 재생성 없이 무효화만
 */
class SeoProductCacheListenerTest extends TestCase
{
    protected SeoProductCacheListener $listener;

    protected SeoCacheManagerInterface $cacheMock;

    protected SeoCacheRegenerator $regeneratorMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);
        $this->app->instance(SeoCacheManagerInterface::class, $this->cacheMock);

        $this->regeneratorMock = Mockery::mock(SeoCacheRegenerator::class);
        $this->app->instance(SeoCacheRegenerator::class, $this->regeneratorMock);

        $this->listener = new SeoProductCacheListener;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── 훅 구독 등록 ──────────────────────────────────────

    /**
     * 리스너가 올바른 훅-메서드 매핑을 반환하는지 확인
     */
    public function test_get_subscribed_hooks_returns_correct_mapping(): void
    {
        $hooks = SeoProductCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_create', $hooks);
        $this->assertEquals('onProductCreate', $hooks['sirsoft-ecommerce.product.after_create']['method']);
        $this->assertEquals(20, $hooks['sirsoft-ecommerce.product.after_create']['priority']);

        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_update', $hooks);
        $this->assertEquals('onProductUpdate', $hooks['sirsoft-ecommerce.product.after_update']['method']);
        $this->assertEquals(20, $hooks['sirsoft-ecommerce.product.after_update']['priority']);

        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_delete', $hooks);
        $this->assertEquals('onProductDelete', $hooks['sirsoft-ecommerce.product.after_delete']['method']);
        $this->assertEquals(20, $hooks['sirsoft-ecommerce.product.after_delete']['priority']);
    }

    // ─── onProductCreate ──────────────────────────────────

    /**
     * 상품 생성 시 관련 캐시 무효화 + 단건 재생성이 수행되는지 확인
     */
    public function test_on_product_create_invalidates_caches_and_regenerates_detail(): void
    {
        $product = (object) ['id' => 42];

        $this->expectCommonInvalidations($product);

        $this->regeneratorMock->shouldReceive('renderAndCache')
            ->once()
            ->with('/shop/products/42')
            ->andReturn(true);

        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductCreate($product);

        $this->addToAssertionCount(1);
    }

    // ─── onProductUpdate ──────────────────────────────────

    /**
     * 상품 수정 시 관련 캐시 무효화 + 단건 재생성이 수행되는지 확인
     */
    public function test_on_product_update_invalidates_caches_and_regenerates_detail(): void
    {
        $product = (object) ['id' => 10];

        $this->expectCommonInvalidations($product);

        $this->regeneratorMock->shouldReceive('renderAndCache')
            ->once()
            ->with('/shop/products/10')
            ->andReturn(true);

        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductUpdate($product);

        $this->addToAssertionCount(1);
    }

    /**
     * 상품 수정 시 home과 search/index 캐시도 무효화되는지 확인
     */
    public function test_on_product_update_invalidates_home_and_search(): void
    {
        $product = (object) ['id' => 5];

        $invokedLayouts = [];
        $this->cacheMock->shouldReceive('invalidateByUrl')->once();
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->andReturnUsing(function (string $layout) use (&$invokedLayouts) {
                $invokedLayouts[] = $layout;

                return 1;
            });

        $this->regeneratorMock->shouldReceive('renderAndCache')->once()->andReturn(true);
        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductUpdate($product);

        $this->assertContains('home', $invokedLayouts);
        $this->assertContains('search/index', $invokedLayouts);
        $this->assertContains('shop/index', $invokedLayouts);
        $this->assertContains('shop/category', $invokedLayouts);
    }

    // ─── onProductDelete ──────────────────────────────────

    /**
     * 상품 삭제 시 관련 캐시 무효화만 수행되고 재생성은 하지 않는지 확인
     */
    public function test_on_product_delete_invalidates_caches_without_regeneration(): void
    {
        $product = (object) ['id' => 77];

        $this->expectCommonInvalidations($product);

        $this->regeneratorMock->shouldNotReceive('renderAndCache');

        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductDelete($product);

        $this->addToAssertionCount(1);
    }

    /**
     * 상품이 null인 경우 아무 동작도 하지 않는지 확인
     */
    public function test_on_product_update_does_nothing_when_product_is_null(): void
    {
        $this->cacheMock->shouldNotReceive('invalidateByUrl');
        $this->cacheMock->shouldNotReceive('invalidateByLayout');
        $this->regeneratorMock->shouldNotReceive('renderAndCache');

        $this->listener->onProductUpdate(null);

        $this->addToAssertionCount(1);
    }

    // ─── 예외 처리 ──────────────────────────────────────

    /**
     * 캐시 무효화 중 예외 발생 시 graceful하게 처리하는지 확인
     */
    public function test_handles_invalidation_exceptions_gracefully(): void
    {
        $product = (object) ['id' => 99];

        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andThrow(new \RuntimeException('Cache connection failed'));

        // invalidation 실패 후에도 regeneration은 시도됨
        $this->regeneratorMock->shouldReceive('renderAndCache')->once()->andReturn(true);

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Product cache invalidation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Cache connection failed'
                    && $context['product_id'] === 99;
            }));
        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductUpdate($product);

        $this->addToAssertionCount(1);
    }

    // ─── handle (인터페이스 준수) ──────────────────────────

    /**
     * handle 메서드가 존재하는지 확인 (HookListenerInterface 준수)
     */
    public function test_handle_method_exists_for_interface_compliance(): void
    {
        $this->assertTrue(method_exists($this->listener, 'handle'));
        $this->listener->handle();
        $this->assertTrue(true);
    }

    // ─── 헬퍼 ──────────────────────────────────────────────

    /**
     * 공통 캐시 무효화 기대값을 설정합니다.
     *
     * @param  object  $product  상품 객체
     */
    private function expectCommonInvalidations(object $product): void
    {
        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->once()
            ->with("*/products/{$product->id}");

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()->with('shop/index');
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()->with('shop/category');
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()->with('home');
        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()->with('search/index');
    }
}
