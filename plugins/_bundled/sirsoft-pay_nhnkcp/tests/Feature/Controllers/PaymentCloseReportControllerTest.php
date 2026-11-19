<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ChargeAmount;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentOutcome;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\WindowClosure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ChargeNotResolvable;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 결제창 닫힘 보고 컨트롤러 테스트
 *
 * 컨트롤러는 이커머스 공개 결제 계약(`PaymentGatewayBridge`)만 부른다. 계약을 mock 해 주문 DTO 를 돌려주고,
 * 실패 기록은 `markPaymentWindowClosed` 호출과 그 `WindowClosure` 필드로 단언한다.
 */
class PaymentCloseReportControllerTest extends PluginTestCase
{
    /**
     * 주문 소유자의 닫힘 보고는 결제대기 주문을 실패·취소로 기록한다.
     *
     * @scenario context=paymentCallback_card, caller=ownership_verified_buyer, callback_state=approval_not_yet_attempted
     *
     * @effects close_report_marks_pending_order_failed_and_payment_cancelled,
     *   legitimate_failure_is_recorded_by_ownership_verified_close_report
     *
     * @return void 반환값 없음
     */
    public function test_close_report_marks_pending_order_failed_and_payment_cancelled(): void
    {
        $owner = $this->actAsOrderOwner();
        $order = $this->makeOrder('ORD-KCP-CLOSE-001', 10000, ['userId' => $owner->id]);

        $bridge = $this->bridgeReturning($order);
        $bridge->shouldReceive('resolveChargeAmount')
            ->once()
            ->with('ORD-KCP-CLOSE-001')
            ->andReturn(new ChargeAmount(10000, 'KRW'));
        $bridge->shouldReceive('markPaymentWindowClosed')
            ->once()
            ->with('ORD-KCP-CLOSE-001', 'nhnkcp', Mockery::on(
                fn (WindowClosure $closure): bool => $closure->code === 'USER_CANCEL'
                    && $closure->message === '사용자가 NHN KCP 결제창을 닫았습니다.'
                    && $closure->cancelMessage === 'kcp-window-closed'
                    && $closure->failure?->status === PaymentStatusEnum::CANCELLED
                    && $closure->failure->takeOverProvider
            ))
            ->andReturn(new PaymentOutcome(true, null, $order));

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-001',
            'price' => 10000,
            'payment_method' => 'card',
            'reason' => 'kcp-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'recorded');
    }

    /**
     * 보고 금액이 주문 청구액과 다르면 거부한다.
     *
     * @effects close_report_rejects_amount_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_amount_mismatch(): void
    {
        $owner = $this->actAsOrderOwner();
        $order = $this->makeOrder('ORD-KCP-CLOSE-002', 10000, ['userId' => $owner->id]);

        $bridge = $this->bridgeReturning($order);
        $bridge->shouldReceive('resolveChargeAmount')
            ->once()
            ->with('ORD-KCP-CLOSE-002')
            ->andReturn(new ChargeAmount(10000, 'KRW'));
        $bridge->shouldNotReceive('markPaymentWindowClosed');

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-002',
            'price' => 9000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_nhnkcp::messages.responses.amount_mismatch'));
    }

    /**
     * 청구할 수 없는 통화 설정이면 서버 오류 없이 422 로 거부한다.
     *
     * @effects close_report_rejects_invalid_payment_currency_without_server_error
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_invalid_payment_currency_without_server_error(): void
    {
        $owner = $this->actAsOrderOwner();
        $order = $this->makeOrder('ORD-KCP-CLOSE-CURRENCY-001', 10000, [
            'userId' => $owner->id,
            'currency' => 'USD',
            'currencySnapshot' => self::invalidUsdCurrencySnapshot(),
        ]);

        $bridge = $this->bridgeReturning($order);
        // 계약은 청구할 수 없는 결제 통화를 공개 예외(InvalidArgumentException 하위)로 알린다
        $bridge->shouldReceive('resolveChargeAmount')
            ->once()
            ->with('ORD-KCP-CLOSE-CURRENCY-001')
            ->andThrow(ChargeNotResolvable::from(new \InvalidArgumentException('USD exchange rate is zero.')));
        $bridge->shouldNotReceive('markPaymentWindowClosed');

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-CURRENCY-001',
            'price' => 10000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_nhnkcp::messages.responses.currency_not_chargeable'));
    }

    /**
     * 주문자 연락처를 정확히 맞춰 보내도 소유자 인증이 없으면 거부된다 (KVE-2026-2434).
     *
     * 연락처는 제3자도 알 수 있어 소유 증명이 아니다. 종전에는 이 요청이 주문을 취소시켰다.
     *
     * @scenario context=paymentCallback_card, caller=ownership_verified_buyer, callback_state=approval_not_yet_attempted
     *
     * @effects close_report_rejects_matching_buyer_contact_without_ownership,
     *   close_report_rejects_buyer_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_matching_buyer_contact_without_ownership(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-003', 10000, [
            'ordererEmail' => 'buyer@example.com',
            'ordererPhone' => '010-1234-5678',
        ]);

        $bridge = $this->bridgeReturning($order);
        // 비회원 주문 — 조회 토큰이 없으므로 계약의 토큰 검증이 거짓이다
        $bridge->shouldReceive('verifyGuestOrderToken')
            ->once()
            ->with(null, 'ORD-KCP-CLOSE-003')
            ->andReturn(false);
        $bridge->shouldNotReceive('markPaymentWindowClosed');

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-003',
            'price' => 10000,
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_nhnkcp::messages.responses.ownership_verification_failed'));
    }

    /**
     * 이미 결제할 수 없는 주문의 닫힘 보고는 무시한다.
     *
     * @effects close_report_ignores_order_that_is_no_longer_payable
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_order_that_is_no_longer_payable(): void
    {
        $order = $this->makeOrder('ORD-KCP-CLOSE-004', 10000, [
            'orderStatus' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $bridge = $this->bridgeReturning($order);
        $bridge->shouldNotReceive('markPaymentWindowClosed');

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-004',
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'order_not_payable');
    }

    /**
     * 결제가 이미 성공한 주문의 닫힘 보고는 무시한다 (승인 콜백과의 경쟁).
     *
     * @effects close_report_ignores_order_when_payment_already_paid
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_order_when_payment_already_paid(): void
    {
        // race 재현: 승인 콜백이 payment 를 PAID 로 갱신했고 결제 예정액은 이미 0원이 됐으나,
        // order_status 관계가 아직 PENDING_ORDER 로 관측되는 순간에도 close-report 는 금액 불일치가
        // 아니라 결제 성공으로 판단해 무시해야 한다.
        $order = $this->makeOrder('ORD-KCP-CLOSE-PAID-001', 0, [
            'payment' => $this->makePaymentRecord(['paymentStatus' => PaymentStatusEnum::PAID]),
        ]);

        $bridge = $this->bridgeReturning($order);
        $bridge->shouldNotReceive('markPaymentWindowClosed');

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-PAID-001',
            'price' => 10000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'payment_already_paid');
    }

    /**
     * 가상계좌가 이미 발급된(입금대기) 주문의 닫힘 보고는 무시한다 — 발급 콜백과 경쟁해 늦게 온 보고가
     * 발급된 주문을 실패·취소로 덮으면 고객은 받은 계좌로 입금할 수 없게 된다.
     *
     * @effects close_report_ignores_issued_vbank_waiting_deposit
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_issued_vbank_waiting_deposit(): void
    {
        $owner = $this->actAsOrderOwner();
        $order = $this->makeOrder('ORD-KCP-CLOSE-VBANK-001', 10000, [
            'userId' => $owner->id,
            'orderStatus' => OrderStatusEnum::PENDING_PAYMENT,
            'payment' => $this->makePaymentRecord([
                'paymentStatus' => PaymentStatusEnum::WAITING_DEPOSIT,
                'paymentMethod' => 'vbank',
            ]),
        ]);

        $bridge = $this->bridgeReturning($order);
        $bridge->shouldNotReceive('markPaymentWindowClosed');

        $response = $this->postJson('/api/plugins/sirsoft-pay_nhnkcp/payment/close-report', [
            'oid' => 'ORD-KCP-CLOSE-VBANK-001',
            'price' => 10000,
            'payment_method' => 'vbank',
            'reason' => 'kcp-window-closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'payment_not_ready');
    }

    /**
     * 주문번호 조회가 주어진 주문 DTO 를 돌려주는 결제 계약 mock 을 컨테이너에 끼웁니다.
     *
     * @param  OrderRecord  $order  돌려줄 주문
     * @return MockInterface 결제 계약 mock
     */
    private function bridgeReturning(OrderRecord $order): MockInterface
    {
        $bridge = Mockery::mock(PaymentGatewayBridge::class);
        $bridge->shouldReceive('isClaimPaymentNumber')->andReturn(false);
        $bridge->shouldReceive('findOrderByNumber')
            ->once()
            ->with($order->orderNumber, 'nhnkcp')
            ->andReturn($order);

        $this->app->instance(PaymentGatewayBridge::class, $bridge);

        return $bridge;
    }

    /**
     * 주문 소유 회원을 만들고 그 회원으로 로그인합니다.
     *
     * 소유자 확인이 이 테스트의 관심사가 아닐 때 게이트를 정당하게 통과시키기 위한 준비다.
     * 소유자 확인 자체는 PaymentCloseReportOwnershipTest 가 검증한다.
     *
     * @return User 로그인한 소유 회원
     */
    private function actAsOrderOwner(): User
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        return $owner;
    }

    /**
     * 결제대기 주문 DTO 를 만듭니다. (결제 행은 이 PG 의 카드 결제대기)
     *
     * @param  string  $orderNumber  주문번호
     * @param  int  $amount  금액
     * @param  array<string, mixed>  $overrides  바꿀 필드
     * @return OrderRecord 주문 DTO
     */
    private function makeOrder(string $orderNumber, int $amount, array $overrides = []): OrderRecord
    {
        return $this->makeOrderRecord(array_merge([
            'orderNumber' => $orderNumber,
            'orderStatus' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currencySnapshot' => self::krwCurrencySnapshot(),
            'totalDueAmount' => (float) $amount,
            'payment' => $this->makePaymentRecord(['orderNumber' => $orderNumber]),
        ], $overrides));
    }

    /**
     * 원화 단일 통화 스냅샷을 돌려줍니다.
     *
     * @return array 구성한 배열
     */
    private static function krwCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'base_unit' => 1,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1,
                ],
            ],
        ];
    }

    /**
     * 청구할 수 없는(환율 0) 달러 통화 스냅샷을 돌려줍니다.
     *
     * @return array 구성한 배열
     */
    private static function invalidUsdCurrencySnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'USD',
            'base_unit' => 1000,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                    'decimal_places' => 0,
                    'base_unit' => 1000,
                ],
                'USD' => [
                    'rate' => 0,
                    'rounding_unit' => '0.01',
                    'rounding_method' => 'round',
                    'decimal_places' => 2,
                    'base_unit' => 1,
                ],
            ],
        ];
    }
}
