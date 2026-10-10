<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\DTO;

use Modules\Sirsoft\Ecommerce\DTO\AppliedShippingPolicy;
use Modules\Sirsoft\Ecommerce\DTO\ItemCalculation;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderCalculationResult::getShippingByPolicy 정책별 집계 테스트 (S-Q26-b)
 *
 * 환불 예상의 배송비 정책별 차액은 재계산 측을 이 메서드로 구한다. 같은 정책에 상품옵션이 둘 이상이면
 * 몫을 더해야 하고, 같은 상품옵션 아이템(재계산 라인 둘)은 같은 키 몫을 공유하므로 한 번만 센다.
 *
 * 시나리오 매니페스트: `tests/scenarios/partial-cancel-row-allocation.yaml`
 */
class OrderCalculationResultTest extends ModuleTestCase
{
    /**
     * 정책 몫을 가진 결과 아이템을 만듭니다.
     *
     * @param  int  $productOptionId  상품옵션 ID
     * @param  int  $policyId  배송정책 ID
     * @param  int  $base  기본 배송비 몫
     * @return ItemCalculation 결과 아이템
     */
    private function item(int $productOptionId, int $policyId, int $base): ItemCalculation
    {
        return new ItemCalculation(
            productOptionId: $productOptionId,
            appliedShippingPolicy: new AppliedShippingPolicy(policyId: $policyId, shippingAmount: $base, totalShippingAmount: $base),
        );
    }

    /**
     * 같은 정책의 서로 다른 상품옵션 몫은 더하고, 같은 상품옵션 아이템은 한 번만 센다.
     *
     * @effects shipping_by_policy_sums_distinct_options
     *
     * @return void 반환값 없음
     */
    public function test_shipping_by_policy_sums_distinct_options_in_same_policy(): void
    {
        $result = new OrderCalculationResult(items: [
            $this->item(11, 7, 1500),
            $this->item(12, 7, 1500),
            // 같은 키(12) 라인 — 같은 몫을 공유하므로 다시 더하지 않는다
            $this->item(12, 7, 1500),
        ]);

        $byPolicy = $result->getShippingByPolicy();

        $this->assertEquals(3000, $byPolicy[7]['base']);
        $this->assertEquals(0, $byPolicy[7]['extra']);
    }
}
