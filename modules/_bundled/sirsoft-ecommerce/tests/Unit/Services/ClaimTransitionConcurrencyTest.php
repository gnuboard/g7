<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 신청서 전이의 동시성 멱등 — 더블클릭·재시도 (S3-D16)
 *
 * 전이는 「현재 상태를 읽어 → 갈 수 있는지 보고 → 쓴다」 순서다. 판정을 **메모리에 들고 있는
 * 모델 값**으로 하면 동시에 들어온 두 요청이 둘 다 같은 출발 상태를 보고 둘 다 가드를 통과한다.
 * 쓰기는 같은 값이라 **상태만 보면 1회처럼 보이는데**, 전이 훅이 두 번 돌아 **알림이 채널마다
 * 2통씩** 나간다(2026-09-16 T10 실측: `return_approved` 가 database·mail·sms·alimtalk 전부 ×2).
 *
 * 상태 기계는 **같은 상태로의 재진입을 허용한다**(`canTransitionTo` 가 self 를 true 로 낸다).
 * 그래서 예외도 상태도 판정 근거가 못 된다 — 상태는 고쳐지기 전에도 정상으로 보였고 예외는
 * 원래 나지 않는다. 이 스위트는 **「전이 훅이 몇 번 돌았는가」** 를 센다 — 그것이 알림 통수와
 * 1:1 로 대응하는 유일한 관측값이다.
 */
class ClaimTransitionConcurrencyTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 클레임 사유 카탈로그를 시드해 반품·교환 접수가 사유 코드 검증을 통과하게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClaimReasons();
    }

    /**
     * 배송완료 주문에 교환을 접수해 승인까지 올립니다.
     *
     * @return OrderExchange 승인 상태로 전이된 교환 신청서
     */
    private function approvedExchange(): OrderExchange
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderExchangeService::class);

        $exchange = $service->requestExchange($order, [
            'items' => [[
                'order_option_id' => $order->options->first()->id,
                'quantity' => 1,
            ]],
            'reason_code' => 'defective_product',
        ]);

        return $service->transition($exchange, ExchangeStatusEnum::APPROVED);
    }

    /**
     * 같은 전이를 두 번 지시해도 전이 훅은 **한 번만** 돌아야 한다. (교환)
     *
     * 잠금·판정·쓰기가 한 트랜잭션 안에 있으면, 늦게 들어온 쪽은 앞선 전이가 커밋된 뒤의 상태를
     * 읽어 **훅을 한 번만** 돌린다. 잠금 없이 메모리 값으로 판정하면 둘 다 통과해 훅이 두 번 돌고,
     * 그만큼 알림이 채널마다 2통씩 나간다.
     *
     * 되돌림 축(2026-09-16 실측): 수정을 되돌리고 `requested → approved` 를 동시 2회 보내면
     * `return_approved` 가 database·mail·sms·alimtalk **전부 ×2** — 수정 후엔 전부 ×1 이다.
     *
     * @scenario claim_type=exchange, concurrency=simultaneous
     *
     * @effects duplicate_transition_is_rejected_under_row_lock
     *
     * @return void 반환값 없음
     */
    public function test_repeating_the_same_exchange_transition_is_rejected(): void
    {
        $exchange = $this->approvedExchange();
        $service = app(OrderExchangeService::class);

        // 더블클릭의 실제 형태 — 호출층이 **낡은 인스턴스를 그대로 다시 넘긴다**.
        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.status_changed', function () use (&$fired) {
            $fired++;
        });

        $service->transition($exchange, ExchangeStatusEnum::COLLECTION_READY);
        $service->transition($exchange, ExchangeStatusEnum::COLLECTION_READY);

        // 상태 기계는 같은 상태로의 재진입을 허용한다(`canTransitionTo` 가 self 를 true 로 낸다).
        // 그래서 **예외가 아니라 「훅이 몇 번 돌았는가」** 로 판정한다 — 훅이 두 번 돌면 알림이
        // 채널마다 2통씩 나간다(2026-09-16 실측: 수정 전 database·mail·sms·alimtalk 전부 ×2).
        $this->assertSame(
            1,
            $fired,
            '같은 전이를 두 번 지시했는데 훅이 두 번 돌면 구매자에게 같은 알림이 두 번 간다.'
        );
    }

    /**
     * 같은 전이를 두 번 지시해도 전이 훅은 **한 번만** 돌아야 한다. (반품)
     *
     * 교환과 **같은 형태**라 한쪽만 고치면 다른 쪽에 같은 중복이 남는다.
     *
     * @scenario claim_type=return, concurrency=simultaneous
     *
     * @effects duplicate_transition_is_rejected_under_row_lock
     *
     * @return void 반환값 없음
     */
    public function test_repeating_the_same_return_transition_is_rejected(): void
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderReturnService::class);

        $return = $service->requestReturn($order, [
            'items' => [[
                'order_option_id' => $order->options->first()->id,
                'quantity' => 1,
            ]],
            'reason_code' => 'defective_product',
        ]);

        $return = $service->transition($return, ReturnStatusEnum::APPROVED);

        $fired = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.return.status_changed', function () use (&$fired) {
            $fired++;
        });

        $service->transition($return, ReturnStatusEnum::COLLECTION_READY);
        $service->transition($return, ReturnStatusEnum::COLLECTION_READY);

        $this->assertSame(1, $fired, '반품도 교환과 같은 형태다 — 한쪽만 고치면 다른 쪽에 중복이 남는다.');
    }

    /**
     * 잠금을 넣었다고 **정상 순차 전이가 막히면 안 된다.**
     *
     * 이 테스트가 없으면 「두 번째를 거절한다」를 과하게 구현해 정상 흐름까지 막아도 green 이다.
     *
     * @scenario claim_type=exchange, concurrency=sequential
     *
     * @effects sequential_transition_still_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_sequential_transitions_still_advance(): void
    {
        $exchange = $this->approvedExchange();
        $service = app(OrderExchangeService::class);

        foreach ([
            ExchangeStatusEnum::COLLECTION_READY,
            ExchangeStatusEnum::COLLECTING,
            ExchangeStatusEnum::COLLECTED,
            ExchangeStatusEnum::REDELIVERY_READY,
            ExchangeStatusEnum::REDELIVERING,
            ExchangeStatusEnum::REDELIVERED,
        ] as $step) {
            $exchange = $service->transition($exchange, $step, $this->claimTrackingFor($step));
        }

        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $exchange->exchange_status);
    }

    /**
     * 반품도 순차 전이는 그대로 간다.
     *
     * @scenario claim_type=return, concurrency=sequential
     *
     * @effects sequential_transition_still_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_sequential_return_transitions_still_advance(): void
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderReturnService::class);

        $return = $service->requestReturn($order, [
            'items' => [[
                'order_option_id' => $order->options->first()->id,
                'quantity' => 1,
            ]],
            'reason_code' => 'defective_product',
        ]);

        foreach ([
            ReturnStatusEnum::APPROVED,
            ReturnStatusEnum::COLLECTION_READY,
            ReturnStatusEnum::COLLECTING,
            ReturnStatusEnum::COLLECTED,
        ] as $step) {
            $return = $service->transition($return, $step, $this->claimTrackingFor($step));
        }

        $this->assertSame(ReturnStatusEnum::COLLECTED, $return->return_status);
    }
}
