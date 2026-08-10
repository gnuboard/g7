<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Performance;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 쇼핑 첫 화면 구성 조회의 쿼리 수 회귀 테스트 (#519)
 *
 * 쇼핑 첫 화면은 분류·상품 목록·최근 본·인기·신상품 다섯 조각으로 그려진다. 각 조각은
 * 독립 엔드포인트라 어느 하나에 N+1 이 들어와도 나머지는 멀쩡하고, 화면도 정상으로 보인다.
 * 응답 형태를 보는 테스트로는 드러나지 않으므로 **조각마다** 쿼리 수를 따로 센다.
 *
 * 특정 시점의 쿼리 수를 숫자로 박아 두면 관계 하나만 추가해도 깨져 유지되지 않는다.
 * 대신 **행 수를 늘려도 쿼리 수가 늘지 않는다** 를 단언한다 — 그것이 N+1 의 정의다.
 *
 * @scenario case=list_query_count
 *
 * @effects shop_home_popular_query_count_stable,
 *          shop_home_new_query_count_stable,
 *          shop_home_recent_query_count_stable
 */
class ShopHomeQueryCountRegressionTest extends ModuleTestCase
{
    use CountsQueries;

    /**
     * 진열 중인 상품을 원하는 수만큼 만듭니다.
     *
     * @param  int  $count  생성할 수
     * @param  string  $prefix  상품 코드 접두
     * @return array<int, int> 생성된 상품 ID 목록
     */
    private function seedProducts(int $count, string $prefix): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = Product::create([
                'product_code' => $prefix.'-'.$i,
                'name' => ['ko' => '상품 '.$prefix.$i],
                'selling_price' => 1000 + $i,
                'stock_quantity' => 10,
                'sales_status' => 'on_sale',
                'display_status' => 'visible',
            ])->id;
        }

        return $ids;
    }

    /**
     * 인기 상품 섹션: 상품 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * 판매량 집계가 상품마다 상관 서브쿼리로 나가면 여기서 드러난다.
     *
     * @effects shop_home_popular_query_count_stable
     */
    public function test_popular_section_query_count_is_stable(): void
    {
        $repository = app(ProductRepositoryInterface::class);
        $this->seedProducts(5, 'HOME-POP-A');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->getPopularProducts(10),
            grow: fn () => $this->seedProducts(10, 'HOME-POP-B'),
            context: '쇼핑 첫 화면 인기 상품',
        );
    }

    /**
     * 신상품 섹션: 상품 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * @effects shop_home_new_query_count_stable
     */
    public function test_new_section_query_count_is_stable(): void
    {
        $repository = app(ProductRepositoryInterface::class);
        $this->seedProducts(5, 'HOME-NEW-A');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->getNewProducts(10),
            grow: fn () => $this->seedProducts(10, 'HOME-NEW-B'),
            context: '쇼핑 첫 화면 신상품',
        );
    }

    /**
     * 최근 본 상품 섹션: 지목한 상품 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * 이 섹션은 화면이 보관한 ID 목록으로 조회한다. ID 마다 단건 조회가 나가면
     * 목록이 길어질수록 쿼리가 그만큼 늘어난다.
     *
     * @effects shop_home_recent_query_count_stable
     */
    public function test_recent_section_query_count_is_stable(): void
    {
        $repository = app(ProductRepositoryInterface::class);
        $ids = $this->seedProducts(5, 'HOME-RECENT-A');

        // measure 와 grow 가 같은 목록을 봐야 한다. 화살표 함수는 정의 시점 값을 붙잡으므로
        // 참조로 받지 않으면 두 번째 측정이 늘어나기 전 목록을 다시 재게 된다.
        $this->assertQueryCountStableAsDataGrows(
            measure: function () use ($repository, &$ids) {
                return $repository->findByIds($ids);
            },
            grow: function () use (&$ids) {
                $ids = array_merge($ids, $this->seedProducts(10, 'HOME-RECENT-B'));
            },
            context: '쇼핑 첫 화면 최근 본 상품',
        );
    }
}
