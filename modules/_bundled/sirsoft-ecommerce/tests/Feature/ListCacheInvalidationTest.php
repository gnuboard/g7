<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use Modules\Sirsoft\Ecommerce\Listeners\CategoryTreeCacheListener;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 목록 화면이 반복해서 읽는 집계의 캐시·무효화 계약 테스트
 *
 * 캐시가 걸리면 "다시 계산하지 않는다" 는 이득과 "낡은 값을 보여 준다" 는 위험이 함께
 * 생긴다. 두 번째가 실제로 막혀 있는지 확인한다 — 무효화가 빠지면 오류 없이 숫자만
 * 틀리므로 화면을 봐도 알아채기 어렵다.
 */
class ListCacheInvalidationTest extends ModuleTestCase
{
    /**
     * 테스트용 카테고리를 만듭니다.
     *
     * `path` 는 NOT NULL 이므로 생성 후 자기 id 로 채운다 (Service 경유 생성과 같은 형태).
     *
     * @param  string  $slug  슬러그
     * @param  string  $name  한국어 이름
     * @param  int  $sortOrder  정렬 순서
     * @return Category 생성된 카테고리
     */
    private function makeCategory(string $slug, string $name, int $sortOrder): Category
    {
        $category = Category::create([
            'name' => ['ko' => $name],
            'slug' => $slug,
            'depth' => 0,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'path' => '',
        ]);

        $category->update(['path' => (string) $category->id]);

        return $category;
    }

    /**
     * 테스트용 상품을 만듭니다.
     *
     * @param  string  $code  상품 코드
     * @return Product 생성된 상품
     */
    private function makeProduct(string $code)
    {
        return app(ProductRepositoryInterface::class)->create([
            'product_code' => $code,
            'name' => ['ko' => '캐시 테스트 상품 '.$code],
            'selling_price' => 1000,
            'stock_quantity' => 5,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
        ]);
    }

    /**
     * 카테고리 트리가 두 번째 호출에서 캐시로 응답하는지 확인
     */
    public function test_category_tree_is_cached_between_calls(): void
    {
        $this->makeCategory('appliance', '가전', 0);

        $this->assertCount(1, Category::getTree());

        // 무효화 훅을 거치지 않고 행을 늘렸는데도 결과가 그대로라면, 두 번째 호출이
        // 캐시였다는 뜻이다 (Model::create 는 Service 훅을 발화하지 않는다).
        $this->makeCategory('clothing', '의류', 1);

        $this->assertCount(1, Category::getTree());
    }

    /**
     * 트리 캐시를 비우면 다음 호출이 다시 계산하는지 확인
     */
    public function test_flush_makes_tree_recompute(): void
    {
        $this->makeCategory('appliance', '가전', 0);
        Category::getTree();

        $this->makeCategory('clothing', '의류', 1);
        Category::flushTreeCache();

        $this->assertCount(2, Category::getTree());
    }

    /**
     * 상품 변경 훅에서도 트리 캐시가 비워지는지 확인
     *
     * 트리에는 카테고리 구조뿐 아니라 상품 수 집계가 함께 실린다. 상품 훅을 빠뜨리면
     * 카테고리 배지의 상품 수가 오류 없이 낡은 값으로 남는다.
     */
    public function test_product_hooks_also_invalidate_tree_cache(): void
    {
        $hooks = CategoryTreeCacheListener::getSubscribedHooks();

        foreach ([
            'sirsoft-ecommerce.product.after_create',
            'sirsoft-ecommerce.product.after_update',
            'sirsoft-ecommerce.product.after_delete',
            'sirsoft-ecommerce.product.after_bulk_update',
        ] as $hook) {
            $this->assertArrayHasKey($hook, $hooks, "상품 훅 {$hook} 가 트리 캐시 무효화에 연결돼 있지 않다");
        }

        $this->makeCategory('appliance', '가전', 0);
        Category::getTree();

        $this->makeCategory('clothing', '의류', 1);
        (new CategoryTreeCacheListener)->flush();

        $this->assertCount(2, Category::getTree());
    }

    /**
     * 상품 통계가 쓰기 경로에서 즉시 무효화되는지 확인
     */
    public function test_product_statistics_cache_is_invalidated_on_write(): void
    {
        $repository = app(ProductRepositoryInterface::class);

        $before = $repository->getStatistics()['total'];

        $product = $this->makeProduct('TEST-CACHE-1');

        $this->assertSame(
            $before + 1,
            $repository->getStatistics()['total'],
            '상품을 만들었는데 통계가 그대로다 — 쓰기 경로에서 캐시가 비워지지 않았다'
        );

        $repository->delete($product);

        $this->assertSame($before, $repository->getStatistics()['total']);
    }

    /**
     * 재고 변경도 통계 무효화 대상인지 확인
     *
     * 통계에 품절·재고부족 건수가 들어 있으므로 재고만 바뀌어도 값이 달라진다.
     */
    public function test_stock_change_invalidates_statistics(): void
    {
        $repository = app(ProductRepositoryInterface::class);

        $product = $this->makeProduct('TEST-CACHE-2');
        $before = $repository->getStatistics()['out_of_stock_count'];

        $repository->updateStockQuantity($product->id, 0);

        $this->assertSame(
            $before + 1,
            $repository->getStatistics()['out_of_stock_count'],
            '재고를 0 으로 바꿨는데 품절 건수가 그대로다 — 재고 경로에서 캐시가 비워지지 않았다'
        );
    }

    /**
     * 통계 캐시를 끄면 캐시를 거치지 않고 매번 집계하는지 확인
     */
    public function test_statistics_cache_can_be_disabled(): void
    {
        config(['g7_settings.core.cache.stats_enabled' => false]);

        $repository = app(ProductRepositoryInterface::class);
        $before = $repository->getStatistics()['total'];

        // 캐시가 꺼져 있으면 무효화 호출 없이도 다음 조회에 곧바로 반영된다
        Category::query()->getConnection()->table('ecommerce_products')->insert([
            'product_code' => 'TEST-CACHE-3',
            'name' => json_encode(['ko' => '직접 삽입'], JSON_UNESCAPED_UNICODE),
            'selling_price' => 1000,
            'stock_quantity' => 5,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($before + 1, $repository->getStatistics()['total']);
    }
}
