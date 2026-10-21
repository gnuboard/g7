<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Jobs\GenerateSitemapJob;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use App\Seo\SitemapIndexer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\SeoProductCacheListener;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * SeoProductCacheListener 테스트
 *
 * 상품 변경 시 SEO 캐시 무효화 리스너의 동작을 검증합니다.
 * - 훅 구독 등록 확인 (create → onProductCreate, update → onProductUpdate, delete → onProductDelete)
 * - 공통 무효화: URL, shop/index, shop/category, home, search/index, sitemap
 * - 생성/수정 시 단건 캐시 재생성
 * - 삭제 시 재생성 없이 무효화만
 */
class SeoProductCacheListenerTest extends ModuleTestCase
{
    protected SeoProductCacheListener $listener;

    protected SeoCacheManagerInterface $cacheMock;

    protected SeoCacheRegenerator $regeneratorMock;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);
        $this->app->instance(SeoCacheManagerInterface::class, $this->cacheMock);

        $this->regeneratorMock = Mockery::mock(SeoCacheRegenerator::class);
        $this->app->instance(SeoCacheRegenerator::class, $this->regeneratorMock);

        // 사이트맵 색인 경로는 이 테스트 범위 밖 — spy 로 대체하고 잡을 fake 하여 DB/큐 부작용을 차단
        $this->app->instance(SitemapIndexer::class, Mockery::spy(SitemapIndexer::class));
        Bus::fake([GenerateSitemapJob::class]);

        $this->listener = new SeoProductCacheListener;
    }

    /**
     * 테스트가 바꾼 상태를 정리합니다.
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
     * 리스너가 올바른 훅-메서드 매핑을 반환하는지 확인
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
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
     * 상품 생성 시 관련 캐시를 무효화하고, 상세 재생성은 하지 않는다 (큐 리스너가 맡는다, #724)
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_product_create_invalidates_caches_without_regeneration(): void
    {
        $product = (object) ['id' => 42];

        $this->expectCommonInvalidations($product);

        $this->regeneratorMock->shouldNotReceive('renderAndCache');

        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductCreate($product);

        $this->addToAssertionCount(1);
    }

    // ─── onProductUpdate ──────────────────────────────────

    /**
     * 상품 수정 시 관련 캐시를 무효화하고, 상세 재생성은 하지 않는다 (큐 리스너가 맡는다, #724)
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_product_update_invalidates_caches_without_regeneration(): void
    {
        $product = (object) ['id' => 10];

        $this->expectCommonInvalidations($product);

        $this->regeneratorMock->shouldNotReceive('renderAndCache');

        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductUpdate($product);

        $this->addToAssertionCount(1);
    }

    /**
     * 상품 수정 시 home과 search/index 캐시도 무효화되는지 확인
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
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

        $this->regeneratorMock->shouldNotReceive('renderAndCache');
        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductUpdate($product);

        $this->assertContains('home', $invokedLayouts);
        $this->assertContains('search/index', $invokedLayouts);
        $this->assertContains('shop/index', $invokedLayouts);
        $this->assertContains('shop/category', $invokedLayouts);
    }

    /**
     * 상품 수정 시 상품 코드 주소(사용자 화면 링크가 쓰는 상세 주소)의 캐시도 비우는지 확인
     *
     * 상세 라우트는 번호와 상품 코드를 모두 받으므로(`Product::resolveRouteBinding`) 번호 주소만 비우면
     * 상품 코드 주소의 검색엔진용 화면이 캐시 만료까지 옛 내용으로 남는다. 사이트맵은 번호 주소
     * (`products/{id}`)를 싣는다(`EcommerceSitemapContributor`).
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_product_update_invalidates_product_code_url_too(): void
    {
        $product = (object) ['id' => 4, 'product_code' => 'FM1TGKY42DSXR60H'];

        $invokedUrls = [];
        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andReturnUsing(function (string $pattern) use (&$invokedUrls) {
                $invokedUrls[] = $pattern;

                return 1;
            });
        $this->cacheMock->shouldReceive('invalidateByLayout')->andReturn(1);

        $this->regeneratorMock->shouldNotReceive('renderAndCache');
        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductUpdate($product);

        $this->assertContains('*/products/4', $invokedUrls);
        $this->assertContains('*/products/FM1TGKY42DSXR60H', $invokedUrls);
    }

    /**
     * 상품 코드를 바꾼 저장이면 옛 상품 코드 주소의 캐시도 비우는지 확인
     *
     * 수정 훅은 수정 전 스냅샷(`ProductService::update` 의 `$product->toArray()`)을 두 번째 인자로 넘긴다.
     * 새 코드 주소만 비우면 옛 코드 주소(이미 공유·색인된 링크)의 검색엔진용 화면이 캐시 만료까지 옛 내용으로 남는다.
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_product_update_invalidates_previous_product_code_url_when_code_changed(): void
    {
        $product = (object) ['id' => 4, 'product_code' => 'NEWCODE0000000001'];
        $snapshot = ['id' => 4, 'product_code' => 'OLDCODE0000000001'];

        $invokedUrls = [];
        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andReturnUsing(function (string $pattern) use (&$invokedUrls) {
                $invokedUrls[] = $pattern;

                return 1;
            });
        $this->cacheMock->shouldReceive('invalidateByLayout')->andReturn(1);

        $this->regeneratorMock->shouldNotReceive('renderAndCache');
        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductUpdate($product, $snapshot);

        $this->assertContains('*/products/4', $invokedUrls);
        $this->assertContains('*/products/NEWCODE0000000001', $invokedUrls);
        $this->assertContains('*/products/OLDCODE0000000001', $invokedUrls);
    }

    /**
     * 상품 코드가 그대로인 저장이면 같은 주소를 두 번 비우지 않는지 확인
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_product_update_does_not_repeat_url_when_code_unchanged(): void
    {
        $product = (object) ['id' => 4, 'product_code' => 'SAMECODE000000001'];
        $snapshot = ['id' => 4, 'product_code' => 'SAMECODE000000001'];

        $invokedUrls = [];
        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andReturnUsing(function (string $pattern) use (&$invokedUrls) {
                $invokedUrls[] = $pattern;

                return 1;
            });
        $this->cacheMock->shouldReceive('invalidateByLayout')->andReturn(1);

        $this->regeneratorMock->shouldNotReceive('renderAndCache');
        Log::shouldReceive('debug')->atLeast()->once();

        $this->listener->onProductUpdate($product, $snapshot);

        $this->assertSame(['*/products/4', '*/products/SAMECODE000000001'], $invokedUrls);
    }

    // ─── onProductDelete ──────────────────────────────────

    /**
     * 상품 삭제 시 관련 캐시 무효화만 수행되고 재생성은 하지 않는지 확인
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
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
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
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
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_handles_invalidation_exceptions_gracefully(): void
    {
        $product = (object) ['id' => 99];

        $this->cacheMock->shouldReceive('invalidateByUrl')
            ->andThrow(new \RuntimeException('Cache connection failed'));

        // 재생성은 큐 리스너가 맡는다 — 무효화 실패와 무관하게 여기서는 다시 그리지 않는다
        $this->regeneratorMock->shouldNotReceive('renderAndCache');

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Product cache invalidation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Cache connection failed'
                    && $context['product_id'] === 99;
            }));
        // 무효화가 실패하면 성공 debug 로그는 남지 않는다 (예전 debug 는 재생성 성공 로그였다)
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->listener->onProductUpdate($product);

        $this->addToAssertionCount(1);
    }

    // ─── handle (인터페이스 준수) ──────────────────────────

    /**
     * handle 메서드가 존재하는지 확인 (HookListenerInterface 준수)
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
