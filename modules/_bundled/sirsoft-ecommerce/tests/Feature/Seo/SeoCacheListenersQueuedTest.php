<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Seo;

use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\SeoCategoryCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\SeoProductCacheListener;
use Modules\Sirsoft\Ecommerce\Listeners\SeoSettingsCacheListener;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 SEO 캐시 — 비우기는 저장한 요청 안에서, 무거운 상세 재렌더는 큐로
 *
 * 검색엔진용 캐시 비우기가 큐로 가면 워커가 돌 때까지(워커가 없으면 TTL 까지) 봇이 옛 상품 정보를 받는다.
 * 비우기는 sync 로 돌리고, 상세 페이지를 미리 다시 그리는 무거운 일만 별도 큐 리스너가 맡는다.
 */
class SeoCacheListenersQueuedTest extends ModuleTestCase
{
    /**
     * 새 큐 리스너의 클래스 이름 (수정 전에는 클래스가 없어 문자열로 둔다).
     */
    private const REGENERATE_LISTENER = 'Modules\\Sirsoft\\Ecommerce\\Listeners\\SeoProductDetailRegenerateListener';

    /**
     * 큐를 database 로 바꾸고 워커는 돌리지 않습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    /**
     * 비우기 리스너 3종의 전 구독이 sync 다. (실패-먼저)
     *
     * @scenario listener=seo_product, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_invalidation_listeners_are_synchronous(): void
    {
        foreach ([SeoProductCacheListener::class, SeoCategoryCacheListener::class, SeoSettingsCacheListener::class] as $listener) {
            foreach ($listener::getSubscribedHooks() as $hook => $config) {
                $this->assertTrue(($config['sync'] ?? false) === true, class_basename($listener)." 의 {$hook} 가 sync 가 아닙니다.");
            }
        }
    }

    /**
     * 상품을 수정한 직후, 워커가 돌지 않아도 그 상품 상세의 SEO 캐시가 사라진다. (실패-먼저)
     *
     * @scenario listener=seo_product, queue=database
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_product_detail_cache_is_invalidated_without_worker(): void
    {
        $product = Product::factory()->create();
        $url = ShopPathResolver::path("products/{$product->id}");
        $cache = app(SeoCacheManagerInterface::class);
        $cache->put($url, 'ko', '<html>옛 상품</html>');
        $this->assertNotNull($cache->get($url, 'ko'));

        HookManager::doAction('sirsoft-ecommerce.product.after_update', $product, $product->toArray());

        $this->assertNull($cache->get($url, 'ko'), '상품을 수정했는데 SEO 캐시가 남았습니다 — 봇이 옛 상품 정보를 받습니다.');
        $this->assertSame(
            0,
            DB::table('jobs')->where('payload', 'like', '%SeoProductCacheListener%')->count(),
            'SEO 캐시 비우기가 큐 작업으로 쌓였습니다.'
        );
    }

    /**
     * 상품 생성·수정의 상세 재렌더는 새 큐 리스너로 넘어가고, 비우기 리스너는 다시 그리지 않는다.
     *
     * @scenario listener=seo_product, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_detail_regeneration_is_pushed_to_queue_listener(): void
    {
        Queue::fake();
        $regenerator = Mockery::mock(SeoCacheRegenerator::class);
        $regenerator->shouldNotReceive('renderAndCache');
        $this->app->instance(SeoCacheRegenerator::class, $regenerator);

        $product = Product::factory()->create();

        HookManager::doAction('sirsoft-ecommerce.product.after_create', $product, []);
        HookManager::doAction('sirsoft-ecommerce.product.after_update', $product, $product->toArray());

        Queue::assertPushed(
            DispatchHookListenerJob::class,
            fn (DispatchHookListenerJob $job) => $job->listenerClass === self::REGENERATE_LISTENER && $job->method === 'onProductCreate'
        );
        Queue::assertPushed(
            DispatchHookListenerJob::class,
            fn (DispatchHookListenerJob $job) => $job->listenerClass === self::REGENERATE_LISTENER && $job->method === 'onProductUpdate'
        );
        Queue::assertNotPushed(
            DispatchHookListenerJob::class,
            fn (DispatchHookListenerJob $job) => $job->listenerClass === SeoProductCacheListener::class
        );
    }

    /**
     * 비우기 핸들러 6개 모두 트랜잭션 안에서 발화하면 커밋 전에는 SEO 캐시를 비우지 않고, 커밋 뒤에 비운다.
     *
     * 커밋 전에 비우면 그 사이 다른 요청(봇)이 옛 데이터로 캐시를 다시 채운다.
     *
     * @scenario listener=seo_product, queue=database
     *
     * @effects invalidation_deferred_until_commit
     *
     * @return void 반환값 없음
     */
    public function test_every_invalidation_handler_waits_for_commit(): void
    {
        $calls = 0;
        $cache = Mockery::mock(SeoCacheManagerInterface::class)->shouldIgnoreMissing();
        $cache->shouldReceive('invalidateByLayout', 'invalidateByUrl', 'clearAll')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return 0;
        });
        $this->app->instance(SeoCacheManagerInterface::class, $cache);

        $product = Product::factory()->create();
        $handlers = [
            'product.onProductCreate' => fn () => app(SeoProductCacheListener::class)->onProductCreate($product, []),
            'product.onProductUpdate' => fn () => app(SeoProductCacheListener::class)->onProductUpdate($product, $product->toArray()),
            'product.onProductDelete' => fn () => app(SeoProductCacheListener::class)->onProductDelete($product),
            'category.onCategoryChange' => fn () => app(SeoCategoryCacheListener::class)->onCategoryChange(null),
            'category.onCategoryDelete' => fn () => app(SeoCategoryCacheListener::class)->onCategoryDelete(null),
            'settings.onModuleSettingsSave' => fn () => app(SeoSettingsCacheListener::class)->onModuleSettingsSave('sirsoft-ecommerce', ['seo' => ['meta_product_title' => 'x']]),
        ];

        // 핸들러마다 따로 판정해 모은다 — 첫 실패에서 멈추면 나머지 핸들러가 잠겨 있는지 드러나지 않는다.
        $early = [];
        $missing = [];
        foreach ($handlers as $name => $fire) {
            $calls = 0;

            DB::transaction(function () use ($fire, $name, &$calls, &$early) {
                $fire();

                if ($calls > 0) {
                    $early[] = $name;
                }
            });

            if ($calls === 0) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $early, '커밋 전에 SEO 캐시를 비운 핸들러가 있습니다.');
        $this->assertSame([], $missing, '커밋 뒤에도 SEO 캐시를 비우지 않은 핸들러가 있습니다.');
    }
}
