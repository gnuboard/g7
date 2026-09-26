<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\ReadsReturnRefundLink;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회차별 환불신청서는 **자기 몫**만 담는다 (S-B-D7 · #599 빠른재실측 세션B)
 *
 * 형제 테스트 `ReturnedRowsExcludedFromRecalcTest` 는 재계산 기준선이 **누적**인지를 잠근다.
 * 그 누적은 쿠폰 탈락·배송비 재판정을 맞추려는 의도된 설계다(S2-D5). 이 테스트가 잠그는
 * 것은 그 뒤의 한 걸음이다 — **누적 결과를 신규 환불신청서에 그대로 실으면 안 된다.**
 *
 * 실측이 남긴 증상(주문 1500 · 2026-09-21 세션B):
 *
 *   회차① 후드티 1개 반품 → 환불신청서 42,000원 **실행 완료**(취소신청서까지 생성)
 *   회차② 단종상품 1개 반품(신청서 미리보기 15,000원)
 *     → 완료 모달 예고 60,000원 · 생성된 환불신청서 refund_amount 60,000원
 *     → 그 옵션 행에 회차①의 order_option_id 1990(41,702.50원)이 **다시 포함**
 *
 * 실행했다면 42,000 + 60,000 = 102,000원이 나간다. 돌려줘야 할 돈은 57,000원이므로
 * **45,000원 과다 환불**이다. 회차가 늘수록 눈덩이처럼 커진다.
 *
 * 두 축으로 잠근다 — **금액**(회차 합이 누적과 같은가)과 **상품 행**(앞 회차 옵션이
 * 다시 들어오지 않는가). 금액만 재면 옵션 행이 중복돼도 통과하고, 옵션 행만 재면
 * 헤더 금액이 누적인 채로도 통과한다.
 *
 * @scenario return_round=refund_request_delta
 *
 * @effects sequential_return_refund_request_carries_only_its_own_share
 */
class SequentialReturnRefundShareTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;
    use ReadsReturnRefundLink;

    /**
     * 클레임 사유 카탈로그를 심고 시작합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClaimReasons();
    }

    /**
     * 반품 1건을 접수해 완료까지 보내고 환불신청서를 만듭니다.
     *
     * 회수비는 0(판매자 귀책 계열)으로 둔다 — 차감이 섞이면 「회차 합 = 누적」 단언이
     * 회수비만큼 어긋나 축이 흐려진다. 회수비 차감 축은 브라우저 실측이 따로 잰다.
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
            'reason_code' => 'defective_product',
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
     * 신청서가 만든 환불신청서의 금액을 읽습니다.
     *
     * @param  OrderReturn  $return  완료된 신청서
     * @return float 환불신청서 금액
     */
    private function refundAmountOf(OrderReturn $return): float
    {
        return (float) DB::table('ecommerce_order_refunds')
            ->where('id', $this->returnRefundId($return))
            ->value('refund_amount');
    }

    /**
     * 환불신청서가 담은 주문옵션 ID 목록을 읽습니다.
     *
     * @param  OrderReturn  $return  완료된 신청서
     * @return array<int, int> 주문옵션 ID
     */
    private function refundOptionIdsOf(OrderReturn $return): array
    {
        return DB::table('ecommerce_order_refund_options')
            ->where('order_refund_id', $this->returnRefundId($return))
            ->pluck('order_option_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * 두 회차로 나눠 반품하면 **회차별 환불액의 합이 누적 환불액과 같다.**
     *
     * 수정 전에는 2회차 환불신청서가 누적액을 그대로 담아 합이 누적을 **넘었다**
     * (실측: 42,000 + 60,000 = 102,000, 정상 57,000).
     *
     * @scenario return_round=refund_request_delta
     *
     * @effects sequential_return_refund_request_carries_only_its_own_share
     *
     * @return void 반환값 없음
     */
    public function test_refund_amounts_of_each_round_sum_to_the_accumulated_total(): void
    {
        // 수량 2 — 1이면 1회차에 전량이 끝나 「순차」축이 성립하지 않는다.
        $order = $this->deliveredClaimOrder(quantity: 2);
        $optionId = $order->options->first()->id;

        $first = $this->completeReturnOf($order, $optionId, 1);
        $firstAmount = $this->refundAmountOf($first);

        $second = $this->completeReturnOf($order->fresh(['options']), $optionId, 1);
        $secondAmount = $this->refundAmountOf($second);

        // 전량(2개)을 반품했으므로 두 회차 합은 상품 소계 전액이어야 한다. 환불을 *실행*하지는
        // 않았으므로 `total_refunded_amount` 는 0 이다 — 그 값을 기준으로 삼으면 안 된다.
        $expected = (float) $order->fresh()->subtotal_amount;

        $this->assertEqualsWithDelta(
            $expected,
            $firstAmount + $secondAmount,
            0.01,
            '회차별 환불신청서 금액의 합이 상품 소계와 다릅니다 — 2회차가 1회차 몫을 다시 담으면 '
                .'합이 소계를 넘어 과다 환불이 됩니다(S-B-D7). '
                ."1회차={$firstAmount} / 2회차={$secondAmount} / 상품 소계={$expected}"
        );

        // 두 회차가 같은 상품 1개씩이므로 금액도 같아야 한다. 이 단언이 없으면 2회차가 누적
        // (= 소계 전액)을 담고 1회차가 0 인 구현으로도 위 합계 단언이 통과한다.
        $this->assertEqualsWithDelta(
            $firstAmount,
            $secondAmount,
            0.01,
            '같은 상품 1개씩을 두 회차로 반품했는데 회차별 환불액이 다릅니다 — 2회차가 누적을 '
                ."담고 있을 수 있습니다. 1회차={$firstAmount} / 2회차={$secondAmount}"
        );
    }

    /**
     * 2회차 환불신청서에 **1회차 상품 행이 들어오지 않는다.**
     *
     * 부분 반품은 주문 옵션 행을 쪼개므로 회차마다 `order_option_id` 가 다르다 — 그래서
     * 「2회차 목록에 1회차 ID 가 없다」로 잴 수 있다. 수정 전에는 1회차 ID 가 2회차
     * 환불신청서에 그대로 들어 있었다(실측: `order_option_id` 1990).
     *
     * @scenario return_round=refund_request_delta
     *
     * @effects sequential_return_refund_request_carries_only_its_own_share
     *
     * @return void 반환값 없음
     */
    public function test_second_round_refund_request_excludes_the_first_round_item(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $optionId = $order->options->first()->id;

        $first = $this->completeReturnOf($order, $optionId, 1);
        $firstOptionIds = $this->refundOptionIdsOf($first);

        $second = $this->completeReturnOf($order->fresh(['options']), $optionId, 1);
        $secondOptionIds = $this->refundOptionIdsOf($second);

        $this->assertNotEmpty($firstOptionIds, '1회차 환불신청서에 상품 행이 없습니다 — 측정 조건이 깨졌습니다.');
        $this->assertNotEmpty($secondOptionIds, '2회차 환불신청서에 상품 행이 없습니다 — 측정 조건이 깨졌습니다.');

        $overlap = array_values(array_intersect($firstOptionIds, $secondOptionIds));

        $this->assertSame(
            [],
            $overlap,
            '2회차 환불신청서가 1회차에서 이미 환불한 상품 행을 다시 담았습니다 — 같은 상품이 '
                .'두 번 환불됩니다(S-B-D7). 겹친 주문옵션 ID: '.json_encode($overlap)
                .' / 1회차='.json_encode($firstOptionIds)
                .' / 2회차='.json_encode($secondOptionIds)
        );
    }
}
