<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 일괄 가격·재고 증감의 결과값 회귀 테스트 (#519)
 *
 * 증감·비율 계산을 행마다 update() 하지 않고 단일 statement 로 합치는 것이 목적이지만,
 * 그 과정에서 SET 절에 값이 실리는 경로가 깨지면 조용히 엉뚱한 값이 기록된다.
 * "몇 행이 영향받았는가" 만 보면 통과하므로, **결과값 자체**를 단언한다.
 *
 * @scenario case=product_bulk_adjustment
 *
 * @effects bulk_price_increase_applies_exact_amount,
 *          bulk_price_percent_applies_exact_ratio,
 *          bulk_price_floors_at_zero,
 *          bulk_stock_increase_applies_exact_amount,
 *          bulk_stock_floors_at_zero,
 *          bulk_adjustment_touches_only_selected_rows
 */
class ProductBulkAdjustmentTest extends ModuleTestCase
{
    private ProductRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProductRepositoryInterface::class);
    }

    /**
     * 상품 하나를 만듭니다.
     *
     * @param  string  $code  상품 코드
     * @param  float  $price  판매가
     * @param  int  $stock  재고 수량
     * @return Product 생성된 상품
     */
    private function makeProduct(string $code, float $price, int $stock = 10): Product
    {
        return Product::create([
            'product_code' => $code,
            'name' => ['ko' => '상품 '.$code],
            'selling_price' => $price,
            'stock_quantity' => $stock,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
        ]);
    }

    /**
     * 정액 증액이 지정한 금액만큼 정확히 오르는지 확인
     *
     * @effects bulk_price_increase_applies_exact_amount
     */
    public function test_bulk_price_increase_applies_exact_amount(): void
    {
        // Given: 판매가가 서로 다른 상품 2건
        $a = $this->makeProduct('BP-A', 1000);
        $b = $this->makeProduct('BP-B', 2500);

        // When: 정액 1000원 증액
        $affected = $this->repository->bulkUpdatePrice([$a->id, $b->id], 'increase', 1000, 'amount');

        // Then: 각 행이 정확히 1000원씩 오른다
        $this->assertSame(2, $affected);
        $this->assertEquals(2000.0, (float) $a->fresh()->selling_price);
        $this->assertEquals(3500.0, (float) $b->fresh()->selling_price);
    }

    /**
     * 정액 감액이 지정한 금액만큼 정확히 내리는지 확인
     *
     * @effects bulk_price_increase_applies_exact_amount
     */
    public function test_bulk_price_decrease_applies_exact_amount(): void
    {
        // Given: 판매가 5000원 상품
        $product = $this->makeProduct('BP-C', 5000);

        // When: 정액 1500원 감액
        $this->repository->bulkUpdatePrice([$product->id], 'decrease', 1500, 'amount');

        // Then: 3500원
        $this->assertEquals(3500.0, (float) $product->fresh()->selling_price);
    }

    /**
     * 정률 증감이 지정한 비율만큼 정확히 반영되는지 확인
     *
     * @effects bulk_price_percent_applies_exact_ratio
     */
    public function test_bulk_price_percent_applies_exact_ratio(): void
    {
        // Given: 판매가 1000원 상품 2건
        $up = $this->makeProduct('BP-D', 1000);
        $down = $this->makeProduct('BP-E', 1000);

        // When: 각각 10% 증액 / 10% 감액
        $this->repository->bulkUpdatePrice([$up->id], 'increase', 10, 'percent');
        $this->repository->bulkUpdatePrice([$down->id], 'decrease', 10, 'percent');

        // Then: 1100 / 900
        $this->assertEquals(1100.0, (float) $up->fresh()->selling_price);
        $this->assertEquals(900.0, (float) $down->fresh()->selling_price);
    }

    /**
     * 감액 결과가 음수가 되지 않고 0 에서 멈추는지 확인
     *
     * @effects bulk_price_floors_at_zero
     */
    public function test_bulk_price_decrease_floors_at_zero(): void
    {
        // Given: 판매가 500원 상품
        $product = $this->makeProduct('BP-F', 500);

        // When: 판매가보다 큰 금액을 감액
        $this->repository->bulkUpdatePrice([$product->id], 'decrease', 900, 'amount');

        // Then: 음수가 아니라 0
        $this->assertEquals(0.0, (float) $product->fresh()->selling_price);
    }

    /**
     * 재고 증감이 지정한 수량만큼 정확히 반영되는지 확인
     *
     * @effects bulk_stock_increase_applies_exact_amount
     */
    public function test_bulk_stock_increase_and_decrease_apply_exact_amount(): void
    {
        // Given: 재고가 서로 다른 상품 2건
        $a = $this->makeProduct('BS-A', 1000, 10);
        $b = $this->makeProduct('BS-B', 1000, 40);

        // When: 두 건을 함께 5 증가
        $this->repository->bulkUpdateStock([$a->id, $b->id], 'increase', 5);

        // Then: 각각 정확히 5씩 증가
        $this->assertSame(15, (int) $a->fresh()->stock_quantity);
        $this->assertSame(45, (int) $b->fresh()->stock_quantity);

        // When: 두 건을 함께 3 감소
        $this->repository->bulkUpdateStock([$a->id, $b->id], 'decrease', 3);

        // Then: 각각 정확히 3씩 감소
        $this->assertSame(12, (int) $a->fresh()->stock_quantity);
        $this->assertSame(42, (int) $b->fresh()->stock_quantity);
    }

    /**
     * 재고 감소 결과가 음수가 되지 않고 0 에서 멈추는지 확인
     *
     * @effects bulk_stock_floors_at_zero
     */
    public function test_bulk_stock_decrease_floors_at_zero(): void
    {
        // Given: 재고 2개 상품
        $product = $this->makeProduct('BS-C', 1000, 2);

        // When: 재고보다 많은 수량을 감소
        $this->repository->bulkUpdateStock([$product->id], 'decrease', 10);

        // Then: 음수가 아니라 0
        $this->assertSame(0, (int) $product->fresh()->stock_quantity);
    }

    /**
     * 지정하지 않은 행이 영향받지 않는지 확인
     *
     * WHERE 바인딩이 SET 절로 밀리면 대상 선택 자체가 어긋난다.
     *
     * @effects bulk_adjustment_touches_only_selected_rows
     */
    public function test_bulk_adjustment_touches_only_selected_rows(): void
    {
        // Given: 대상 2건 + 비대상 1건
        $target1 = $this->makeProduct('BT-1', 1000, 10);
        $target2 = $this->makeProduct('BT-2', 1000, 10);
        $untouched = $this->makeProduct('BT-3', 1000, 10);

        // When: 대상 2건만 지정해 증감
        $this->repository->bulkUpdatePrice([$target1->id, $target2->id], 'increase', 500, 'amount');
        $this->repository->bulkUpdateStock([$target1->id, $target2->id], 'increase', 7);

        // Then: 대상만 바뀌고 비대상은 그대로
        $this->assertEquals(1500.0, (float) $target1->fresh()->selling_price);
        $this->assertEquals(1500.0, (float) $target2->fresh()->selling_price);
        $this->assertEquals(1000.0, (float) $untouched->fresh()->selling_price);
        $this->assertSame(17, (int) $target1->fresh()->stock_quantity);
        $this->assertSame(10, (int) $untouched->fresh()->stock_quantity);
    }

    /**
     * 'set' 경로가 종전대로 동작하는지 확인 (회귀 방지)
     *
     * @effects bulk_price_increase_applies_exact_amount
     */
    public function test_bulk_set_replaces_value(): void
    {
        // Given: 판매가 1000원 / 재고 10 상품
        $product = $this->makeProduct('BSET-1', 1000, 10);

        // When: set 으로 지정
        $this->repository->bulkUpdatePrice([$product->id], 'set', 7777, 'amount');
        $this->repository->bulkUpdateStock([$product->id], 'set', 33);

        // Then: 지정값 그대로
        $this->assertEquals(7777.0, (float) $product->fresh()->selling_price);
        $this->assertSame(33, (int) $product->fresh()->stock_quantity);
    }
}
