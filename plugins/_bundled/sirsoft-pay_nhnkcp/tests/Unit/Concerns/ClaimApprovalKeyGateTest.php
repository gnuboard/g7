<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Plugins\Sirsoft\PayNhnkcp\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 승인키 발급 게이트의 fail-closed 형태 테스트 (K9)
 *
 * 열람자 대조를 `(int) ($payment->order?->user_id ?? 0) !== (int) ($request->user()?->id ?? 0)`
 * 로 쓰면 **양쪽이 모두 0 인 순간 통과**한다 — 주문을 못 읽었고(soft-delete) 열람자도 없는
 * 조합이다. 지금은 라우트의 `auth:sanctum` 이 오른쪽을 0 이 되지 못하게 막아 실질은 닫혀
 * 있지만, 그 방어는 이 판정 **밖의** 조건이다. 미들웨어 구성이 바뀌거나 이 메서드를 다른
 * 진입점이 부르면 그 순간 열린다.
 *
 * HTTP 경로로는 그 조합을 만들 수 없으므로(인증 미들웨어가 먼저 401 을 낸다) 게이트를
 * 직접 부른다 — 재는 것은 라우트가 아니라 **판정식의 형태**다.
 */
class ClaimApprovalKeyGateTest extends PluginTestCase
{
    /**
     * 트레이트만 노출하는 최소 소비자를 만듭니다.
     *
     * @return object 게이트 메서드를 공개한 소비자
     */
    private function gate(): object
    {
        // 트레이트는 호스트가 Repository 를 생성자로 주입받는다고 전제한다(서비스 로케이터 금지).
        return new class(app(ClaimPaymentRepositoryInterface::class))
        {
            use HandlesClaimPaymentCallbacks {
                validateClaimApprovalKeyContext as public;
            }

            /**
             * 트레이트가 쓰는 클레임 결제 Repository 를 주입받습니다.
             *
             * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 Repository
             */
            public function __construct(
                private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
            ) {}
        };
    }

    /**
     * 주문이 soft-delete 된 결제 대기 건을 만듭니다.
     *
     * @return ClaimPayment 결제 대기 건
     */
    private function paymentWithoutOrder(): ClaimPayment
    {
        $order = OrderFactory::new()->create([
            'user_id' => null,
            'order_number' => 'ORD-K9GATE-1',
            'currency' => 'KRW',
        ]);

        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-K9GATE-1',
        ]);

        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-K9GATE-1',
            'pg_provider' => 'nhnkcp',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        $order->delete();

        return $payment->fresh();
    }

    /**
     * 주문을 못 읽고 열람자도 없으면 통과가 아니라 403 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approval_key
     *
     * @effects kcp_claim_mobile_approval_key_is_gated_by_the_claim_payment
     */
    public function test_gate_blocks_when_both_the_parent_order_and_the_viewer_are_missing(): void
    {
        $response = $this->gate()->validateClaimApprovalKeyContext(
            Request::create('/', 'POST'),
            'CP-K9GATE-1',
            3000,
            'KRW',
        );

        $this->assertInstanceOf(
            JsonResponse::class,
            $response,
            '부모를 못 읽고 열람자도 없는 조합이 통과하면 그 방어는 이 판정 밖의 조건에 얹혀 있는 것이다'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * 결제 건 자체가 없으면 404 다 (대조군 — 게이트가 전부-403 으로 공허해지지 않음을 고정).
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approval_key
     *
     * @effects kcp_claim_mobile_approval_key_is_gated_by_the_claim_payment
     */
    public function test_gate_reports_a_missing_payment_as_not_found(): void
    {
        $response = $this->gate()->validateClaimApprovalKeyContext(
            Request::create('/', 'POST'),
            'CP-DOES-NOT-EXIST',
            3000,
            'KRW',
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * 주문이 살아 있는 비회원 결제 건도 열람자가 없으면 막힌다.
     *
     * 비회원 주문(`user_id` NULL)에서 익명 요청을 허용하면 결제번호만 알면 승인키를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approval_key
     *
     * @effects kcp_claim_mobile_approval_key_is_gated_by_the_claim_payment
     */
    public function test_gate_blocks_an_anonymous_request_for_a_guest_order(): void
    {
        $order = OrderFactory::new()->create([
            'user_id' => null,
            'order_number' => 'ORD-K9GATE-2',
            'currency' => 'KRW',
        ]);

        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-K9GATE-2',
        ]);

        ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-K9GATE-2',
            'pg_provider' => 'nhnkcp',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        $response = $this->gate()->validateClaimApprovalKeyContext(
            Request::create('/', 'POST'),
            'CP-K9GATE-2',
            3000,
            'KRW',
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * 주문이 사라진 결제 대기 건 픽스처를 먼저 세웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 픽스처가 게이트보다 먼저 서야 한다 — 게이트는 결제번호로만 대상을 찾는다.
        $this->paymentWithoutOrder();
    }
}
