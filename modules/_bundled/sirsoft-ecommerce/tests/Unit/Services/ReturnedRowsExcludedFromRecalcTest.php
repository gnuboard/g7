<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 순차 부분반품의 재계산 기준 — 누적 완료분 (S2-D5 · #599 통합실측 세션2)
 *
 * **증상**: 상품을 여러 회차로 나눠 전부 반품해도 **쿠폰이 영영 복원되지 않는다.**
 * 실측(주문 517 · 2026-09-15): 옵션 6행 전건 `returned` · 환불 5건 전건 `completed` ·
 * `total_refunded` 170,500 인데 쿠폰 3장(발급 179·180·181)이 **끝까지 `used`** 였다.
 * 마지막 회차의 `recalculated_snapshot` 이 `subtotal_amount 158,000` · 쿠폰할인 11,000 —
 * 전건 반품인데 15.8만이 남아 있다고 계산한다. 고객은 전액 환불받고도 쿠폰을 잃는다.
 *
 * **원인**: 재계산(`OrderAdjustmentService::buildRecalcInput()`)은 반품완료(`RETURNED`) 행을
 * **잔여로 센다.** 교환과 달리 승계 행이 없어 그렇게 설계돼 있고, 그 독블록이 그렇게 선언한다.
 * 그래서 완료 처리가 「이번 회차 몫」만 넘기면 재계산은 **원 주문 기준 단건 시뮬레이션**이 되고,
 * 앞 회차에 이미 반품된 상품이 여전히 남아 있다고 본다. 쿠폰 조건이 한 번도 깨지지 않으니
 * `detectRestoredCoupons()` 의 `array_diff` 가 항상 비고 복원 훅이 발화하지 않는다.
 *
 * **고친 방법(선택지 ②)**: 공용 경로는 **건드리지 않고**, 반품이 자기 입력을 **누적**으로
 * 넘긴다 — `completedClaimItems()` 가 이 신청서의 완료분에 **같은 주문의 다른 신청서
 * 완료분**을 합산한다.
 *
 * 선택지 ①(`buildRecalcInput()` 의 제외 목록에 `RETURNED` 추가)을 쓰지 않은 이유는
 * 그 메서드가 **취소·환불·반품·교환이 모두 타는 단일 경로**이기 때문이다. 거기를 고치면
 * 세 세션(환불·반품·교환)의 금액이 동시에 움직이고, 교환은 `EXCHANGED` 승계 규칙과
 * 상호작용한다. 반품 안에서 끝내면 같은 결과를 **공용 계약 변경 없이** 얻는다.
 *
 * 소유 시나리오: `returned-rows-in-recalc` (축 매핑은 각 테스트 메서드에 있다)
 */
class ReturnedRowsExcludedFromRecalcTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

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
     * 완료 처리용 아이템을 만듭니다. (protected 메서드 직접 호출)
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return array 재계산에 넘길 완료분 목록 [{order_option_id, cancel_quantity}]
     */
    private function completedItems(OrderReturn $return): array
    {
        $method = new ReflectionMethod(OrderReturnService::class, 'completedClaimItems');
        $method->setAccessible(true);

        return $method->invoke(app(OrderReturnService::class), $return->fresh('returnOptions'));
    }

    /**
     * 반품 1건을 접수해 완료까지 보냅니다.
     *
     * @param  Order  $order  주문
     * @param  int  $optionId  주문 옵션 ID
     * @param  int  $quantity  반품 수량
     * @return OrderReturn 완료된 신청서
     */
    private function completeReturnOf($order, int $optionId, int $quantity): OrderReturn
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
                createRefundRequest: false,
            ),
        );
    }

    /**
     * 2회차 신청서의 재계산 입력에 **1회차 완료분이 함께 실린다.** (S2-D5 본체)
     *
     * 이것이 빠지면 2회차 재계산이 「1회차 상품은 아직 주문에 남아 있다」고 보고,
     * 쿠폰 조건이 깨지지 않아 복원이 일어나지 않는다.
     *
     * @scenario return_round=sequential
     *
     * @effects sequential_return_recalc_counts_previously_returned_rows
     *
     * @return void 반환값 없음
     */
    public function test_second_return_includes_the_first_returns_completed_quantity(): void
    {
        // 수량 2 — 1이면 1회차에 전량 반품완료가 되어 2회차 접수 자체가 막힌다(순차 축이 성립하지 않는다).
        $order = $this->deliveredClaimOrder(quantity: 2);
        $optionId = $order->options->first()->id;

        $first = $this->completeReturnOf($order, $optionId, 1);
        $second = $this->completeReturnOf($order->fresh(['options']), $optionId, 1);

        $items = $this->completedItems($second);

        // 부분 반품은 주문 옵션 행을 **쪼갠다**(`source_type=split`) — 1회차와 2회차는 서로 다른
        // `order_option_id` 를 가리킨다. 그래서 「한 줄로 합쳐지는가」가 아니라
        // **「총 반품 수량이 누적되는가」**가 이 축의 계약이다.
        $total = array_sum(array_column($items, 'cancel_quantity'));

        $this->assertSame(
            2,
            $total,
            '2회차 재계산에 1회차 완료분이 빠졌습니다 — 앞 회차 상품이 잔여로 남아 쿠폰 조건이 깨지지 않습니다. '
                ."(1회차 신청서 {$first->return_number} · 실제 실린 줄: ".json_encode($items).')'
        );

        // 같은 옵션 ID 가 두 줄로 중복되면 재계산이 수량을 두 번 세거나 덮어쓴다.
        $ids = array_column($items, 'order_option_id');
        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            '같은 order_option_id 가 여러 줄로 실렸습니다 — 합산이 되지 않았습니다.'
        );
    }

    /**
     * 1회차만 있을 때는 자기 몫만 실린다 — 대조군.
     *
     * 이 테스트가 없으면 위의 「2」가 **누적이라서 2 인지 무조건 2 인지** 가려지지 않는다.
     *
     * @scenario return_round=single
     *
     * @effects sequential_return_recalc_counts_previously_returned_rows
     *
     * @return void 반환값 없음
     */
    public function test_first_return_counts_only_its_own_quantity(): void
    {
        $order = $this->deliveredClaimOrder();
        $optionId = $order->options->first()->id;

        $first = $this->completeReturnOf($order, $optionId, 1);

        $items = $this->completedItems($first);

        $this->assertCount(1, $items);
        $this->assertSame(
            1,
            $items[0]['cancel_quantity'],
            '앞 회차가 없는데 수량이 1 이 아닙니다 — 누적 합산이 자기 몫을 중복해 세고 있습니다.'
        );
    }

    /**
     * 완료되지 않은 신청서(철회·반려·진행 중)는 합산에서 빠진다.
     *
     * 이것이 새면 **아직 돌려받지도 않은 상품**이 잔여에서 빠져 환불액이 부풀고,
     * 쿠폰이 조기에 복원된다 — 돈이 어긋나는 방향이라 누적보다 위험하다.
     *
     * @scenario return_round=incomplete_excluded
     *
     * @effects incomplete_returns_are_not_counted_as_returned
     *
     * @return void 반환값 없음
     */
    public function test_incomplete_returns_are_not_counted(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $optionId = $order->options->first()->id;

        $service = app(OrderReturnService::class);

        // 접수만 하고 완료하지 않은 신청서
        $pending = $service->requestReturn($order, [
            'items' => [['order_option_id' => $optionId, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        $completed = $this->completeReturnOf($order->fresh(['options']), $optionId, 1);

        $items = $this->completedItems($completed);

        $this->assertSame(
            1,
            $items[0]['cancel_quantity'],
            "미완료 신청서({$pending->return_number})가 누적에 섞였습니다 — 돌려받지 않은 상품이 "
                .'잔여에서 빠져 환불액이 부풀고 쿠폰이 조기 복원됩니다.'
        );
    }

    /**
     * 공용 재계산 경로는 **바뀌지 않았다** — 선택지 ② 를 택한 근거를 고정한다.
     *
     * `buildRecalcInput()` 이 여전히 `CANCELLED`·`EXCHANGED` 만 빼는지 소스에서 확인한다.
     * 누군가 나중에 여기에 `RETURNED` 를 더하면 **반품 쪽 누적과 이중으로 빠져** 같은 상품이
     * 두 번 제외된다 — 환불액이 과다 산정되는 방향이라 조용히 지나가면 안 된다.
     *
     * @scenario return_round=shared_path_unchanged
     *
     * @effects shared_recalc_path_still_counts_returned_rows
     *
     * @return void 반환값 없음
     */
    public function test_shared_recalc_path_is_unchanged(): void
    {
        $path = (new ReflectionClass(OrderAdjustmentService::class))
            ->getFileName();

        $source = (string) file_get_contents($path);

        $start = strpos($source, 'private function buildRecalcInput(');
        $this->assertNotFalse($start, 'buildRecalcInput() 을 찾지 못했습니다.');

        $body = substr($source, $start, 2000);

        $this->assertStringContainsString('OrderStatusEnum::CANCELLED', $body);
        $this->assertStringContainsString('OrderStatusEnum::EXCHANGED', $body);

        $this->assertStringNotContainsString(
            'OrderStatusEnum::RETURNED',
            $body,
            '공용 재계산 경로에 RETURNED 제외가 추가됐습니다. 반품은 이미 `completedClaimItems()` 에서 '
                .'누적으로 빼고 있으므로 **같은 상품이 두 번 제외**되어 환불액이 과다 산정됩니다. '
                .'둘 중 하나만 남겨야 합니다.'
        );
    }
}
