<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 추가결제 복귀 주소 단위 테스트 (S5b)
 *
 * 결제 플러그인 4종은 이 클래스로만 복귀 주소를 만든다 — 각자 조립하면 화면 경로가 바뀔 때
 * 네 곳이 함께 낡는다. 쿼리 계약(`claim_payment=success|failed|cancelled`)은 S12/S13 의
 * 신청서 상세가 소비한다.
 *
 * 회원/비회원 분기는 주문의 `user_id` 로 정한다. 주문을 읽지 못하는 상태(soft-delete 등)에서
 * 회원 건을 비회원 경로로 보내면, 로그인으로 보호되던 화면이 비회원 조회 경로로 넘어간다 —
 * 부모를 못 읽을 때는 넓은 쪽이 아니라 좁은 쪽(회원 경로)으로 닫는다.
 */
class ClaimPaymentRedirectTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected ClaimPaymentService $service;

    protected OrderReturnService $returnService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->service = app(ClaimPaymentService::class);
        $this->returnService = app(OrderReturnService::class);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 회수 배송비가 청구되는 반품의 결제 대기 건을 만듭니다.
     *
     * @param  array  $orderOverrides  주문 오버라이드 (비회원 주문 등)
     * @return ClaimPayment 결제 대기 건
     */
    protected function pendingClaimPayment(array $orderOverrides = [])
    {
        $order = $this->deliveredClaimOrder(orderOverrides: $orderOverrides);

        $return = $this->returnService->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);

        return $this->service->initiateForRequest($return, PaymentMethodEnum::CARD->value);
    }

    public function test_member_claim_payment_returns_to_the_mypage_claim_detail(): void
    {
        $payment = $this->pendingClaimPayment();
        $number = $payment->payable->return_number;

        $this->assertSame("/mypage/claims/{$number}?claim_payment=success", ClaimPaymentRedirect::success($payment));
        $this->assertSame("/mypage/claims/{$number}?claim_payment=cancelled", ClaimPaymentRedirect::cancelled($payment));
        $this->assertSame(
            "/mypage/claims/{$number}?claim_payment=failed&error=amount_mismatch",
            ClaimPaymentRedirect::fail($payment, 'amount_mismatch')
        );
    }

    public function test_guest_claim_payment_returns_to_the_guest_claim_detail(): void
    {
        $payment = $this->pendingClaimPayment(['user_id' => null]);
        $number = $payment->payable->return_number;

        $this->assertSame("/shop/guest/claims/{$number}?claim_payment=success", ClaimPaymentRedirect::success($payment));
    }

    public function test_an_unreadable_order_does_not_downgrade_a_member_claim_to_the_guest_path(): void
    {
        // 주문이 soft-delete 되면 `$payment->order` 는 null 이다. 그때 "user_id 가 null" 로 읽어
        // 비회원 경로를 내보내면, 로그인으로 보호되던 화면이 비회원 조회 경로로 넘어간다.
        $payment = $this->pendingClaimPayment();
        $number = $payment->payable->return_number;
        $payment->order->delete();

        $this->assertSame(
            "/mypage/claims/{$number}?claim_payment=success",
            ClaimPaymentRedirect::success($payment->fresh())
        );
    }
}
