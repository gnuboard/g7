<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\DTO\OrderAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\ReadsReturnRefundLink;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 클레임 핵심 서비스 결함 수정 검증 — 후속 2건 (#599 validate P2-A 후속)
 *
 * 검증 축:
 * - 접수 때 고른 환불 우선순위가 신청서(교환·반품·환불)에 저장되어 응답에 실린다.
 *   저장 컬럼이 없던 동안 `ClaimResource::refund_priority` 는 언제나 null 이었고, 수정 화면은
 *   환불액 구성으로 추측해 PG 우선 신청서를 마일리지 우선으로 뒤집었다.
 * - [금액 수정]의 환불수단 저장과 [환불하기] 실행 게이트가 **같은 판정**을 쓴다. PG 리스너가 없어
 *   계좌 환불로 떨어지는 주문(P14)에서 금액 수정은 PG 저장을 받아 주고 실행은 막아, 저장한
 *   수단으로 실행할 길이 없었다.
 */
class ValidateP2ClaimFollowupTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;
    use ReadsReturnRefundLink;

    /**
     * 서비스와 테스트 통화·사유를 준비합니다.
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
     * 신청 대상 페이로드를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  string  $reasonCode  사유 코드
     * @param  string|null  $priority  고객이 고른 환불 우선순위 (null 이면 싣지 않는다)
     * @return array<string, mixed> 서비스 페이로드
     */
    protected function payload(Order $order, string $reasonCode, ?string $priority): array
    {
        $payload = [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => $reasonCode,
            'requested_by' => $order->user_id,
        ];

        if ($priority !== null) {
            $payload['refund_priority'] = $priority;
        }

        return $payload;
    }

    /**
     * 상품준비중(심사 대기 분기) 카드 결제 주문을 만듭니다.
     *
     * @return Order 주문
     */
    protected function preparingCardOrder(): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(
            29000,
            1,
            $policy,
            ['currency_snapshot' => $this->krwCurrencySnapshot()],
            ['payment_method' => 'card'],
        );

        return $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::PREPARING);
    }

    /**
     * 응답 리소스가 싣는 환불 우선순위를 읽습니다.
     *
     * @param  mixed  $claim  신청서
     * @return string|null 응답의 `refund_priority`
     */
    protected function resourcePriority(mixed $claim): ?string
    {
        return (new ClaimResource($claim->fresh()))->toArray(request())['refund_priority'] ?? null;
    }

    // ── 1) 환불 우선순위 영속화 ──

    /**
     * 교환·반품 접수 때 고른 환불 우선순위가 신청서와 응답에 남는지 검증합니다.
     *
     * 수정 전에는 저장 컬럼이 없어 응답이 언제나 null 이었다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=refund_priority_persisted
     *
     * @effects exchange_and_return_keep_the_chosen_refund_priority
     */
    public function test_교환_반품은_고른_환불_우선순위를_저장한다(): void
    {
        $service = app(UserClaimService::class);

        $returnOrder = $this->deliveredClaimOrder();
        $return = $service->requestReturn($returnOrder, $this->payload($returnOrder, 'defective_product', 'points_first'))['claim'];

        $exchangeOrder = $this->deliveredClaimOrder();
        $exchange = $service->requestExchange($exchangeOrder, $this->payload($exchangeOrder, 'defective_product', 'points_first'))['claim'];

        $this->assertSame(RefundPriorityEnum::POINTS_FIRST, $return->fresh()->refund_priority);
        $this->assertSame(RefundPriorityEnum::POINTS_FIRST, $exchange->fresh()->refund_priority);
        $this->assertSame('points_first', $this->resourcePriority($return));
        $this->assertSame('points_first', $this->resourcePriority($exchange));
    }

    /**
     * 환불신청서 접수 때 고른 환불 우선순위가 저장되는지 검증합니다. (고르지 않으면 계산에 쓴 PG 우선)
     *
     * 수정 전에는 저장 컬럼이 없어 응답이 언제나 null 이었다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=refund_priority_persisted
     *
     * @effects refund_request_keeps_the_priority_it_was_calculated_with
     */
    public function test_환불신청서는_계산에_쓴_환불_우선순위를_저장한다(): void
    {
        $service = app(UserClaimService::class);

        $chosenOrder = $this->preparingCardOrder();
        $chosen = $service->requestRefund($chosenOrder, $this->payload($chosenOrder, 'changed_mind', 'points_first'))['refund'];

        $defaultOrder = $this->preparingCardOrder();
        $default = $service->requestRefund($defaultOrder, $this->payload($defaultOrder, 'changed_mind', null))['refund'];

        $this->assertInstanceOf(OrderRefund::class, $chosen);
        $this->assertSame(RefundPriorityEnum::POINTS_FIRST, $chosen->fresh()->refund_priority);
        $this->assertSame('points_first', $this->resourcePriority($chosen));
        $this->assertSame(RefundPriorityEnum::PG_FIRST, $default->fresh()->refund_priority);
    }

    // ── 2) 환불수단 저장·실행 게이트의 단일 판정 ──

    /**
     * PG 리스너가 없어 계좌 환불로 떨어지는 카드 주문에서 금액 수정이 PG 저장을 거절하는지 검증합니다.
     *
     * 수정 전 금액 수정은 결제수단(`needsPgProvider`)만 보고 PG 를 저장했고, 실행 게이트는
     * 실효 수단(`determineRefundMethod`)을 보고 막았다 — 저장한 수단으로 실행할 길이 없었다.
     *
     * 카드 결제인데 PG 환불을 받는 리스너가 없다는 것은 그 결제 플러그인이 꺼졌다는 뜻이라, 거절 문장은
     * 일반 문장이 아니라 플러그인 꺼짐 사유와 해결책이다(S3-D25 — 실행 경로와 같은 문장).
     *
     * @return void 반환값 없음
     *
     * @scenario defect=refund_method_single_gate
     *
     * @effects amount_update_refuses_pg_when_no_pg_refund_listener
     */
    public function test_pg_리스너가_없는_주문은_금액_수정에서도_pg를_저장하지_않는다(): void
    {
        $order = $this->preparingCardOrder();
        $refund = app(UserClaimService::class)->requestRefund($order, $this->payload($order, 'changed_mind', null))['refund'];

        $this->assertClaimException(
            'pg_provider_inactive',
            fn () => app(OrderRefundRequestService::class)->updateAmounts($refund, ['refund_method' => RefundMethodEnum::PG->value]),
        );

        $this->assertNotSame(RefundMethodEnum::PG, $refund->fresh()->refund_method);
        $this->assertNotContains(
            RefundMethodEnum::PG->value,
            array_column(app(AdminClaimService::class)->refundMethodOptions($order->fresh()), 'value'),
        );
    }

    /**
     * PG 리스너가 있는 카드 주문에서는 선택지와 금액 수정이 함께 PG 를 받는지 검증합니다. (대조군)
     *
     * @return void 반환값 없음
     *
     * @scenario defect=refund_method_single_gate
     *
     * @effects amount_update_and_options_accept_pg_when_listener_exists
     */
    public function test_pg_리스너가_있으면_선택지와_금액_수정이_함께_pg를_받는다(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn (array $default) => $default);

        $order = $this->preparingCardOrder();
        $refund = app(UserClaimService::class)->requestRefund($order, $this->payload($order, 'changed_mind', null))['refund'];

        app(OrderRefundRequestService::class)->updateAmounts($refund, ['refund_method' => RefundMethodEnum::BANK->value]);
        $updated = app(OrderRefundRequestService::class)->updateAmounts($refund->fresh(), ['refund_method' => RefundMethodEnum::PG->value]);

        $this->assertSame(RefundMethodEnum::PG, $updated->refund_method);
        $this->assertContains(
            RefundMethodEnum::PG->value,
            array_column(app(AdminClaimService::class)->refundMethodOptions($order->fresh()), 'value'),
        );
    }

    // ── 3) 접수 뒤 재계산이 저장된 우선순위를 잇는다 ──

    /**
     * 마일리지 10,000 을 섞어 결제한 배송완료 주문을 만듭니다. (반품 고정 3,000 · 교환 왕복 6,000)
     *
     * @param  int  $quantity  주문 수량
     * @return Order 배송완료 주문
     */
    protected function deliveredPointsOrder(int $quantity = 1): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $policy->countrySettings()->where('country_code', 'KR')->update([
            'claim_fee_mode' => 'fixed',
            'return_fixed_fee' => 3000,
            'exchange_fixed_fee' => 6000,
        ]);

        [$product, $option] = $this->createClaimProduct(29000, $policy);

        $order = $this->createClaimOrder(
            new CalculationInput(
                items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: $quantity)],
                usePoints: 10000,
            ),
            ['currency_snapshot' => $this->krwCurrencySnapshot()],
        );

        $this->assertSame(10000.0, (float) $order->total_points_used_amount);

        return $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::DELIVERED);
    }

    /**
     * `preview()` 에 넘어온 환불 우선순위를 기록하는 계산 서비스로 바꿔 끼웁니다.
     *
     * @return \ArrayObject<int, RefundPriorityEnum> 호출마다 받은 우선순위
     */
    protected function spyPreviewPriorities(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $args = array_map(
            fn ($parameter) => app($parameter->getType()->getName()),
            (new ReflectionMethod(OrderAdjustmentService::class, '__construct'))->getParameters(),
        );

        $spy = new class(...$args) extends OrderAdjustmentService
        {
            /** @var \ArrayObject<int, RefundPriorityEnum>|null 기록 대상 */
            public ?\ArrayObject $seen = null;

            /**
             * 우선순위를 기록하고 원래 계산을 수행합니다.
             *
             * @param  Order  $order  주문
             * @param  OrderAdjustment  $adjustment  변경 정보
             * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
             * @return AdjustmentResult 계산 결과
             */
            public function preview(Order $order, OrderAdjustment $adjustment, RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST): AdjustmentResult
            {
                $this->seen?->append($refundPriority);

                return parent::preview($order, $adjustment, $refundPriority);
            }
        };
        $spy->seen = $seen;

        $this->app->instance(OrderAdjustmentService::class, $spy);
        $this->app->forgetInstance(OrderReturnService::class);
        $this->app->forgetInstance(OrderExchangeService::class);

        return $seen;
    }

    /**
     * 마일리지 우선을 고른 부분 반품의 완료 환불신청서가 마일리지부터 채워지고 그 우선순위를 기록하는지 검증합니다.
     *
     * 수정 전 완료 계산은 PG 우선 기본값으로 돌아, 마일리지 잔여 10,000 이 있어도 PG 로만 채워졌다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=refund_priority_carried_after_intake
     *
     * @effects return_completion_refund_follows_the_stored_priority
     */
    public function test_반품_완료_환불신청서는_저장된_우선순위로_나뉜다(): void
    {
        $order = $this->deliveredPointsOrder(2);
        $service = app(OrderReturnService::class);

        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
            'refund_priority' => RefundPriorityEnum::POINTS_FIRST,
        ]);

        foreach ([ReturnStatusEnum::APPROVED, ReturnStatusEnum::COLLECTION_READY, ReturnStatusEnum::COLLECTING, ReturnStatusEnum::COLLECTED] as $step) {
            $return = $service->transition($return, $step, $this->claimTrackingFor($step));
        }

        $completed = $service->completeReturn($return->fresh(['returnOptions']), [], new ClaimCompletionOptions([], true));
        $refund = OrderRefund::query()->findOrFail($this->returnRefundId($completed));

        $this->assertSame(10000.0, (float) $refund->refund_points_amount);
        $this->assertSame(RefundPriorityEnum::POINTS_FIRST, $refund->refund_priority);
    }

    /**
     * 마일리지 우선을 고른 저가 교환의 완료 환불신청서가 마일리지부터 채워지고 그 우선순위를 기록하는지 검증합니다.
     *
     * 수정 전 차액 분할은 PG 우선 고정이라, 환불 4,000(차액 10,000 − 왕복 6,000)이 PG 로만 실렸다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=refund_priority_carried_after_intake
     *
     * @effects exchange_completion_refund_follows_the_stored_priority
     */
    public function test_교환_완료_환불신청서는_저장된_우선순위로_나뉜다(): void
    {
        $order = $this->deliveredPointsOrder();
        $service = app(OrderExchangeService::class);
        $newOption = ProductOptionFactory::new()
            ->forProduct($order->options->first()->product)
            ->create(['price_adjustment' => -10000, 'stock_quantity' => 100, 'is_default' => false, 'is_active' => true]);

        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1, 'new_product_option_id' => $newOption->id]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'refund_priority' => RefundPriorityEnum::POINTS_FIRST,
        ]);

        foreach ([
            ExchangeStatusEnum::APPROVED,
            ExchangeStatusEnum::COLLECTION_READY,
            ExchangeStatusEnum::COLLECTING,
            ExchangeStatusEnum::COLLECTED,
            ExchangeStatusEnum::REDELIVERY_READY,
            ExchangeStatusEnum::REDELIVERING,
            ExchangeStatusEnum::REDELIVERED,
        ] as $step) {
            $exchange = $service->transition($exchange, $step, $this->claimTrackingFor($step));
        }

        $service->completeExchange($exchange->fresh(['exchangeOptions']), [], new ClaimCompletionOptions([], true));
        $refund = OrderRefund::query()->where('order_id', $order->id)->latest('id')->firstOrFail();

        $this->assertSame(4000.0, (float) $refund->refund_points_amount);
        $this->assertSame(0.0, (float) $refund->refund_amount);
        $this->assertSame(RefundPriorityEnum::POINTS_FIRST, $refund->refund_priority);
    }

    /**
     * 신청 수정이 동결 스냅샷을 다시 낼 때 저장된 우선순위를 쓰는지 검증합니다. (교환·반품)
     *
     * 수정 전에는 `PG_FIRST` 고정으로 다시 계산했다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=refund_priority_carried_after_intake
     *
     * @effects update_request_recalculates_with_the_stored_priority
     */
    public function test_신청_수정의_재계산은_저장된_우선순위를_쓴다(): void
    {
        $returnOrder = $this->deliveredPointsOrder();
        $return = app(OrderReturnService::class)->requestReturn($returnOrder, [
            'items' => [['order_option_id' => $returnOrder->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
            'refund_priority' => RefundPriorityEnum::POINTS_FIRST,
        ]);

        $exchangeOrder = $this->deliveredPointsOrder();
        $exchange = app(OrderExchangeService::class)->requestExchange($exchangeOrder, [
            'items' => [['order_option_id' => $exchangeOrder->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
            'refund_priority' => RefundPriorityEnum::POINTS_FIRST,
        ]);

        $seen = $this->spyPreviewPriorities();

        app(OrderReturnService::class)->updateRequest($return, [
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST->value,
        ]);
        app(OrderExchangeService::class)->updateRequest($exchange, [
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST->value,
        ]);

        $values = array_values(array_unique(array_map(fn (RefundPriorityEnum $priority) => $priority->value, $seen->getArrayCopy())));

        $this->assertSame([RefundPriorityEnum::POINTS_FIRST->value], $values);
    }
}
