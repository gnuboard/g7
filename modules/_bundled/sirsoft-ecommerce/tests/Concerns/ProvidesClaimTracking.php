<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Concerns;

use BackedEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 회수중·발송중 전이에 실을 테스트 송장 (#599 후속 — 그 두 상태는 송장이 있어야 들어간다)
 *
 * 흐름을 끝까지 몰아가는 픽스처가 단계마다 `transition($claim, $step, $this->claimTrackingFor($step))`
 * 로 부른다 — 송장이 필요 없는 단계에는 빈 배열이라 기존 호출과 같다.
 */
trait ProvidesClaimTracking
{
    /**
     * 단계에 실을 송장 문맥을 돌려줍니다. (회수중·발송중이 아니면 빈 배열)
     *
     * @param  BackedEnum  $step  목표 상태
     * @return array{carrier_id?: int, tracking_number?: string} 전이 부가 정보
     */
    protected function claimTrackingFor(BackedEnum $step): array
    {
        if (! in_array($step->value, ['collecting', 'redelivering'], true)) {
            return [];
        }

        return [
            'carrier_id' => $this->testClaimCarrier()->id,
            'tracking_number' => 'TEST-'.strtoupper($step->value),
        ];
    }

    /**
     * 테스트용 택배사를 돌려줍니다. (없으면 만든다)
     *
     * @return ShippingCarrier 택배사
     */
    protected function testClaimCarrier(): ShippingCarrier
    {
        return ShippingCarrier::first() ?? ShippingCarrier::create([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'is_active' => true,
        ]);
    }
}
