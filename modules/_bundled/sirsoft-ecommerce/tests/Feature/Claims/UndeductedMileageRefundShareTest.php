<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimAdminListResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimLinkResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사용 마일리지를 차감하지 않은 주문은 미리보기·접수 단계부터 마일리지 환불 몫이 0 이다 (Q15 원천)
 *
 * 수동 결제완료에서 「마일리지 차감」을 끈 주문처럼 `is_mileage_deducted=false` 인 결제 주문은, 사용 마일리지가 고객이
 * 낸 적 없는 돈이다. 실행은 마일리지 몫을 0 으로 바꿔 왔지만 미리보기·접수 계산은 그 판정을 몰라 회원 반품 신청
 * 화면과 관리자 환불 확인 창이 「사용 마일리지 반환액 3,000」·환불 예정 24,000 을 보였다(실제 결과 21,000).
 * 계산 원천에서 같은 수로 맞춘다 — 현금으로 옮기지 않는다(실행과 같은 수).
 *
 * 시나리오 매니페스트: `tests/scenarios/claim-undeducted-mileage-share.yaml`
 */
class UndeductedMileageRefundShareTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private OrderAdjustmentService $adjustment;

    /**
     * 통화·마일리지 규칙을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('mileage.enabled', true);
        $settings->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);
        $this->adjustment = app(OrderAdjustmentService::class);
    }

    /**
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 12,000원 × 2개 주문에 마일리지 3,000 을 쓴 결제 주문을 만듭니다.
     *
     * @param  bool  $deducted  사용 마일리지를 실제로 차감했는가
     * @return Order 결제완료 주문
     */
    private function orderUsingPoints(bool $deducted): Order
    {
        $policy = $this->createClaimShippingPolicy();
        [$product, $option] = $this->createClaimProduct(12000, $policy);

        $order = $this->createClaimOrder(
            new CalculationInput(
                items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 2)],
                usePoints: 3000,
            ),
            ['is_mileage_deducted' => $deducted, 'currency_snapshot' => $this->krwCurrencySnapshot()],
        );

        $this->assertEquals(3000, (float) $order->total_points_used_amount, '준비: 사용 마일리지 3,000');

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 주문의 첫 행에서 지정 수량을 빼는 취소 조정을 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  int  $quantity  취소 수량
     * @return CancellationAdjustment 취소 조정
     */
    private function cancel(Order $order, int $quantity): CancellationAdjustment
    {
        return CancellationAdjustment::fromArray([
            ['order_option_id' => $order->options->first()->id, 'cancel_quantity' => $quantity],
        ]);
    }

    /**
     * 차감 주문과 미차감 주문을 같은 취소로 미리 계산해 마일리지 몫·현금 몫을 대조합니다.
     *
     * @param  int  $quantity  취소 수량 (1 = 부분, 2 = 전체)
     * @param  RefundPriorityEnum  $priority  환불 우선순위
     * @return void 반환값 없음
     */
    private function assertNoPointsShareForUndeducted(int $quantity, RefundPriorityEnum $priority): void
    {
        $deducted = $this->orderUsingPoints(true);
        $undeducted = $this->orderUsingPoints(false);

        $withPoints = $this->adjustment->preview($deducted, $this->cancel($deducted, $quantity), $priority);
        $withoutPoints = $this->adjustment->preview($undeducted, $this->cancel($undeducted, $quantity), $priority);

        if ($quantity === 2 || $priority === RefundPriorityEnum::POINTS_FIRST) {
            // PG 먼저 부분 취소는 남은 현금이 몫을 덮어 차감 주문도 마일리지 몫이 0 이다 — 그 경우만 대조군을 재지 않는다.
            $this->assertGreaterThan(0, $withPoints->refundPointsAmount, '대조군: 차감 주문은 마일리지 몫이 있다');
        }
        $this->assertEquals(0, $withoutPoints->refundPointsAmount, '차감되지 않은 마일리지를 돌려주려 합니다.');
        $this->assertEquals($withPoints->refundAmount, $withoutPoints->refundAmount, '현금 몫이 달라졌습니다 — 마일리지 몫을 현금으로 옮기지 않습니다.');
    }

    /**
     * 부분 취소(PG 먼저) — 미차감 주문은 마일리지 몫 0, 현금 몫은 차감 주문과 같다.
     *
     * @scenario scope=partial, priority=pg_first
     *
     * @effects undeducted_mileage_share_is_zero_from_preview
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_pg_first_gives_no_points_share(): void
    {
        $this->assertNoPointsShareForUndeducted(1, RefundPriorityEnum::PG_FIRST);
    }

    /**
     * 전체 취소(PG 먼저) — 전량 제외 경로도 같은 수다.
     *
     * @scenario scope=full, priority=pg_first
     *
     * @effects undeducted_mileage_share_is_zero_from_preview
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_pg_first_gives_no_points_share(): void
    {
        $this->assertNoPointsShareForUndeducted(2, RefundPriorityEnum::PG_FIRST);
    }

    /**
     * 부분 취소(마일리지 먼저) — 마일리지 우선을 골라도 미차감 주문은 0, 현금은 실행과 같은 수다.
     *
     * @scenario scope=partial, priority=points_first
     *
     * @effects undeducted_mileage_share_is_zero_from_preview
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_points_first_gives_no_points_share(): void
    {
        $this->assertNoPointsShareForUndeducted(1, RefundPriorityEnum::POINTS_FIRST);
    }

    /**
     * 전체 취소(마일리지 먼저).
     *
     * @scenario scope=full, priority=points_first
     *
     * @effects undeducted_mileage_share_is_zero_from_preview
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_points_first_gives_no_points_share(): void
    {
        $this->assertNoPointsShareForUndeducted(2, RefundPriorityEnum::POINTS_FIRST);
    }

    /**
     * 이 수정 전에 마일리지 몫 3,000 으로 접수된 신청서도 화면 응답은 0 과 그 이유를 싣는다 — 실행이 어차피 0 으로 바꾼다.
     *
     * @scenario scope=partial, priority=pg_first
     *
     * @effects filed_refund_shows_zero_points_for_undeducted_order
     *
     * @return void 반환값 없음
     */
    public function test_filed_refund_shows_zero_points_with_the_reason(): void
    {
        $order = $this->orderUsingPoints(false);
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-'.uniqid(),
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 10500,
            'refund_points_amount' => 3000,
            'refund_shipping_amount' => 0,
        ]);

        $array = (new ClaimResource($refund->fresh(['order'])))->withReasonLabels([])->toArray(request());

        $this->assertEquals(0, $array['refund_points_amount'], '차감되지 않은 마일리지를 반환액으로 보입니다.');
        $this->assertTrue($array['points_not_deducted'] ?? null, '0 인 이유(차감되지 않은 마일리지)를 화면이 알 수 없습니다.');
        $this->assertEquals(10500, $array['refund_amount']);
    }

    /**
     * 차감된 주문의 신청서는 종전 그대로다 (회귀 가드).
     *
     * @scenario scope=partial, priority=pg_first
     *
     * @effects filed_refund_shows_zero_points_for_undeducted_order
     *
     * @return void 반환값 없음
     */
    public function test_filed_refund_of_deducted_order_keeps_its_points(): void
    {
        $order = $this->orderUsingPoints(true);
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-'.uniqid(),
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 10500,
            'refund_points_amount' => 1500,
            'refund_shipping_amount' => 0,
        ]);

        $array = (new ClaimResource($refund->fresh(['order'])))->withReasonLabels([])->toArray(request());

        $this->assertEquals(1500, $array['refund_points_amount']);
        $this->assertFalse($array['points_not_deducted']);
    }

    /**
     * 회원 신청 화면이 「0 (차감되지 않은 마일리지)」와 환불 우선순위 숨김을 판정할 수 있게 주문 응답이 이유를 싣는다.
     *
     * @scenario scope=partial, priority=pg_first
     *
     * @effects undeducted_points_row_shows_zero_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_order_response_says_points_were_not_deducted(): void
    {
        $undeducted = (new OrderResource($this->orderUsingPoints(false)))->resolve();
        $deducted = (new OrderResource($this->orderUsingPoints(true)))->resolve();

        $this->assertTrue($undeducted['points_not_deducted'] ?? null, '주문 응답에 미차감 사유가 없습니다.');
        $this->assertFalse($deducted['points_not_deducted']);
    }

    /**
     * 관리자 클레임 목록의 환불 합계도 상세와 같은 수다 — 환불 탭 행과 취소 탭 행 모두 (D-②a 「응답에서도 0」).
     *
     * @scenario scope=partial, priority=pg_first
     *
     * @effects filed_refund_shows_zero_points_for_undeducted_order
     *
     * @return void 반환값 없음
     */
    public function test_admin_claim_list_rows_total_without_undeducted_points(): void
    {
        foreach ([false => 10500, true => 12000] as $deducted => $expectedTotal) {
            $order = $this->orderUsingPoints((bool) $deducted);
            $cancel = OrderCancel::create([
                'order_id' => $order->id,
                'cancel_number' => 'CL-'.uniqid(),
                'cancel_type' => CancelTypeEnum::PARTIAL,
                'cancel_status' => CancelStatusEnum::REQUESTED,
                'cancel_reason_type' => 'change_of_mind',
                'items_snapshot' => [],
            ]);
            $refund = $this->filedRefund($order, ['order_cancel_id' => $cancel->id, 'refund_points_amount' => (bool) $deducted ? 1500 : 3000]);

            $refundRow = (new ClaimAdminListResource($refund->fresh(['order'])))->withReasonLabels([])->toArray(request());
            $cancelRow = (new ClaimAdminListResource($cancel->fresh(['order', 'refund'])))->withReasonLabels([])->toArray(request());

            $label = $deducted ? '차감 주문' : '미차감 주문';
            $this->assertEquals($expectedTotal, $refundRow['refund_total'], "{$label}: 환불 탭 행 합계가 상세와 다릅니다.");
            $this->assertEquals($expectedTotal, $cancelRow['refund_total'], "{$label}: 취소 탭 행 합계가 상세와 다릅니다.");
        }
    }

    /**
     * 반품·교환 상세의 연결 환불 카드 합계도 상세와 같은 수다 (D-②a 「응답에서도 0」).
     *
     * @scenario scope=partial, priority=pg_first
     *
     * @effects filed_refund_shows_zero_points_for_undeducted_order
     *
     * @return void 반환값 없음
     */
    public function test_linked_refund_card_totals_without_undeducted_points(): void
    {
        foreach ([false => 10500, true => 12000] as $deducted => $expectedTotal) {
            $order = $this->orderUsingPoints((bool) $deducted);
            $refund = $this->filedRefund($order, ['refund_points_amount' => (bool) $deducted ? 1500 : 3000]);

            $card = ClaimLinkResource::envelope(
                ['source' => null, 'refunds' => [$refund->fresh()], 'result_cancel' => null],
                fn (string $number) => '/claims/'.$number,
                $order,
            )['latest_refund'];

            $label = $deducted ? '차감 주문' : '미차감 주문';
            $this->assertEquals($expectedTotal, $card['refund_total'], "{$label}: 연결 환불 카드 합계가 상세와 다릅니다.");
        }
    }

    /**
     * 미차감 주문은 「환불 후 잔여 마일리지」도 0 이다 — 돌려줄 일이 없는 마일리지가 남은 것처럼 보이지 않게(부분·전체 두 경로).
     *
     * @scenario scope=full, priority=pg_first
     *
     * @effects undeducted_points_row_shows_zero_with_reason
     *
     * @return void 반환값 없음
     */
    public function test_remaining_points_balance_is_zero_for_undeducted_order(): void
    {
        foreach ([1 => '부분 취소', 2 => '전체 취소'] as $quantity => $label) {
            $deducted = $this->orderUsingPoints(true);
            $undeducted = $this->orderUsingPoints(false);

            $withPoints = $this->adjustment->preview($deducted, $this->cancel($deducted, $quantity), RefundPriorityEnum::PG_FIRST);
            $withoutPoints = $this->adjustment->preview($undeducted, $this->cancel($undeducted, $quantity), RefundPriorityEnum::PG_FIRST);

            $this->assertEquals(0, $withoutPoints->remainingPointsBalance, "{$label}: 차감되지 않은 마일리지가 잔여로 남았습니다.");
            if ($quantity === 1) {
                $this->assertGreaterThan(0, $withPoints->remainingPointsBalance, '대조군: 차감 주문의 PG 먼저 부분 취소는 마일리지가 남는다');
            }
        }
    }

    /**
     * 승인 상태로 접수된 환불신청서를 만듭니다 (현금 10,500).
     *
     * @param  Order  $order  주문
     * @param  array<string, mixed>  $overrides  덮어쓸 칼럼
     * @return OrderRefund 환불신청서
     */
    private function filedRefund(Order $order, array $overrides = []): OrderRefund
    {
        return OrderRefund::create($overrides + [
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-'.uniqid(),
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 10500,
            'refund_shipping_amount' => 0,
        ]);
    }
}
