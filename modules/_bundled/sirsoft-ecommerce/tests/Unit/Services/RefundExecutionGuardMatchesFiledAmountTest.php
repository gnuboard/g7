<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\DTO\OrderAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ReturnAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\CancellableAmountCalculator;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\ReadsReturnRefundLink;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 환불 실행 가드는 완료 처리와 **같은 누적**으로 재계산한다 (S-B-D16 · #599 빠른재실측 세션B)
 *
 * **증상**(주문 1519 · 2026-09-22 실측): 5상품 주문에서 1회차 반품 3,000원을 환불 완료한 뒤
 * 잔여를 전량 반품하자 시스템이 환불신청서를 **194,500원**으로 만들었는데, 그 신청서의 환불
 * 실행이 **422** 로 막혔다 — 「환불액(194,500원)이 취소 가능 금액(188,500원)을 초과합니다.」
 * 결제수단 잔여(`paymentCapacity`)는 194,500 으로 충분했고, 막은 것은 도메인 축이다.
 * 시스템이 만든 환불신청서를 시스템이 거부해 그 금액이 **영구 미환불**로 남는다.
 *
 * **원인**: 재계산(`OrderAdjustmentService::buildRecalcInput()`)은 반품완료(`RETURNED`) 행을
 * **잔여로 센다**(S2-D5 독블록이 그렇게 선언한다 — 교환과 달리 승계 행이 없어서다).
 * 완료 처리 경로는 그래서 `completedClaimItems()` 로 **누적**을 넘기는데, 환불 실행 가드
 * (`CancellableAmountCalculator::calculateForRefund()`)만 **이번 신청서 몫**을 넘겼다.
 * 그러면 앞 회차에 이미 반품된 상품이 여전히 잔여로 남아, 그 상품과 **그 상품만 쓰던
 * 배송정책의 배송비**가 한도에서 빠진다(실측 차이 6,000).
 *
 * **고친 방법**: 공용 경로(`buildRecalcInput()`)는 건드리지 않고, 가드도 완료 처리와 같은
 * 누적을 넘긴다(`returnRefundDelta()` — 누적 재계산에서 앞 회차 몫을 뺀 차분, #599 validate P2 #1).
 *
 * **이 테스트가 재는 자리**: 금액이 아니라 **가드가 재계산에 넘기는 입력**이다.
 * 금액으로 재려면 「한 배송정책을 혼자 쓰던 상품이 전량 빠지는」 주문이 필요한데, 공용
 * 주문 빌더는 배송비를 0 으로 두어 그 조건이 성립하지 않는다 — 금액 단언은 결함이 있어도
 * 통과한다(수정을 되돌린 역검증에서 확인). 입력을 재면 주문 구성과 무관하게 규칙이 잠긴다.
 *
 * @scenario return_round=refund_execution_guard
 *
 * @effects refund_execution_guard_accepts_the_amount_it_filed
 */
class RefundExecutionGuardMatchesFiledAmountTest extends ModuleTestCase
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
     * 가드가 재계산에 넘긴 `ReturnAdjustment` 를 가로채 돌려줍니다.
     *
     * `OrderAdjustmentService` 를 감싸 `calculate()` 의 두 번째 인자를 붙잡는다 — 가드가
     * 「이번 신청서 몫」을 넘겼는지 「누적」을 넘겼는지는 이 인자에만 드러난다.
     *
     * @param  Order  $order  대상 주문
     * @param  OrderRefund  $refund  환불신청서
     * @return ReturnAdjustment 가드가 넘긴 변경 DTO
     */
    private function captureGuardAdjustment(Order $order, OrderRefund $refund): ReturnAdjustment
    {
        $captured = null;
        $real = app(OrderAdjustmentService::class);

        $spy = new class($real, $captured) extends OrderAdjustmentService
        {
            /** @var OrderAdjustment|null 가로챈 변경 DTO */
            public ?OrderAdjustment $seen = null;

            /**
             * 실제 서비스를 감싸 인자를 기록합니다.
             *
             * @param  OrderAdjustmentService  $inner  실제 서비스
             * @param  mixed  $unused  사용하지 않음 (익명 클래스 생성자 자리)
             */
            public function __construct(private OrderAdjustmentService $inner, $unused = null) {}

            /**
             * 넘어온 변경 DTO 를 기록하고 실제 계산으로 위임합니다.
             *
             * @param  Order  $order  대상 주문
             * @param  OrderAdjustment  $adjustment  변경 DTO
             * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
             * @return AdjustmentResult 실제 계산 결과
             */
            public function calculate(Order $order, OrderAdjustment $adjustment, RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST): AdjustmentResult
            {
                $this->seen = $adjustment;

                return $this->inner->calculate($order, $adjustment, $refundPriority);
            }
        };

        app()->instance(OrderAdjustmentService::class, $spy);

        try {
            app()->forgetInstance(CancellableAmountCalculator::class);
            app()->make(CancellableAmountCalculator::class)->calculateForRefund($order, $refund);
        } finally {
            app()->instance(OrderAdjustmentService::class, $real);
            app()->forgetInstance(CancellableAmountCalculator::class);
        }

        $this->assertInstanceOf(
            ReturnAdjustment::class,
            $spy->seen,
            '가드가 반품 재계산 경로를 타지 않았습니다 — 측정 조건이 깨졌습니다.'
        );

        return $spy->seen;
    }

    /**
     * 가드는 **앞 회차 완료분까지 합친 누적**을 재계산에 넘긴다.
     *
     * 수정 전에는 이번 신청서 몫만 넘겨, 앞 회차에 이미 반품된 상품이 잔여로 남았다.
     * 그 결과 저장 금액과 실행 한도가 어긋나 정당한 환불이 422 로 막혔다.
     *
     * @scenario return_round=refund_execution_guard
     *
     * @effects refund_execution_guard_accepts_the_amount_it_filed
     *
     * @return void 반환값 없음
     */
    public function test_guard_passes_accumulated_completed_items_to_the_recalculation(): void
    {
        // 수량 2 — 1이면 1회차에 전량이 끝나 「앞 회차가 남아 있는가」축이 성립하지 않는다.
        $order = $this->deliveredClaimOrder(quantity: 2);
        $optionId = $order->options->first()->id;

        $first = $this->completeReturnOf($order, $optionId, 1);
        $firstRefund = OrderRefund::findOrFail($this->returnRefundId($first));
        $firstOptionIds = DB::table('ecommerce_order_refund_options')
            ->where('order_refund_id', $firstRefund->id)
            ->pluck('order_option_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // 앞 회차를 **실행 완료**로 만든다 — 가드의 baseline 분기는 누적 환불액이 0 보다 클 때만
        // 돈다. 실행하지 않으면 이 회귀가 재현되지 않는다.
        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'total_refunded_amount' => $firstRefund->refund_amount,
            'total_refunded_points_amount' => $firstRefund->refund_points_amount,
        ]);

        $second = $this->completeReturnOf($order->fresh(['options']), $optionId, 1);
        $secondRefund = OrderRefund::findOrFail($this->returnRefundId($second));

        $adjustment = $this->captureGuardAdjustment(
            $order->fresh(['options', 'shippings', 'payments']),
            $secondRefund
        );

        $passedIds = array_map(
            static fn (array $item): int => (int) $item['order_option_id'],
            $adjustment->getExcludedItems()
        );

        $this->assertNotEmpty($firstOptionIds, '1회차 환불신청서에 상품 행이 없습니다 — 측정 조건이 깨졌습니다.');

        foreach ($firstOptionIds as $priorId) {
            $this->assertContains(
                $priorId,
                $passedIds,
                '환불 실행 가드가 앞 회차 완료분을 재계산 입력에서 빠뜨렸습니다(S-B-D16). '
                    .'재계산은 `RETURNED` 행을 잔여로 세므로, 이번 몫만 넘기면 앞 회차 상품과 '
                    .'그 배송비가 한도에서 빠져 시스템이 만든 환불신청서를 시스템이 거부합니다. '
                    .'넘긴 옵션='.json_encode($passedIds).' / 1회차 옵션='.json_encode($firstOptionIds)
            );
        }
    }

    /**
     * 그래도 **결제 총액을 넘는 환불은 막힌다.**
     *
     * 위 단언만 있으면 한도를 무한히 열어 두는 구현으로도 통과한다 — 상한이 살아 있는지를
     * 따로 잠근다. 상한은 수단별 잔여(`remainingPg`·`remainingPoints`)가 만든다.
     *
     * @scenario return_round=refund_execution_guard
     *
     * @effects refund_execution_guard_accepts_the_amount_it_filed
     *
     * @return void 반환값 없음
     */
    public function test_guard_still_caps_at_what_the_customer_actually_paid(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $optionId = $order->options->first()->id;

        $first = $this->completeReturnOf($order, $optionId, 1);
        $firstRefund = OrderRefund::findOrFail($this->returnRefundId($first));

        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'total_refunded_amount' => $firstRefund->refund_amount,
            'total_refunded_points_amount' => $firstRefund->refund_points_amount,
        ]);

        $second = $this->completeReturnOf($order->fresh(['options']), $optionId, 1);
        $secondRefund = OrderRefund::findOrFail($this->returnRefundId($second));

        $fresh = $order->fresh(['options', 'shippings', 'payments']);
        $cancellable = app(CancellableAmountCalculator::class)->calculateForRefund($fresh, $secondRefund);

        // 고객이 실제로 낸 돈에서 이미 나간 돈을 뺀 값이 절대 상한이다.
        $paid = (float) $fresh->total_paid_amount + (float) $fresh->total_points_used_amount;
        $alreadyRefunded = (float) $fresh->total_refunded_amount + (float) $fresh->total_refunded_points_amount;
        $remaining = $paid - $alreadyRefunded;

        $this->assertLessThanOrEqual(
            $remaining + 0.01,
            $cancellable->amount,
            '취소 가능 금액이 고객이 낸 잔여를 넘습니다 — 과다 환불이 열립니다. '
                ."취소 가능={$cancellable->amount} / 결제 총액={$paid} / 이미 환불={$alreadyRefunded}"
        );
    }
}
