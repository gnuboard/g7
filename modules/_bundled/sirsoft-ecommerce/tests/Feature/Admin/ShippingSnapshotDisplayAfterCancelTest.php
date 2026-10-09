<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsManualPaidOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 부분취소·재활성 뒤 주문의 배송정책 스냅샷 표시 칸(「그 상품만 샀을 때 배송비」)이 남은 수량 기준으로 바뀐다
 *
 * 회원·비회원 주문 상세 상품 목록의 「적용 배송정책」은 `shipping_policy_applied_snapshot.items[].policy` 의
 * `standalone_shipping_amount` 를 읽는다. 재계산은 배송 행(배송 현황 카드)만 다시 썼고 이 칸은 주문 시점 값으로 남아,
 * 일부 취소로 조건부 무료배송 기준 아래가 된 상품도 「무료배송」으로 보였다. 표시 칸만 갱신한다 — 정책 규칙
 * (`policy_snapshot`)·훅 배송비(`hook_overridden`·`shipping_amount`)는 다음 취소·재활성 재계산의 입력이라 주문 시점
 * 값으로 둔다(두 번째 취소의 기준선은 살아 있는 행을 정책 규칙으로 다시 계산한 값이다).
 *
 * 시나리오 매니페스트: `tests/scenarios/partial-cancel-row-allocation.yaml`
 */
class ShippingSnapshotDisplayAfterCancelTest extends ModuleTestCase
{
    use BuildsManualPaidOrders;

    protected User $admin;

    /**
     * 통화·사유를 심고 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.orders.update']);
    }

    /**
     * 테스트가 심은 통화 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 조건부 무료배송(2,500 / 기준 50,000) 상품 20,000원 × 3개 주문 — 실주문과 같은 신형 스냅샷으로 둡니다.
     *
     * @param  bool  $legacyShape  구형(옵션 ID => 정책) 스냅샷으로 둘지
     * @return Order 결제완료 주문
     */
    private function conditionalFreeOrder(bool $legacyShape = false): Order
    {
        $member = User::factory()->create();
        $policy = $this->createClaimShippingPolicy(ChargePolicyEnum::CONDITIONAL_FREE, 2500, 50000);
        [$product, $option] = $this->createClaimProduct(20000, $policy);

        $input = new CalculationInput(items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 3)]);
        $order = $this->createClaimOrder(
            $input,
            ['user_id' => $member->id, 'currency_snapshot' => $this->krwCurrencySnapshot()],
            ['payment_method' => PaymentMethodEnum::CARD->value, 'pg_provider' => 'tosspayments'],
        );
        $order = $this->attachPolicyShippings($this->withPromotionsSnapshot($order, $input), $policy);
        $order = $this->moveOrderTo($order, OrderStatusEnum::PAYMENT_COMPLETE);

        if (! $legacyShape) {
            $items = [];
            foreach (ShippingPolicySnapshot::toOptionPolicyMap($order->shipping_policy_applied_snapshot) as $optionId => $entry) {
                $items[] = ['product_option_id' => $optionId, 'policy' => $entry];
            }
            $order->forceFill(['shipping_policy_applied_snapshot' => ShippingPolicySnapshot::make($items, ['country_code' => 'KR'])])->save();
        }

        return $order->fresh(['options', 'shippings', 'payment']);
    }

    /**
     * 스냅샷에서 그 상품 옵션의 정책 항목을 꺼냅니다.
     *
     * @param  Order  $order  주문
     * @return array 정책 항목
     */
    private function policyOf(Order $order): array
    {
        $map = ShippingPolicySnapshot::toOptionPolicyMap($order->fresh()->shipping_policy_applied_snapshot);

        return $map[$order->options->first()->product_option_id] ?? [];
    }

    /**
     * 일부 취소로 무료배송 기준 아래가 되면 표시 칸이 2,500 이 되고, 재계산 입력 필드는 주문 시점 값 그대로다.
     *
     * @effects shipping_snapshot_display_follows_remaining_quantity
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_updates_only_the_standalone_display_fields(): void
    {
        $order = $this->conditionalFreeOrder();
        $before = $this->policyOf($order);
        $this->assertEquals(0, $before['standalone_shipping_amount'], '준비: 주문 시점은 무료배송(60,000 ≥ 50,000)');

        // 3개 중 2개 취소 → 20,000 < 50,000
        $order = $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 2]]);
        $after = $this->policyOf($order);

        $this->assertEquals(2500, $after['standalone_shipping_amount'], '「적용 배송정책」이 취소 전 「무료배송」으로 남았습니다.');
        $this->assertSame(ecommerce_format_price(2500), $after['standalone_shipping_amount_formatted']);

        foreach (['policy_snapshot', 'policy_name', 'hook_overridden', 'shipping_amount', 'policy_id'] as $key) {
            $this->assertSame($before[$key] ?? null, $after[$key] ?? null, "재계산 입력 {$key} 를 바꾸면 다음 취소의 기준이 흔들립니다.");
        }
    }

    /**
     * 표시 칸은 재계산 입력이 아니다 — 갱신된 스냅샷과 주문 시점 스냅샷으로 두 번째 취소를 미리 계산하면 환불액이 같다.
     *
     * @effects shipping_snapshot_display_follows_remaining_quantity
     *
     * @return void 반환값 없음
     */
    public function test_second_cancel_refund_is_the_same_with_or_without_the_display_update(): void
    {
        $order = $this->conditionalFreeOrder();
        $original = $order->shipping_policy_applied_snapshot;

        $order = $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]]);
        $living = $order->options->first(fn ($o) => $o->option_status !== OrderStatusEnum::CANCELLED);
        $second = CancellationAdjustment::fromArray([['order_option_id' => $living->id, 'cancel_quantity' => 1]]);

        $withUpdate = app(OrderAdjustmentService::class)->preview($order->fresh(['options', 'shippings', 'payment']), $second);

        $order->forceFill(['shipping_policy_applied_snapshot' => $original])->save();
        $withoutUpdate = app(OrderAdjustmentService::class)->preview($order->fresh(['options', 'shippings', 'payment']), $second);

        $this->assertEquals($withoutUpdate->refundAmount, $withUpdate->refundAmount);
        $this->assertEquals($withoutUpdate->shippingDifference, $withUpdate->shippingDifference);
    }

    /**
     * 되살리면 표시 칸도 되살린 수량 기준(주문 시점과 같은 무료배송)으로 돌아온다.
     *
     * @effects shipping_snapshot_display_follows_remaining_quantity
     *
     * @return void 반환값 없음
     */
    public function test_reactivation_restores_the_display_fields(): void
    {
        $order = $this->conditionalFreeOrder();
        $rowId = $order->options->first()->id;
        $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $rowId, 'cancel_quantity' => 2]]);
        $this->assertEquals(2500, $this->policyOf($order)['standalone_shipping_amount']);

        $order = $this->cancelThroughAdmin($order->fresh(['options', 'payment', 'shippings']), 'full');
        $this->actingAs($this->admin)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk();

        $this->assertEquals(0, $this->policyOf($order)['standalone_shipping_amount'], '되살린 뒤에도 취소 상태의 배송비가 남았습니다.');
    }

    /**
     * 구형(옵션 ID => 정책) 스냅샷도 같은 칸을 갱신한다.
     *
     * @effects shipping_snapshot_display_follows_remaining_quantity
     *
     * @return void 반환값 없음
     */
    public function test_legacy_shaped_snapshot_is_updated_in_place(): void
    {
        $order = $this->conditionalFreeOrder(legacyShape: true);
        $order = $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 2]]);

        $raw = $order->fresh()->shipping_policy_applied_snapshot;
        $this->assertArrayNotHasKey(ShippingPolicySnapshot::ITEMS, $raw, '구형 스냅샷의 형태를 바꾸면 안 됩니다.');
        $this->assertEquals(2500, $this->policyOf($order)['standalone_shipping_amount']);
    }
}
