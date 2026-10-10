<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsManualPaidOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 부분취소·재활성 뒤 배송 행 금액 (S-Q26-b)
 *
 * 배송 행 금액은 「그 주문 행이 지금 부담하는 배송비 몫」이다. 예전 부분취소는 주문 전체 배송비를 모든 배송
 * 행에 같은 값으로 써서 748 의 배송 행 네 개가 모두 5,500 이 되었고, mc_* 는 갱신하지 않아 base 와 갈렸다.
 * 취소·재활성은 실제 관리자 API 로 한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/partial-cancel-row-allocation.yaml`
 */
class PartialCancelShippingRowsTest extends ModuleTestCase
{
    use BuildsManualPaidOrders;

    protected User $admin;

    /**
     * 통화·사유를 심고 주문 수정 권한을 가진 관리자를 만듭니다.
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
     * 748 형태 주문 — 상품 A(고정 3,000) 3개 + 상품 B(조건부무료 2,500 / 기준 50,000, 단가 4,000) 6개.
     *
     * 주문 행마다 배송 행을 생성 경로(createOrderShippings)와 같은 몫·mc 로 만든다.
     *
     * @return Order 결제완료 주문
     */
    protected function twoPolicyPaidOrder(): Order
    {
        $member = User::factory()->create();
        $spA = $this->createClaimShippingPolicy(ChargePolicyEnum::FIXED, 3000);
        $spB = $this->createClaimShippingPolicy(ChargePolicyEnum::CONDITIONAL_FREE, 2500, 50000);
        [$pA, $oA] = $this->createClaimProduct(10000, $spA);
        [$pB, $oB] = $this->createClaimProduct(4000, $spB);

        $input = new CalculationInput(items: [
            new CalculationItem(productId: $pA->id, productOptionId: $oA->id, quantity: 3),
            new CalculationItem(productId: $pB->id, productOptionId: $oB->id, quantity: 6),
        ]);
        $order = $this->createClaimOrder(
            $input,
            ['user_id' => $member->id, 'currency_snapshot' => $this->krwCurrencySnapshot()],
            ['payment_method' => PaymentMethodEnum::CARD->value, 'pg_provider' => 'tosspayments'],
        );
        $order = $this->withPromotionsSnapshot($order, $input);
        $order = $this->moveOrderTo($order, OrderStatusEnum::PAYMENT_COMPLETE);

        $result = app(OrderCalculationService::class)->calculate($input);
        $shares = [];
        foreach ($result->items as $item) {
            $shares[$item->productOptionId] = [$item->appliedShippingPolicy->policyId, (int) $item->appliedShippingPolicy->shippingAmount];
        }

        $currency = app(CurrencyConversionService::class);
        $order->shippings()->delete();
        foreach ($order->options as $row) {
            [$policyId, $base] = $shares[$row->product_option_id];
            OrderShipping::factory()->create([
                'order_id' => $order->id,
                'order_option_id' => $row->id,
                'shipping_policy_id' => $policyId,
                'delivery_policy_snapshot' => ['country_code' => 'KR'],
                'base_shipping_amount' => $base,
                'extra_shipping_amount' => 0,
                'total_shipping_amount' => $base,
                'shipping_discount_amount' => 0,
                'mc_base_shipping_amount' => $currency->convertToMultiCurrencyWithSnapshot($base, $order->currency_snapshot),
                'mc_total_shipping_amount' => $currency->convertToMultiCurrencyWithSnapshot($base, $order->currency_snapshot),
            ]);
        }

        return $order->fresh(['options', 'shippings', 'payment']);
    }

    /**
     * 상품 행의 배송 행 base 합을 구합니다.
     *
     * @param  Order  $order  주문
     * @param  int  $rowId  주문 행 ID
     * @return float 배송 행 base 합
     */
    protected function shippingBaseOf(Order $order, int $rowId): float
    {
        return (float) OrderShipping::query()->where('order_option_id', $rowId)->sum('base_shipping_amount');
    }

    /**
     * 목록 일괄로 결제완료로 되살립니다.
     *
     * @param  Order  $order  주문
     * @return void 반환값 없음
     */
    protected function reviveOrder(Order $order): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', ['ids' => [$order->id], 'order_status' => 'payment_complete'])
            ->assertOk();
    }

    /**
     * 부분취소 뒤 배송 행마다 그 상품 행의 배송비 몫이 쓰이고, 분할된 취소 조각은 0, 합은 헤더와 같으며 mc 도 따라간다.
     *
     * @effects shipping_rows_receive_their_own_share, closed_rows_shipping_is_zero_after_recalc, new_split_rows_shipping_is_rewritten_after_split, shipping_row_mc_columns_follow_base
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_writes_per_row_shipping_shares(): void
    {
        $order = $this->twoPolicyPaidOrder();
        $rowA = $order->options->firstWhere('quantity', 3);
        $rowB = $order->options->firstWhere('quantity', 6);

        $order = $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $rowB->id, 'cancel_quantity' => 1]]);

        $split = $order->options->first(fn ($o) => $o->option_status === OrderStatusEnum::CANCELLED);
        $this->assertNotNull($split, '부분취소가 B 를 분할해 취소 조각을 남겨야 한다');
        $this->assertEquals(3000, $this->shippingBaseOf($order, $rowA->id));
        $this->assertEquals(2500, $this->shippingBaseOf($order, $rowB->id));
        $this->assertEquals(0, $this->shippingBaseOf($order, $split->id));

        $rows = OrderShipping::query()->where('order_id', $order->id)->get();
        $this->assertEquals((float) $order->base_shipping_amount, (float) $rows->sum('base_shipping_amount'));
        $this->assertEquals(5500, (float) $rows->sum('base_shipping_amount'));
        foreach ($rows as $row) {
            $this->assertEquals(0, (float) $row->shipping_discount_amount);
            $this->assertEquals((float) $row->base_shipping_amount, (float) ($row->mc_total_shipping_amount['KRW'] ?? -1));
        }
    }

    /**
     * 부분·전체취소 뒤 재활성하면 배송 행이 상품 행별 몫으로 돌아온다.
     *
     * @effects shipping_rows_receive_their_own_share
     *
     * @return void 반환값 없음
     */
    public function test_reactivation_rewrites_shipping_rows_from_zero_to_shares(): void
    {
        $order = $this->twoPolicyPaidOrder();
        $rowA = $order->options->firstWhere('quantity', 3);
        $rowB = $order->options->firstWhere('quantity', 6);

        $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $rowB->id, 'cancel_quantity' => 1]]);
        $order = $this->cancelThroughAdmin($order->fresh(['options', 'payment', 'shippings']), 'full');

        $this->reviveOrder($order);

        $order = $order->fresh(['options', 'shippings']);
        $this->assertSame(0, $order->options->where('option_status', OrderStatusEnum::CANCELLED)->count());
        $this->assertEquals(3000, $this->shippingBaseOf($order, $rowA->id));
        $this->assertEquals(2500, $this->shippingBaseOf($order, $rowB->id));
        $this->assertEquals(5500, (float) OrderShipping::query()->where('order_id', $order->id)->sum('base_shipping_amount'));
    }

    /**
     * 한 상품 행에 배송 행이 둘이면 id 가 작은 행만 몫을 받고 나머지는 0 이다.
     *
     * @effects merged_row_extra_shipping_rows_are_zero
     *
     * @return void 반환값 없음
     */
    public function test_merged_row_with_two_shipping_rows_gets_share_once(): void
    {
        $order = $this->twoPolicyPaidOrder();
        $rowA = $order->options->firstWhere('quantity', 3);
        $rowB = $order->options->firstWhere('quantity', 6);

        // 병합 이전분: A 행에 배송 행이 하나 더 붙어 있다(transferByOrderOptionId 가 남기는 모양)
        $first = OrderShipping::query()->where('order_option_id', $rowA->id)->orderBy('id')->first();
        $extra = $first->replicate();
        $extra->save();

        $this->cancelThroughAdmin($order->fresh(['options', 'payment', 'shippings']), 'partial', [['order_option_id' => $rowB->id, 'cancel_quantity' => 1]]);

        $this->assertEquals(3000, (float) $first->fresh()->base_shipping_amount);
        $this->assertEquals(0, (float) $extra->fresh()->base_shipping_amount);
    }
}
