<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Stock;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 옵션 없는 상품의 재고 칸 정합 테스트
 *
 * 옵션 없는 상품도 기본 옵션 한 개로 재고를 차감한다. 주문·취소 뒤 상품 표의 재고 칸이
 * 활성 옵션 재고 합과 같아야 관리자 목록·재고 필터가 실제 재고를 보여 준다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/single-option-stock-sync.yaml
 */
class SingleOptionStockSyncTest extends ModuleTestCase
{
    /**
     * 옵션 없는 상품(기본 옵션 1개, 재고 5)을 만듭니다.
     *
     * @param  bool  $hasOptions  옵션 보유 여부
     * @return array{0: Product, 1: ProductOption} 상품과 기본 옵션
     */
    private function makeProduct(bool $hasOptions): array
    {
        $product = ProductFactory::new()->create(['has_options' => $hasOptions, 'stock_quantity' => 5]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'stock_quantity' => 5,
            'is_active' => true,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    /**
     * 옵션 없는 상품을 2개 주문하면 상품 재고 칸도 3 이 된다.
     *
     * @scenario product=without_options, movement=deduct
     *
     * @effects product_stock_follows_option_stock_on_deduct
     *
     * @return void 반환값 없음
     */
    public function test_deduct_updates_product_stock_for_product_without_options(): void
    {
        [$product, $option] = $this->makeProduct(false);

        $this->assertTrue(app(StockService::class)->deductOptionStock($option->id, 2));

        $this->assertSame(3, (int) $option->fresh()->stock_quantity);
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
    }

    /**
     * 취소로 재고를 되돌리면 상품 재고 칸도 함께 돌아온다.
     *
     * @scenario product=without_options, movement=restore
     *
     * @effects product_stock_follows_option_stock_on_restore
     *
     * @return void 반환값 없음
     */
    public function test_restore_updates_product_stock_for_product_without_options(): void
    {
        [$product, $option] = $this->makeProduct(false);
        $service = app(StockService::class);

        $service->deductOptionStock($option->id, 2);
        $service->restoreOptionStock($option->id, 1);

        $this->assertSame(4, (int) $option->fresh()->stock_quantity);
        $this->assertSame(4, (int) $product->fresh()->stock_quantity);
    }

    /**
     * 옵션 있는 상품은 종전대로 활성 옵션 재고 합을 따른다.
     *
     * @scenario product=with_options, movement=deduct
     *
     * @effects product_with_options_keeps_option_stock_sum
     *
     * @return void 반환값 없음
     */
    public function test_product_with_options_keeps_following_option_sum(): void
    {
        [$product, $option] = $this->makeProduct(true);
        ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 7, 'is_active' => true]);
        ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100, 'is_active' => false]);

        app(StockService::class)->deductOptionStock($option->id, 2);

        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }
}
