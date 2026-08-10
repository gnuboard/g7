<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database;

use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문상품옵션 팩토리 재고 정합성 테스트
 *
 * `OrderOptionFactory` 는 스냅샷에 재고 100 을 적으면서 실제 연결 옵션은 0~100 난수로 만들고
 * 있었다. 주문 수량은 1~5 이므로 재고가 그보다 작게 뽑히면, 주문 상태 전이를 다루는 테스트가
 * 결제 완료 시 재고 차감에서 무작위로 실패했다(전수 실행 3,729건 중 1건이 이 경로로 깨졌다).
 *
 * 재고 부족 시나리오는 그 상태를 명시하는 테스트가 따로 지정하므로, 기본 팩토리는 자기 스냅샷과
 * 일치하는 재고를 갖는 것이 옳다.
 */
class OrderOptionFactoryStockTest extends ModuleTestCase
{
    /**
     * 기본 팩토리가 만든 옵션은 주문 수량을 감당할 재고를 갖는다.
     */
    public function test_default_factory_option_has_stock_for_its_quantity(): void
    {
        foreach (range(1, 15) as $_) {
            $orderOption = OrderOption::factory()->create();
            $productOption = ProductOption::find($orderOption->product_option_id);

            $this->assertNotNull($productOption);
            $this->assertGreaterThanOrEqual(
                $orderOption->quantity,
                $productOption->stock_quantity,
                '기본 팩토리의 연결 옵션 재고가 주문 수량보다 적습니다 — 재고 차감을 거치는 '
                .'테스트가 무작위로 실패하게 됩니다.'
            );
        }
    }

    /**
     * 스냅샷이 적은 재고와 실제 연결 옵션의 재고가 일치한다.
     */
    public function test_snapshot_stock_matches_linked_option_stock(): void
    {
        $orderOption = OrderOption::factory()->create();
        $productOption = ProductOption::find($orderOption->product_option_id);

        $this->assertSame(
            (int) ($orderOption->option_snapshot['stock_quantity'] ?? -1),
            (int) $productOption->stock_quantity
        );
    }
}
