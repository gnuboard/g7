<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 핵심 서비스 결함 수정 검증 — 접수 잠금과 분할 로그 (#599 validate P2-A 후속)
 *
 * 검증 축:
 * - 접수 잠금이 `findForUpdate() ?? $order` 로 **잠기지 않은 모델**에 떨어지지 않는다. 떨어지면
 *   잠금 없이 계속 진행해, 두 요청이 같은 잔여 수량을 보고 각각 통과하는 경합이 조용히 되살아난다.
 * - 상품 단위 분할 전이의 로그 변경분(`changes[]`)이 잔여 수량을 발행 시점 값으로 싣는다. 활동 로그
 *   리스너는 그 값을 먼저 읽고, 없으면 잔여 행을 다시 읽는다 — 다시 읽을 때는 그 사이 바뀐 값이 섞인다.
 */
class ValidateP2ClaimLockSplitTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 테스트 통화·사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
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
     * DB 에 없는 주문 ID 를 가진 사본을 만듭니다. (관계는 원본 그대로 — 잠금 조회만 비게 한다)
     *
     * @param  Order  $order  원본 주문
     * @return Order 잠금 조회가 실패하는 주문 사본
     */
    protected function ghostOf(Order $order): Order
    {
        $ghost = $order->replicate();
        $ghost->id = (int) $order->id + 900000;
        $ghost->exists = true;

        return $ghost;
    }

    /**
     * 신청 대상 페이로드를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  string  $reasonCode  사유 코드
     * @param  int  $quantity  신청 수량
     * @return array<string, mixed> 서비스 페이로드
     */
    protected function payload(Order $order, string $reasonCode, int $quantity = 1): array
    {
        return [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]],
            'reason_code' => $reasonCode,
            'requested_by' => $order->user_id,
        ];
    }

    /**
     * 상품준비중(심사 대기 분기) 주문을 만듭니다.
     *
     * @param  int  $quantity  주문 수량
     * @return Order 주문
     */
    protected function preparingOrder(int $quantity = 1): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, $quantity, $policy, ['currency_snapshot' => $this->krwCurrencySnapshot()]);

        return $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::PREPARING);
    }

    /**
     * 상품 단위 전이 훅이 실은 변경분을 모읍니다.
     *
     * @param  string  $type  클레임 유형 (exchange / return / refund)
     * @return \ArrayObject<int, array> 훅마다 받은 `changes` (참조로 채워진다)
     */
    protected function captureChanges(string $type): \ArrayObject
    {
        $captured = new \ArrayObject;

        HookManager::addAction(
            "sirsoft-ecommerce.claim.{$type}.option_status_changed",
            function ($claim, array $changes) use ($captured) {
                $captured->append($changes);
            },
        );

        return $captured;
    }

    // ── 접수 잠금 ──

    /**
     * 접수 잠금 조회가 주문을 찾지 못하면 잠기지 않은 모델로 진행하지 않고 즉시 실패하는지 검증합니다.
     *
     * 수정 전에는 `findForUpdate() ?? $order` 가 잠기지 않은 호출부 모델로 떨어져 계속 진행했다 —
     * 이 사본에서는 옵션을 다시 읽다 `claim_option_not_found` 로 끝났다(잠금 실패가 드러나지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario defect=intake_lock_or_fail
     *
     * @effects claim_intake_fails_instead_of_continuing_unlocked
     */
    public function test_접수_잠금은_잠기지_않은_모델로_떨어지지_않는다(): void
    {
        $delivered = $this->deliveredClaimOrder();
        $preparing = $this->preparingOrder();

        $this->assertThrows(
            fn () => app(OrderReturnService::class)->requestReturn($this->ghostOf($delivered), $this->payload($delivered, 'defective_product')),
            ModelNotFoundException::class,
        );
        $this->assertThrows(
            fn () => app(OrderExchangeService::class)->requestExchange($this->ghostOf($delivered), $this->payload($delivered, 'defective_product')),
            ModelNotFoundException::class,
        );
        $this->assertThrows(
            fn () => app(OrderRefundRequestService::class)->request($this->ghostOf($preparing), $this->payload($preparing, 'changed_mind')),
            ModelNotFoundException::class,
        );
    }

    // ── 분할 로그의 잔여 수량 ──

    /**
     * 반품 상품 단위 분할 전이의 로그 변경분이 잔여 수량을 싣는지 검증합니다.
     *
     * 수정 전에는 `changes[]` 에 잔여 행 ID 만 있어 리스너가 잔여 행을 다시 읽었다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=split_change_remainder_quantity
     *
     * @effects split_change_carries_the_remainder_quantity_for_returns
     */
    public function test_반품_분할_로그는_잔여_수량을_싣는다(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 3);
        $service = app(OrderReturnService::class);
        $return = $service->requestReturn($order, $this->payload($order, 'defective_product', 3));
        $itemId = (int) $return->returnOptions->first()->id;
        $captured = $this->captureChanges('return');

        $service->transitionOption($return, [['id' => $itemId, 'quantity' => 2]], ReturnStatusEnum::REJECTED);

        $this->assertSame(2, $captured[0][0]['quantity'] ?? null);
        $this->assertSame(1, $captured[0][0]['remainder_quantity'] ?? null);
    }

    /**
     * 교환 상품 단위 분할 전이의 로그 변경분이 잔여 수량을 싣는지 검증합니다.
     *
     * 수정 전에는 `changes[]` 에 잔여 행 ID 만 있어 리스너가 잔여 행을 다시 읽었다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=split_change_remainder_quantity
     *
     * @effects split_change_carries_the_remainder_quantity_for_exchanges
     */
    public function test_교환_분할_로그는_잔여_수량을_싣는다(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 3);
        $service = app(OrderExchangeService::class);
        $exchange = $service->requestExchange($order, $this->payload($order, 'defective_product', 3));
        $itemId = (int) $exchange->exchangeOptions->first()->id;
        $captured = $this->captureChanges('exchange');

        $service->transitionOption($exchange, [['id' => $itemId, 'quantity' => 2]], ExchangeStatusEnum::REJECTED);

        $this->assertSame(2, $captured[0][0]['quantity'] ?? null);
        $this->assertSame(1, $captured[0][0]['remainder_quantity'] ?? null);
    }

    /**
     * 환불신청서 상품 단위 분할 전이의 로그 변경분이 잔여 수량을 싣는지 검증합니다.
     *
     * 수정 전에는 `changes[]` 에 잔여 행 ID 만 있어 리스너가 잔여 행을 다시 읽었다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=split_change_remainder_quantity
     *
     * @effects split_change_carries_the_remainder_quantity_for_refunds
     */
    public function test_환불_분할_로그는_잔여_수량을_싣는다(): void
    {
        $order = $this->preparingOrder(3);
        $service = app(OrderRefundRequestService::class);
        $refund = $service->request($order, $this->payload($order, 'changed_mind', 3))['refund'];
        $itemId = (int) $refund->refundOptions->first()->id;
        $captured = $this->captureChanges('refund');

        $service->transitionOption($refund, [['id' => $itemId, 'quantity' => 2]], RefundStatusEnum::REJECTED);

        $this->assertSame(2, $captured[0][0]['quantity'] ?? null);
        $this->assertSame(1, $captured[0][0]['remainder_quantity'] ?? null);
    }
}
