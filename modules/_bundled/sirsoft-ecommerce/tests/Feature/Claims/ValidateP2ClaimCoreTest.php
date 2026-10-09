<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Support\ClaimTransitionVocabulary;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use RuntimeException;

/**
 * 클레임 핵심 서비스 결함 수정 검증 — 전이 게이트·종결 정산·원자성·입력 방어 (#599 validate P2-A)
 *
 * 검증 축:
 * - 상품 단위 승인이 헤더 승인과 같은 미수납 게이트를 거친다(#1).
 * - 상품 단위 전량 반려로 헤더가 종결되면 대기 결제를 닫는다(#2).
 * - 일괄 전이가 건별 예외를 skip 으로 보고한다(#11).
 * - 금액 수정과 청구 정산이 한 트랜잭션이다(#12).
 * - 이미 낸 청구를 환불금 차감으로 바꾸지 못한다(#13).
 * - 알 수 없는 청구 방식·환불 우선순위가 500 이 아니라 도메인 예외다(#10).
 * - 기준 통화 해석이 한 규칙(스냅샷 → 설정 기본 통화)이다(#14).
 */
class ValidateP2ClaimCoreTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;

    protected AdminClaimService $admin;

    protected ClaimPaymentService $payments;

    protected OrderReturnService $returns;

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

        $this->admin = app(AdminClaimService::class);
        $this->payments = app(ClaimPaymentService::class);
        $this->returns = app(OrderReturnService::class);
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
     * 고객 귀책(회수비 3,000원) 반품을 접수합니다.
     *
     * @param  ClaimChargeMethodEnum  $method  청구 방식
     * @param  Order|null  $order  대상 주문 (없으면 새로 만든다)
     * @return OrderReturn 접수된 반품 신청서
     */
    protected function chargedReturn(ClaimChargeMethodEnum $method, ?Order $order = null): OrderReturn
    {
        $order ??= $this->deliveredClaimOrder();

        return $this->returns->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => $method,
            'requested_by' => $order->user_id,
        ]);
    }

    /**
     * 판매자 귀책(청구 0원) 반품을 접수합니다.
     *
     * @param  int|null  $requestedBy  신청자 ID (비회원이면 null)
     * @return OrderReturn 접수된 반품 신청서
     */
    protected function freeReturn(?int $requestedBy = null): OrderReturn
    {
        $order = $this->deliveredClaimOrder();

        return $this->returns->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
            'requested_by' => $requestedBy,
        ]);
    }

    /**
     * 결제대기까지 간 반품(신청 시점 결제)과 그 대기 결제행을 만듭니다.
     *
     * @return array{0: OrderReturn, 1: ClaimPayment} 신청서와 대기 결제행
     */
    protected function paymentPendingReturn(): array
    {
        $return = $this->chargedReturn(ClaimChargeMethodEnum::PAY_ON_REQUEST);
        $payment = $this->payments->initiateForRequest($return, PaymentMethodEnum::CARD->value);

        return [$return->fresh(['returnOptions']), $payment];
    }

    // ── #1 상품 단위 승인의 미수납 게이트 ──

    /**
     * 신청 시점 결제의 미수납 반품을 상품 단위로 승인하면 헤더 승인과 같이 거절하는지 검증합니다.
     *
     * 예전에는 상품 행 승인이 게이트를 거치지 않아 헤더가 `payment_pending → approved` 로
     * 따라가 돈을 받지 않은 채 승인됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=item_approval_gate
     *
     * @effects item_approval_with_outstanding_charge_is_refused_like_the_header
     */
    public function test_미수납_반품의_상품_단위_승인은_거절된다(): void
    {
        [$return] = $this->paymentPendingReturn();
        $itemId = (int) $return->returnOptions->first()->id;

        $this->assertClaimException('claim_payment_outstanding', fn () => $this->admin->transitionOptions(
            $return,
            [$itemId],
            ReturnStatusEnum::APPROVED->value,
        ));

        $fresh = $return->fresh(['returnOptions']);
        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $fresh->return_status);
        $this->assertNotSame(ReturnStatusEnum::APPROVED, $fresh->returnOptions->first()->option_status);
    }

    /**
     * 승인 시점 결제의 미수납 반품을 상품 단위로 승인하면 결제 요청으로 우회하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=item_approval_gate
     *
     * @effects item_approval_on_pay_on_approval_requests_payment_instead
     */
    public function test_승인_시점_결제의_상품_단위_승인은_결제_요청으로_우회한다(): void
    {
        $return = $this->chargedReturn(ClaimChargeMethodEnum::PAY_ON_APPROVAL)->fresh(['returnOptions']);
        $itemId = (int) $return->returnOptions->first()->id;

        $result = $this->admin->transitionOptions($return, [$itemId], ReturnStatusEnum::APPROVED->value);

        $this->assertTrue($result['payment_requested'] ?? false);
        $this->assertSame([], $result['succeeded']);
        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);
    }

    /**
     * 상품 행 어휘가 결제대기 행에 승인을 내지 않는지 검증합니다. (헤더 축은 그대로)
     *
     * @return void 반환값 없음
     *
     * @scenario defect=item_approval_gate
     *
     * @effects item_vocabulary_hides_approval_on_payment_pending_rows
     */
    public function test_상품_행_어휘는_결제대기_행에_승인을_내지_않는다(): void
    {
        foreach ([ReturnStatusEnum::PAYMENT_PENDING, ExchangeStatusEnum::PAYMENT_PENDING] as $status) {
            $this->assertNotContains('approved', ClaimTransitionVocabulary::valuesFrom($status, true));
            $this->assertContains('approved', ClaimTransitionVocabulary::valuesFrom($status));
        }
    }

    // ── #2 상품 단위 전량 반려의 종결 정산 ──

    /**
     * 상품 단위 전량 반려로 헤더가 반려되면 대기 결제행을 취소하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=item_termination_money
     *
     * @effects item_rejection_closing_the_header_cancels_the_pending_payment
     */
    public function test_상품_단위_전량_반려는_대기_결제를_닫는다(): void
    {
        [$return, $payment] = $this->paymentPendingReturn();
        $itemId = (int) $return->returnOptions->first()->id;

        $this->admin->transitionOptions($return, [$itemId], ReturnStatusEnum::REJECTED->value, ['reject_reason' => '불가']);

        $this->assertSame(ReturnStatusEnum::REJECTED, $return->fresh()->return_status);
        $this->assertSame(ClaimPaymentStatusEnum::CANCELLED, $payment->fresh()->payment_status);
    }

    // ── #11 일괄 전이의 건별 예외 ──

    /**
     * 일괄 반려 중 한 건의 예기치 못한 예외가 전체를 끊지 않고 skip 으로 보고되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=bulk_isolation
     *
     * @effects bulk_transition_reports_an_unexpected_throwable_as_skipped
     */
    public function test_일괄_전이는_건별_예외를_skip_으로_보고한다(): void
    {
        $first = $this->freeReturn();
        $second = $this->freeReturn();

        HookManager::addAction('sirsoft-ecommerce.claim.return.rejected', function ($claim) use ($first) {
            if ((int) $claim->id === (int) $first->id) {
                throw new RuntimeException('pg down');
            }
        });

        $result = $this->admin->bulkTransition(
            ClaimTypeEnum::RETURN,
            [$first->id, $second->id],
            ReturnStatusEnum::REJECTED->value,
            ['reject_reason' => '불가'],
        );

        $this->assertSame([(int) $second->id], array_map('intval', $result['succeeded']));
        $this->assertSame([(int) $first->id], array_map('intval', array_column($result['skipped'], 'id')));
    }

    // ── #12 금액 수정과 정산의 원자성 ──

    /**
     * 청구 정산이 실패하면 금액 수정도 함께 되돌아가는지 검증합니다.
     *
     * 비회원 신청서에 허용 결제수단이 마일리지뿐이면 결제행을 만들 수 없어 정산이 실패한다.
     * 예전에는 금액만 바뀐 채 남아 화면은 「미수납 3,000원」인데 낼 자리가 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=amount_atomicity
     *
     * @effects amount_update_rolls_back_when_charge_settlement_fails
     */
    public function test_정산이_실패하면_금액_수정도_되돌아간다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', [PaymentMethodEnum::POINT->value]);

        $return = $this->freeReturn();
        // 비회원은 **주문의 주인**으로 정한다 — `requested_by` 가 null 인 것만으로는 운영자 직권 접수와 구별되지
        // 않는다(S3-D15). 회원 주문의 직권 접수 건은 회원 수단(마일리지)으로 정산된다.
        $return->order->forceFill(['user_id' => null])->save();

        $this->assertClaimException('claim_point_payment_requires_member', fn () => $this->admin->updateAmounts($return->fresh(), [
            'claim_shipping_fee' => 3000,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST->value,
        ]));

        $fresh = $return->fresh();
        $this->assertSame(0.0, (float) $fresh->claim_shipping_fee);
        $this->assertSame(0.0, (float) $fresh->total_charge_amount);
        $this->assertNull($fresh->charge_method);
    }

    // ── #13 기납부 청구의 차감 전환 ──

    /**
     * 이미 결제한 청구를 운영자가 환불금 차감으로 바꾸지 못하는지 검증합니다. (이중 징수)
     *
     * @return void 반환값 없음
     *
     * @scenario defect=deduct_after_payment
     *
     * @effects paid_charge_cannot_switch_to_deduct_from_refund_by_admin
     */
    public function test_기납부_청구는_관리자가_차감_방식으로_바꿀_수_없다(): void
    {
        $return = $this->paidReturn();

        $this->assertClaimException('claim_charge_method_deduct_after_payment', fn () => $this->admin->updateAmounts($return, [
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->value,
        ]));

        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_REQUEST, $return->fresh()->charge_method);
    }

    /**
     * 이미 결제한 청구를 구매자가 수정 화면에서 환불금 차감으로 바꾸지 못하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=deduct_after_payment
     *
     * @effects paid_charge_cannot_switch_to_deduct_from_refund_by_buyer
     */
    public function test_기납부_청구는_구매자가_차감_방식으로_바꿀_수_없다(): void
    {
        $return = $this->paidReturn();

        $this->assertClaimException('claim_charge_method_deduct_after_payment', fn () => app(UserClaimService::class)->update(
            ClaimTypeEnum::RETURN,
            $return,
            ['charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND->value],
            [],
            [],
            (int) $return->requested_by,
        ));

        $this->assertSame(ClaimChargeMethodEnum::PAY_ON_REQUEST, $return->fresh()->charge_method);
    }

    /**
     * 신청 시점 결제로 청구액을 마일리지로 낸 반품을 만듭니다.
     *
     * @return OrderReturn 결제완료 반품
     */
    protected function paidReturn(): OrderReturn
    {
        $return = $this->chargedReturn(ClaimChargeMethodEnum::PAY_ON_REQUEST);
        $this->grantMileage((int) $return->requested_by, 10000);

        $payment = $this->payments->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        $this->payments->pay($payment, PaymentMethodEnum::POINT->value, (int) $return->requested_by);

        $fresh = $return->fresh(['returnOptions']);
        $this->assertGreaterThan(0.0, $this->payments->paidAmount($fresh));

        return $fresh;
    }

    // ── #10 알 수 없는 입력 ──

    /**
     * 알 수 없는 청구 방식·환불 우선순위가 도메인 예외(4xx)로 거절되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=unknown_enum_input
     *
     * @effects unknown_charge_method_or_priority_is_a_domain_error
     */
    public function test_알_수_없는_청구_방식과_우선순위는_도메인_예외다(): void
    {
        $order = $this->deliveredClaimOrder();
        $input = [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ];
        $service = app(UserClaimService::class);

        $this->assertClaimException('claim_charge_method_not_allowed', fn () => $service->preview(
            $order,
            ClaimTypeEnum::RETURN,
            $input + ['charge_method' => 'bogus'],
        ));

        $this->assertClaimException('claim_refund_priority_invalid', fn () => $service->preview(
            $order,
            ClaimTypeEnum::RETURN,
            $input + ['refund_priority' => 'bogus'],
        ));
    }

    // ── #14 기준 통화 해석 ──

    /**
     * 스냅샷에 기준 통화가 없으면 교환·반품이 결제 통화가 아니라 설정 기본 통화로 떨어지는지 검증합니다.
     *
     * 예전 교환·반품은 `orders.currency`(결제 통화)로 폴백해, 같은 주문을 관리자 화면(설정 기본 통화)과
     * 다른 통화로 적었다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=base_currency_resolution
     *
     * @effects claim_base_currency_falls_back_to_the_store_default_not_the_payment_currency
     */
    public function test_기준_통화는_스냅샷_다음_설정_기본_통화다(): void
    {
        $snapshot = $this->krwCurrencySnapshot();
        unset($snapshot['base_currency']);

        $returnOrder = $this->deliveredClaimOrder(orderOverrides: ['currency_snapshot' => $snapshot, 'currency' => 'USD']);
        $return = $this->returns->requestReturn($returnOrder, [
            'items' => [['order_option_id' => $returnOrder->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
        ]);

        $exchangeOrder = $this->deliveredClaimOrder(orderOverrides: ['currency_snapshot' => $snapshot, 'currency' => 'USD']);
        $exchange = app(OrderExchangeService::class)->requestExchange($exchangeOrder, [
            'items' => [['order_option_id' => $exchangeOrder->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
        ]);

        $this->assertSame('KRW', $return->currency_code);
        $this->assertSame('KRW', $exchange->currency_code);
    }
}
