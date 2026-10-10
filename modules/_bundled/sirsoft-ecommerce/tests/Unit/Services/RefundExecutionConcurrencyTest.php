<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 환불 실행의 한도 경쟁 — 같은 한도를 두 신청서가 나눠 갖는가 (S3-D9 원인 후보)
 *
 * 주문 753 실측에서 **현금 환불 누계가 실결제액을 5,000원 초과**했다. 나는 그 원인을
 * 「가드가 현금·마일리지를 합계 하나로만 비교한다」로 단정했으나, 2026-09-16 새 데이터
 * (주문 965 · 카드 73,000 + 마일리지 20,000) 재현에서 **초과가 나지 않았다** — 실행 가드가
 * 한도 0 에서 62,000 요청을 정확히 막았고 `approved` 선점도 정상 집계됐다.
 *
 * 두 사례의 차이는 **타이밍**뿐이었다. 753 은 두 환불의 실행이 **2초 차**로 겹쳤고,
 * 965 는 내가 순차로 불러 앞 건이 끝난 뒤 다음이 들어갔다. 그래서 남은 가설은
 * **「실행 가드가 트랜잭션 경계를 잡지 않아 동시 실행이 같은 한도를 본다」** 이고,
 * 그것은 S3-D16(전이 훅 중복)과 **같은 형태의 경쟁**이다.
 *
 * HTTP 두 요청으로는 이 축이 서지 않는다 — 타이밍상 한쪽이 밀리면 green 으로 보인다
 * (S3-D16 에서 배운 함정). 그래서 **같은 프로세스에서 연달아 실행**해 잰다.
 */
class RefundExecutionConcurrencyTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClaimReasons();
    }

    /**
     * 한도를 나눠 갖는 두 환불신청서를 만든다.
     *
     * @param  Order  $order  대상 주문
     * @param  float  $each  각 신청서의 환불액
     * @return array<int, OrderRefund> 승인까지 올린 두 신청서
     */
    private function twoApprovedRefunds(Order $order, float $each): array
    {
        $service = app(OrderRefundRequestService::class);
        $refunds = [];

        foreach ([1, 2] as $_) {
            $refund = new OrderRefund;
            $refund->order_id = $order->id;
            $refund->refund_number = 'RF'.uniqid();
            $refund->refund_status = RefundStatusEnum::REQUESTED;
            $refund->refund_amount = $each;
            $refund->refund_points_amount = 0;
            // `bank` 로 둔다 — 이 축은 **한도 판정**을 재는 것이고, PG 호출은 축이 아니다.
            // `pg` 로 두면 테스트가 실제 PG 를 때려 「PG 환불 처리에 실패했습니다」로 죽는다.
            $refund->refund_method = 'bank';
            $refund->reason_code = 'defective_product';
            $refund->save();

            $refunds[] = $service->transition($refund, RefundStatusEnum::APPROVED);
        }

        return $refunds;
    }

    /**
     * 한도를 초과하는 두 환불을 연달아 실행하면 **두 번째는 막혀야 한다.**
     *
     * 실결제 29,000 인 주문에 20,000 짜리 환불 두 건을 승인해 두고 연달아 실행한다.
     * 합계 40,000 은 실결제를 11,000 초과하므로, 두 번째 실행은 반드시 거절돼야 한다.
     *
     * @scenario claim_type=refund, concurrency=back_to_back
     *
     * @effects second_execution_over_limit_is_rejected
     */
    public function test_back_to_back_executions_cannot_exceed_paid_amount(): void
    {
        $order = $this->deliveredClaimOrder(price: 29000, quantity: 1);
        [$first, $second] = $this->twoApprovedRefunds($order, 20000);

        $service = app(OrderRefundRequestService::class);
        $service->executeRefund($first);

        $rejected = false;
        try {
            $service->executeRefund($second);
        } catch (\Throwable $e) {
            $rejected = true;
        }

        $order->refresh();

        $this->assertTrue(
            $rejected,
            '한도를 넘긴 두 번째 환불이 실행됐다 — 실결제액보다 많은 돈이 나간다.'
        );

        $this->assertLessThanOrEqual(
            (float) $order->total_paid_amount,
            (float) $order->total_refunded_amount,
            '환불 누계가 실결제액을 넘었다 (S3-D9 재현).'
        );
    }

    /**
     * 한도 안의 두 환불은 **둘 다 나가야 한다.** (과잉 차단 대조군)
     *
     * 이 축이 없으면 「두 번째를 막는다」를 과하게 구현해 정상 분할 환불까지 막아도 green 이다.
     *
     * @scenario claim_type=refund, concurrency=sequential
     *
     * @effects within_limit_executions_all_succeed
     */
    public function test_within_limit_executions_all_succeed(): void
    {
        $order = $this->deliveredClaimOrder(price: 29000, quantity: 1);
        [$first, $second] = $this->twoApprovedRefunds($order, 10000);

        $service = app(OrderRefundRequestService::class);
        $service->executeRefund($first);
        $service->executeRefund($second);

        $order->refresh();

        $this->assertSame(
            20000.0,
            (float) $order->total_refunded_amount,
            '한도 안의 분할 환불 두 건은 모두 집행돼야 한다.'
        );
    }
}
