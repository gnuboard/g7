<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 순차 부분반품이 끝나면 쿠폰이 **실제로 복원된다** (S2-D5 종단 · #599 통합실측 세션2)
 *
 * 형제 테스트 `ReturnedRowsExcludedFromRecalcTest` 는 `completedClaimItems()` 의 **반환값**
 * 까지만 본다. 그것은 고친 자리를 정확히 겨누지만, **증상 자체**(쿠폰이 `used` 로 굳는다)를
 * 재현하지는 않는다 — 입력이 맞아도 그 뒤 재계산·`detectRestoredCoupons()`·복원 훅 중
 * 한 곳이 끊기면 고객은 여전히 쿠폰을 잃는다(#599 세션2 계획서준수검증 결함4).
 *
 * 실측이 남긴 증상(주문 517 · 2026-09-15):
 *
 *   옵션 6행 전건 `returned` · 환불 5건 전건 `completed` · `total_refunded` 170,500
 *   쿠폰 3장(발급 179·180·181) **끝까지 `used`**
 *   마지막 회차 `recalculated_snapshot`: `subtotal_amount` 158,000 · 쿠폰할인 11,000
 *
 * 전건 반품인데 재계산은 15.8만이 남았다고 본다 — 쿠폰 탈락이 있을 수 없다.
 * **고객은 전액 환불받고도 쿠폰을 잃는다.**
 *
 * 그래서 이 테스트는 **훅이 실제로 발화하는가**를 잰다. 복원의 마지막 관문은
 * `RefundExecutionService::restoreCoupons()` 가 쏘는 `sirsoft-ecommerce.coupon.restore`
 * 훅이고, 그 인자가 비면 아무 쿠폰도 돌아오지 않는다. 훅을 가로채면 `coupon_issues` 를
 * 실제로 되돌리는 리스너(다른 확장 소관)에 기대지 않고 **이 모듈의 계약**만 잰다.
 *
 * 축은 **마지막 회차**다. 중간 회차에서는 남은 상품이 있어 쿠폰 조건이 살아 있을 수 있고,
 * 전건이 반품된 **마지막 회차**에서야 조건이 깨져 복원이 일어나야 한다.
 *
 * @scenario return_round=sequential
 *
 * @effects sequential_return_restores_coupons_at_the_final_round
 */
class SequentialReturnRestoresCouponTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 이 주문에 적용된 **실재하는** 쿠폰 발급 ID.
     *
     * 가짜 ID 를 스냅샷에만 적으면 재계산이 그 쿠폰을 **찾지 못해** 적용 목록에서 늘 빠지고,
     * `array_diff(원 스냅샷, 재계산 적용)` 이 **항상 비지 않는다** — 즉 「언제나 복원」이 되어
     * 대조군이 거짓으로 깨진다(이 테스트를 세우며 실제로 겪었다). 그래서 실제 행을 만든다.
     */
    private int $couponIssueId = 0;

    /**
     * 클레임 사유 카탈로그를 시드해 반품 접수가 사유 코드 검증을 통과하게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClaimReasons();
    }

    /**
     * `coupon.restore` 훅이 받은 쿠폰 ID 를 모읍니다.
     *
     * @param  array<int, array<int, int>>  $captured  발화마다 한 줄씩 쌓인다
     * @return void 반환값 없음
     */
    private function captureCouponRestores(array &$captured): void
    {
        HookManager::addAction(
            'sirsoft-ecommerce.coupon.restore',
            function ($order, $couponIssueIds) use (&$captured) {
                $captured[] = array_values((array) $couponIssueIds);
            }
        );
    }

    /**
     * 주문에 쿠폰 스냅샷을 심습니다.
     *
     * `detectRestoredCoupons()` 는 **주문 스냅샷의 쿠폰**과 **재계산이 적용한 쿠폰**을
     * `array_diff` 한다. 스냅샷이 비어 있으면 그 차집합은 언제나 비어 **복원이 구조적으로
     * 일어날 수 없다** — 그 상태의 통과는 아무것도 증명하지 않으므로 반드시 심는다.
     *
     * 최소주문금액을 상품 총액보다 **낮게** 두어, 전건 반품으로 잔여가 0 이 되면 조건이
     * 깨지도록 만든다.
     *
     * @param  Order  $order  주문
     * @param  int  $minOrderAmount  쿠폰 최소주문금액
     * @return Order 스냅샷이 심긴 주문
     */
    private function applyCouponSnapshot(Order $order, int $minOrderAmount = 20_000): Order
    {
        $couponId = DB::table('ecommerce_promotion_coupons')->insertGetId([
            'name' => '반품 쿠폰 복원 축',
            'target_type' => 'order_amount',
            'discount_type' => 'fixed',
            'discount_value' => 3_000,
            'min_order_amount' => $minOrderAmount,
            'issue_method' => 'direct',
            'issue_condition' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->couponIssueId = DB::table('ecommerce_promotion_coupon_issues')->insertGetId([
            'coupon_id' => $couponId,
            'user_id' => (int) $order->user_id,
            'status' => 'used',
            'issued_at' => now(),
            'used_at' => now(),
            'order_id' => (int) $order->id,
            'discount_amount' => 3_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order->forceFill([
            'promotions_applied_snapshot' => [
                'coupon_issue_ids' => [$this->couponIssueId],
                'order_promotions' => [
                    'coupons' => [[
                        'coupon_issue_id' => $this->couponIssueId,
                        'discount_type' => 'fixed',
                        'discount_value' => 3_000,
                        'min_order_amount' => $minOrderAmount,
                    ]],
                ],
            ],
        ])->save();

        return $order->fresh(['options']);
    }

    /**
     * 반품 1건을 접수해 완료까지 보냅니다. (환불신청서까지 만들어 복원 경로를 탄다)
     *
     * @param  Order  $order  주문
     * @param  int  $optionId  주문 옵션 ID
     * @param  int  $quantity  반품 수량
     * @return OrderReturn 완료된 신청서
     */
    private function completeReturnOf(Order $order, int $optionId, int $quantity): OrderReturn
    {
        $service = app(OrderReturnService::class);

        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $optionId, 'quantity' => $quantity]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        foreach ([
            ReturnStatusEnum::APPROVED,
            ReturnStatusEnum::COLLECTION_READY,
            ReturnStatusEnum::COLLECTING,
            ReturnStatusEnum::COLLECTED,
        ] as $target) {
            $return = $service->transition($return, $target, $this->claimTrackingFor($target));
        }

        $return->load('returnOptions');
        $ids = $return->returnOptions->pluck('id')->all();

        return $service->completeReturn(
            $return,
            $ids,
            new ClaimCompletionOptions(
                restoreStockByItem: array_fill_keys($ids, false),
                createRefundRequest: true,
            ),
        );
    }

    /**
     * 두 회차로 나눠 전량 반품하면 **마지막 회차에서 쿠폰 복원이 발화한다.**
     *
     * S2-D5 이전에는 마지막 회차의 재계산이 **1회차 상품을 잔여로 세어** 쿠폰 최소주문금액이
     * 한 번도 깨지지 않았고, `array_diff` 가 늘 비어 훅이 **한 번도 발화하지 않았다.**
     *
     * @scenario return_round=sequential
     *
     * @effects sequential_return_restores_coupons_at_the_final_round
     *
     * @return void 반환값 없음
     */
    public function test_coupon_restore_fires_after_the_final_sequential_return(): void
    {
        // 수량 2 — 1이면 1회차에 전량이 끝나 「순차」축이 성립하지 않는다.
        $order = $this->applyCouponSnapshot($this->deliveredClaimOrder(quantity: 2));
        $optionId = $order->options->first()->id;

        $captured = [];
        $this->captureCouponRestores($captured);

        $this->completeReturnOf($order, $optionId, 1);
        $this->completeReturnOf($order->fresh(['options']), $optionId, 1);

        $restoredIds = array_merge(...($captured ?: [[]]));

        $this->assertContains(
            $this->couponIssueId,
            $restoredIds,
            '전량을 나눠 반품했는데 쿠폰 복원 훅이 그 쿠폰을 싣지 않았습니다 — 고객은 전액을 '
                .'환불받고도 쿠폰을 잃습니다(S2-D5 의 증상). 마지막 회차의 재계산이 앞 회차 '
                .'상품을 여전히 잔여로 세면 쿠폰 조건이 깨지지 않아 복원이 일어나지 않습니다. '
                .'실제 발화: '.json_encode($captured)
        );

        // 훅 인자만이 아니라 실제 CouponRestoreListener 가 발급 행을 되돌렸는지(T11)
        $issue = DB::table('ecommerce_promotion_coupon_issues')->find($this->couponIssueId);
        $this->assertSame('available', $issue->status);
        $this->assertNull($issue->used_at);
    }

    /**
     * 아직 상품이 남아 있는 **1회차에서는 복원이 일어나지 않는다** — 대조군.
     *
     * 이것이 없으면 위 단언은 「언제나 복원한다」는 구현으로도 통과한다. 그 구현은 반품
     * 한 건만으로 쿠폰을 돌려주어 **쓰지 않은 할인을 다시 쓰게** 만든다 — 돈이 새는 방향이다.
     *
     * @scenario return_round=single
     *
     * @effects sequential_return_restores_coupons_at_the_final_round
     *
     * @return void 반환값 없음
     */
    public function test_coupon_is_not_restored_while_items_remain(): void
    {
        // 최소주문금액을 낮게 잡아 1회차 뒤에도 조건이 살아 있게 한다(잔여 29,000 ≥ 10,000).
        $order = $this->applyCouponSnapshot($this->deliveredClaimOrder(quantity: 2), minOrderAmount: 10_000);
        $optionId = $order->options->first()->id;

        $captured = [];
        $this->captureCouponRestores($captured);

        $this->completeReturnOf($order, $optionId, 1);

        $restoredIds = array_merge(...($captured ?: [[]]));

        $this->assertNotContains(
            $this->couponIssueId,
            $restoredIds,
            '아직 상품이 남아 쿠폰 조건이 살아 있는데 복원이 일어났습니다 — 고객이 쓰지 않은 '
                .'할인을 다시 쓰게 됩니다. 실제 발화: '.json_encode($captured)
        );
        $this->assertSame('used', DB::table('ecommerce_promotion_coupon_issues')->find($this->couponIssueId)->status);
    }
}
