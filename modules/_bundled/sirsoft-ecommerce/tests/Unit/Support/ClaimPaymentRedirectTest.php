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
 * 네 곳이 함께 낡는다. 쿼리 계약은 `claim_payment=success|failed|cancelled` + `claim_number` 다.
 *
 * **목적지는 "지금 서 있는 화면" 이어야 한다** (2026-08-31 S12 실측으로 계약 변경). 원래는
 * 신청서 상세로 돌아갔는데 그 화면은 뒤 세션이 만들 것이라 라우트가 없었고, 결제를 마치고
 * 돌아온 구매자에게 **성공·실패·취소 전부 404** 가 열렸다 — 돈이 나갔는지조차 알리지 못했고
 * 실패 사유도 남지 않았다. 그래서 결과를 그릴 수 있는 화면으로 보낸다: 회원은 주문 상세
 * (클레임 추가결제 합계 행이 이미 있다), 비회원은 주문 조회 화면(상세는 조회 토큰을 전제하는데
 * 결제창에서 돌아온 브라우저에 그 토큰이 있다고 보장할 수 없다). 신청서 상세가 서면 그리로
 * 되돌린다 — 주소 규칙은 `ClaimUrl` 에 그대로 있다.
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

    /**
     * 회원은 주문 상세로 돌아가고, 세 결과가 쿼리로 구분된다.
     *
     * 신청서 번호를 쿼리로 함께 싣는다 — 주소가 더 이상 번호를 품지 않으므로, 이것이 없으면
     * 한 주문에 신청서가 여럿일 때 어느 건의 결제였는지 화면이 알 수 없다.
     *
     * @scenario claim_type=return, charge_method=pay_on_request, refund_branch=none
     *
     * @effects claim_payment_returns_to_a_screen_that_exists
     */
    public function test_member_claim_payment_returns_to_a_screen_that_exists(): void
    {
        $payment = $this->pendingClaimPayment();
        $number = $payment->payable->return_number;
        $orderNumber = $payment->order->order_number;

        $this->assertSame(
            "/mypage/orders/{$orderNumber}?claim_payment=success&claim_number={$number}",
            ClaimPaymentRedirect::success($payment)
        );
        $this->assertSame(
            "/mypage/orders/{$orderNumber}?claim_payment=cancelled&claim_number={$number}",
            ClaimPaymentRedirect::cancelled($payment)
        );
        $this->assertSame(
            "/mypage/orders/{$orderNumber}?claim_payment=failed&error=amount_mismatch&claim_number={$number}",
            ClaimPaymentRedirect::fail($payment, 'amount_mismatch')
        );
    }

    /**
     * 아직 서지 않은 화면으로 보내지 않는다. (이 결함의 회귀 가드)
     *
     * 신청서 상세 주소로 되돌리면 그 순간 다시 404 가 된다 — 예외도 로그도 남지 않고, 결제는
     * 정상 성공하므로 어긋난 사실이 어디에도 드러나지 않는다.
     */
    public function test_claim_payment_never_returns_to_the_not_yet_built_claim_detail(): void
    {
        $payment = $this->pendingClaimPayment();

        foreach ([
            ClaimPaymentRedirect::success($payment),
            ClaimPaymentRedirect::cancelled($payment),
            ClaimPaymentRedirect::fail($payment, 'amount_mismatch'),
        ] as $url) {
            $this->assertStringNotContainsString('/mypage/claims/', $url);
            $this->assertStringNotContainsString('/guest/claims/', $url);
        }
    }

    /**
     * 비회원은 주문 조회 화면으로 돌아간다.
     *
     * 비회원 신청서 상세는 조회 토큰을 전제하는데, PG 결제창을 거쳐 돌아온 브라우저에 그 토큰이
     * 남아 있다고 보장할 수 없다 — 알림 링크가 같은 이유로 이미 조회 화면을 가리킨다.
     */
    public function test_guest_claim_payment_returns_to_the_guest_order_lookup(): void
    {
        $payment = $this->pendingClaimPayment(['user_id' => null]);
        $number = $payment->payable->return_number;

        $this->assertSame(
            "/shop/guest/orders?claim_payment=success&claim_number={$number}",
            ClaimPaymentRedirect::success($payment)
        );
    }

    public function test_an_unreadable_order_does_not_downgrade_a_member_claim_to_the_guest_path(): void
    {
        // 주문이 soft-delete 되면 `$payment->order` 는 null 이다. 그때 "user_id 가 null" 로 읽어
        // 비회원 경로를 내보내면, 로그인으로 보호되던 화면이 비회원 조회 경로로 넘어간다.
        $payment = $this->pendingClaimPayment();
        $number = $payment->payable->return_number;
        $payment->order->delete();

        // 주문을 못 읽으면 주문번호도 없다 — 그때는 회원 목록으로 닫는다. 지키는 것은 그대로다:
        // 비회원 경로로 넘어가지 않는다.
        $url = ClaimPaymentRedirect::success($payment->fresh());

        $this->assertSame("/mypage/claims?claim_payment=success&claim_number={$number}", $url);
        $this->assertStringNotContainsString('/guest/', $url);
    }
}
