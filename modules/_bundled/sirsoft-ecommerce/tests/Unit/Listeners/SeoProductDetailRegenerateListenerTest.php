<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Listeners\SeoProductDetailRegenerateListener;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 상세 SEO 캐시 다시 그리기 리스너 (큐) 테스트
 *
 * 캐시 비우기는 SeoProductCacheListener 가 sync 로 하고, 무거운 다시 그리기만 이 리스너가 큐에서 맡는다(#724).
 */
class SeoProductDetailRegenerateListenerTest extends ModuleTestCase
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
     * 생성·수정 훅을 구독하고 sync 를 선언하지 않는다 — 무거운 일은 큐에 남는다.
     *
     * @scenario listener=seo_product, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_create_and_update_as_queued(): void
    {
        $hooks = SeoProductDetailRegenerateListener::getSubscribedHooks();

        $this->assertSame('onProductCreate', $hooks['sirsoft-ecommerce.product.after_create']['method']);
        $this->assertSame('onProductUpdate', $hooks['sirsoft-ecommerce.product.after_update']['method']);
        foreach ($hooks as $hook => $config) {
            $this->assertArrayNotHasKey('sync', $config, "{$hook} 는 큐로 돌아야 합니다.");
            $this->assertSame(20, $config['priority']);
        }
    }

    /**
     * 상품 생성·수정 후 상점 기준 경로의 상품 상세를 다시 그린다.
     *
     * @scenario listener=seo_product, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_regenerates_product_detail_url(): void
    {
        $this->regenerator->shouldReceive('renderAndCache')->once()->with('/shop/products/42')->andReturn(true);
        $this->regenerator->shouldReceive('renderAndCache')->once()->with('/shop/products/10')->andReturn(true);
        Log::shouldReceive('debug')->atLeast()->once();

        $listener = new SeoProductDetailRegenerateListener;
        $listener->onProductCreate((object) ['id' => 42]);
        $listener->onProductUpdate((object) ['id' => 10]);

        $this->addToAssertionCount(1);
    }

    /**
     * 주소 없이 운영하는 상점(no_route)은 세그먼트 없이 루트 경로를 다시 그린다 (공개 #85)
     *
     * @scenario listener=seo_product, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_regenerates_root_path_when_no_route(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info', [
            'route_path' => 'shop',
            'no_route' => true,
        ]);
        $this->regenerator->shouldReceive('renderAndCache')->once()->with('/products/7')->andReturn(true);
        Log::shouldReceive('debug')->atLeast()->once();

        (new SeoProductDetailRegenerateListener)->onProductUpdate((object) ['id' => 7]);

        $this->addToAssertionCount(1);
    }

    /**
     * 다시 그리기가 실패해도 예외를 밖으로 내지 않고 경고만 남긴다.
     *
     * @scenario listener=seo_product, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_regeneration_failure_is_logged(): void
    {
        $this->regenerator->shouldReceive('renderAndCache')->andThrow(new \RuntimeException('render failed'));
        Log::shouldReceive('warning')->once()->with('[SEO] Product detail cache regeneration failed', Mockery::type('array'));

        (new SeoProductDetailRegenerateListener)->onProductUpdate((object) ['id' => 3]);

        $this->addToAssertionCount(1);
    }

    /**
     * 상품이 없으면 아무 것도 하지 않는다.
     *
     * @scenario listener=seo_product, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_does_nothing_without_product(): void
    {
        $this->regenerator->shouldNotReceive('renderAndCache');

        (new SeoProductDetailRegenerateListener)->onProductCreate(null);

        $this->addToAssertionCount(1);
    }
}
