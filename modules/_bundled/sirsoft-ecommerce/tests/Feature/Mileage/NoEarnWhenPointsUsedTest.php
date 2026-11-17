<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Mileage;

use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Mileage\Concerns\BuildsMileagePolicyFixtures;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 「적립금을 쓴 주문은 구매 적립 없음」 정책 테스트
 *
 * 새 주문은 설정과 실제 사용액으로, 재계산(부분취소 등)은 주문 스냅샷으로 판정한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/mileage-policy-signup-noearn-mintotal.yaml
 */
class NoEarnWhenPointsUsedTest extends ModuleTestCase
{
    use BuildsMileagePolicyFixtures;

    /**
     * 켠 상태에서 적립금을 쓴 주문은 예상 적립이 0 이고 스냅샷에 정책이 남는다.
     *
     * @scenario policy=no_earn_when_points_used, setting=on, trigger=order_with_points
     *
     * @effects order_with_points_earns_nothing, order_snapshots_earn_policy
     *
     * @return void 반환값 없음
     */
    public function test_order_using_points_earns_nothing_when_policy_is_on(): void
    {
        $this->writeMileageSettings(['no_earn_when_points_used' => true]);

        $order = $this->placeOrder(50000, 10000);

        $this->assertSame(0.0, (float) $order->total_earned_points_amount);
        $this->assertTrue($order->mileage_policy_snapshot['earn_policy']['no_earn_when_points_used']);
        $this->assertSame(10000, (int) $order->mileage_policy_snapshot['used_points']);
    }

    /**
     * 켠 상태라도 적립금을 쓰지 않은 주문은 정상 적립한다.
     *
     * @scenario policy=no_earn_when_points_used, setting=on, trigger=order_without_points
     *
     * @effects order_without_points_earns_normally
     *
     * @return void 반환값 없음
     */
    public function test_order_without_points_earns_normally_when_policy_is_on(): void
    {
        $this->writeMileageSettings(['no_earn_when_points_used' => true]);

        $order = $this->placeOrder(50000, 0);

        $this->assertSame(5000.0, (float) $order->total_earned_points_amount);
    }

    /**
     * 꺼진 상태에서는 적립금을 써도 (사용분을 뺀 금액 기준으로) 적립한다.
     *
     * @scenario policy=no_earn_when_points_used, setting=off_or_zero, trigger=order_with_points
     *
     * @effects order_with_points_earns_when_policy_off
     *
     * @return void 반환값 없음
     */
    public function test_order_using_points_still_earns_when_policy_is_off(): void
    {
        $this->writeMileageSettings(['no_earn_when_points_used' => false]);

        $order = $this->placeOrder(50000, 10000);

        $this->assertSame(4000.0, (float) $order->total_earned_points_amount);
        $this->assertFalse($order->mileage_policy_snapshot['earn_policy']['no_earn_when_points_used']);
    }

    /**
     * 재계산은 지금 설정이 아니라 주문 스냅샷을 따른다 — 주문 뒤 정책을 꺼도 0, 옛 주문(키 없음)은 예전과 같다.
     *
     * @scenario policy=no_earn_when_points_used, setting=changed_after_order, trigger=recalculation
     *
     * @effects recalculation_follows_order_snapshot, legacy_snapshot_without_key_earns_as_before
     *
     * @return void 반환값 없음
     */
    public function test_recalculation_follows_the_order_snapshot_not_current_settings(): void
    {
        // 지금 설정은 「꺼짐」
        $this->writeMileageSettings(['no_earn_when_points_used' => false]);

        $rule = ['earn_rounding_unit' => '1', 'earn_rounding_method' => 'floor'];

        // 주문 때 정책이 켜져 있었고 적립금을 썼다 → 재계산해도 0
        $this->assertSame([1 => 0], $this->earnWithSnapshot([
            'rule' => $rule,
            'used_points' => 1000,
            'earn_policy' => ['no_earn_when_points_used' => true],
        ]));

        // 주문 때 정책이 켜져 있었지만 적립금을 쓰지 않았다 → 정상 적립
        $this->assertSame([1 => 900], $this->earnWithSnapshot([
            'rule' => $rule,
            'used_points' => 0,
            'earn_policy' => ['no_earn_when_points_used' => true],
        ]));

        // 옛 주문(스냅샷에 키 없음) — 지금 설정을 켜도 소급하지 않는다
        $this->writeMileageSettings(['no_earn_when_points_used' => true]);
        $this->assertSame([1 => 900], $this->earnWithSnapshot(['rule' => $rule, 'used_points' => 1000]));
    }

    /**
     * 회원 주문을 만들어 돌려줍니다.
     *
     * @param  int  $price  상품 판매가
     * @param  int  $usePoints  사용할 적립금
     * @return Order 만든 주문
     */
    private function placeOrder(int $price, int $usePoints): Order
    {
        $user = $this->createUser();
        $this->grantMileage($user->id, 20000);
        $cart = $this->cartFor($user, $price);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', array_filter([
                'item_ids' => [$cart->id],
                'use_points' => $usePoints ?: null,
            ]))
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/user/orders', $this->memberOrderPayload($price - $usePoints))
            ->assertStatus(201);

        return Order::query()->where('user_id', $user->id)->firstOrFail();
    }

    /**
     * 주문 스냅샷을 준 재계산 입력으로 적립 계산 단계를 실행합니다 (옵션 1개, 금액 10,000, 사용 안분 1,000).
     *
     * @param  array<string, mixed>  $snapshot  주문 시점 적립금 정책 스냅샷
     * @return array<int, int> 옵션별 적립액
     */
    private function earnWithSnapshot(array $snapshot): array
    {
        $method = new ReflectionMethod(OrderCalculationService::class, 'calculatePointsEarning');
        $method->setAccessible(true);

        $option = (object) ['mileage_value' => null, 'mileage_type' => null];

        $result = $method->invoke(
            app(OrderCalculationService::class),
            [['product_option_id' => 1, 'product_option' => $option, 'quantity' => 1]],
            [1 => ['discounted_subtotal' => 10000, 'order_discount_share' => 0]],
            ['points_by_option' => [1 => 1000], 'actual_points' => 1000],
            new CalculationInput(mileagePolicySnapshot: $snapshot)
        );

        return array_map('intval', $result);
    }
}
