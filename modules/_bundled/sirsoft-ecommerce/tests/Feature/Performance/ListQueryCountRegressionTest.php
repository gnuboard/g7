<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Performance;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 쇼핑 목록 조회의 쿼리 수 회귀 테스트 (#519)
 *
 * 특정 시점의 쿼리 수를 숫자로 박아 두면 관계 하나만 추가해도 깨져 유지되지 않는다.
 * 대신 **행 수를 늘려도 쿼리 수가 늘지 않는다** 를 단언한다 — 그것이 N+1 의 정의다.
 *
 * @scenario case=list_query_count
 *
 * @effects product_list_query_count_stable,
 *          public_list_query_count_stable,
 *          category_tree_query_count_stable
 */
class ListQueryCountRegressionTest extends ModuleTestCase
{
    use CountsQueries;

    /**
     * 상품을 원하는 수만큼 만듭니다.
     *
     * @param  int  $count  생성할 수
     * @param  string  $prefix  상품 코드 접두
     */
    private function seedProducts(int $count, string $prefix): void
    {
        for ($i = 0; $i < $count; $i++) {
            Product::create([
                'product_code' => $prefix.'-'.$i,
                'name' => ['ko' => '상품 '.$prefix.$i],
                'selling_price' => 1000 + $i,
                'stock_quantity' => 10,
                'sales_status' => 'on_sale',
                'display_status' => 'visible',
            ]);
        }
    }

    /**
     * 관리자 상품 목록: 상품 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * @effects product_list_query_count_stable
     */
    public function test_admin_product_list_query_count_is_stable(): void
    {
        $repository = app(ProductRepositoryInterface::class);
        $this->seedProducts(5, 'ADMIN-A');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->getListWithFilters([], 50),
            grow: fn () => $this->seedProducts(10, 'ADMIN-B'),
            context: '관리자 상품 목록',
        );
    }

    /**
     * 공개 상품 목록: 상품 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * @effects public_list_query_count_stable
     */
    public function test_public_product_list_query_count_is_stable(): void
    {
        $repository = app(ProductRepositoryInterface::class);
        $this->seedProducts(5, 'PUB-A');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->getPublicList([], 50),
            grow: fn () => $this->seedProducts(10, 'PUB-B'),
            context: '공개 상품 목록',
        );
    }

    /**
     * 카테고리 트리: 카테고리 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * 종전에는 노드마다 자식을 다시 조회해 카테고리 수만큼 쿼리가 나갔다.
     *
     * @effects category_tree_query_count_stable
     */
    public function test_category_tree_query_count_is_stable(): void
    {
        $this->seedCategories(3, 'tree-a');

        $this->assertQueryCountStableAsDataGrows(
            measure: function () {
                // 캐시가 있으면 두 번째 측정이 0 쿼리가 되어 판정이 무의미해진다.
                // 여기서 재는 것은 "조립 쿼리 수가 노드 수에 비례하지 않는다" 이므로 매번 비운다.
                Category::flushTreeCache();

                return Category::getTree();
            },
            grow: fn () => $this->seedCategories(6, 'tree-b'),
            context: '카테고리 트리',
        );
    }

    /**
     * 카테고리를 계층으로 만듭니다 (루트 1 + 자식 N-1).
     *
     * @param  int  $count  생성할 수
     * @param  string  $prefix  슬러그 접두
     */
    private function seedCategories(int $count, string $prefix): void
    {
        $root = Category::create([
            'name' => ['ko' => '루트 '.$prefix],
            'slug' => $prefix.'-root',
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => true,
            'path' => '',
        ]);
        $root->update(['path' => (string) $root->id]);

        for ($i = 1; $i < $count; $i++) {
            $child = Category::create([
                'name' => ['ko' => '자식 '.$prefix.$i],
                'slug' => $prefix.'-child-'.$i,
                'parent_id' => $root->id,
                'depth' => 1,
                'sort_order' => $i,
                'is_active' => true,
                'path' => '',
            ]);
            $child->update(['path' => $root->id.'/'.$child->id]);
        }
    }
}
