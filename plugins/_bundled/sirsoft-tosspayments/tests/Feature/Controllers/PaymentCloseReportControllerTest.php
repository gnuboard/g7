<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Controllers;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 토스페이먼츠 결제창 닫힘·결제 실패 보고 테스트
 *
 * 브라우저 리턴 콜백(`/payment/fail`)이 주문 상태를 바꾸지 않게 되면서, 정당한 결제 실패를
 * 기록하는 책임은 전적으로 이 엔드포인트에 있다. 그 자격은 주문 소유자 확인(회원 본인 로그인 /
 * 비회원 조회 토큰)이 정한다 — 주문자 연락처는 제3자도 알 수 있어 소유 증명이 아니다 (KVE-2026-2434).
 * 소유자 확인의 전 경우는 PaymentCloseReportOwnershipTest 가 검증한다.
 */
class PaymentCloseReportControllerTest extends PluginTestCase
{
    private const URL = '/api/plugins/sirsoft-tosspayments/payment/close-report';

    private const BUYER_EMAIL = 'toss-buyer@example.com';

    private const BUYER_PHONE = '01012345678';

    /**
     * 원화 통화 스냅샷 (자릿수 명시).
     *
     * 팩토리 기본값은 상점 통화 설정에서 읽으므로 선행 스위트가 남긴 설정 상태에 좌우된다.
     * 특히 `decimal_places` 가 비면 KRW(0자리)가 2자리로 해석돼 청구액이 100배가 되고,
     * 이 파일의 금액 대조가 실제와 다른 것을 측정하게 된다.
     *
     * @return array<string, mixed> 통화 스냅샷
     */
    private static function krwSnapshot(): array
    {
        return [
            'base_currency' => 'KRW',
            'order_currency' => 'KRW',
            'exchange_rate' => 1.0,
            'exchange_rates' => [
                'KRW' => [
                    'rate' => 1.0,
                    'decimal_places' => 0,
                    'rounding_unit' => '1',
                    'rounding_method' => 'round',
                ],
            ],
            'snapshot_at' => '2026-01-01T00:00:00+00:00',
        ];
    }

    /**
     * 주문을 회원 소유로 만들고 그 회원으로 로그인합니다.
     *
     * @param  Order  $order  대상 주문
     * @return User 로그인한 소유 회원
     */
    private function actAsOrderOwner(Order $order): User
    {
        $owner = User::factory()->create();
        $order->update(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        return $owner;
    }

    /**
     * 결제 대기 주문과 배송지(구매자 정보)를 생성합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  int  $amount  주문 금액
     * @param  bool  $withAddress  구매자 정보를 담은 배송지 생성 여부
     * @return Order 생성된 주문
     */
    private function makeOrder(string $orderNumber, int $amount = 10000, bool $withAddress = true): Order
    {
        $order = OrderFactory::new()->create([
            'order_number' => $orderNumber,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwSnapshot(),
            'subtotal_amount' => $amount,
            'total_amount' => $amount,
            'total_due_amount' => $amount,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'tosspayments',
            'paid_amount_local' => 0,
        ]);

        if ($withAddress) {
            OrderAddress::create([
                'order_id' => $order->id,
                'address_type' => 'shipping',
                'orderer_name' => '홍길동',
                'orderer_phone' => self::BUYER_PHONE,
                'orderer_email' => self::BUYER_EMAIL,
                'recipient_name' => '홍길동',
                'recipient_phone' => self::BUYER_PHONE,
                'zipcode' => '06234',
                'address' => '서울시 강남구',
                'address_detail' => '101호',
            ]);
        }

        return $order->fresh('payment');
    }

    /**
     * 주문 소유자가 보고하면 결제창 닫힘이 주문 취소로 기록된다.
     *
     * @effects close_report_marks_pending_order_cancelled
     *
     * @return void 반환값 없음
     */
    public function test_close_report_marks_pending_order_cancelled(): void
    {
        $order = $this->makeOrder('ORD-TOSS-CLOSE-001');
        $this->actAsOrderOwner($order);

        $response = $this->postJson(self::URL, [
            'orderId' => 'ORD-TOSS-CLOSE-001',
            'amount' => 10000,
            'reason' => 'user closed the payment window',
        ]);

        $response->assertOk();
        $this->assertSame('recorded', $response->json('data.status'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertSame('USER_CANCEL', $order->order_meta['payment_failure_code'] ?? null);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertSame('tosspayments', $payment->payment_meta['failure_source'] ?? null);
        $this->assertSame('window_closed', $payment->payment_meta['failure_stage'] ?? null);
    }

    /**
     * 결제 거절(실패 코드 동반)은 원인을 구분할 수 있게 기록된다.
     *
     * @effects close_report_records_declined_payment_with_its_code
     *
     * @return void 반환값 없음
     */
    public function test_close_report_records_declined_payment_with_its_code(): void
    {
        $order = $this->makeOrder('ORD-TOSS-CLOSE-002');
        $this->actAsOrderOwner($order);

        $response = $this->postJson(self::URL, [
            'orderId' => 'ORD-TOSS-CLOSE-002',
            'amount' => 10000,
            'code' => 'REJECT_CARD_COMPANY',
            'reason' => '카드사에서 승인을 거절했습니다.',
        ]);

        $response->assertOk();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertSame('REJECT_CARD_COMPANY', $order->order_meta['payment_failure_code'] ?? null);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertSame('payment_failed', $payment->payment_meta['failure_stage'] ?? null);
    }

    /**
     * 주문자 연락처를 정확히 맞춰 보내도 소유자 인증이 없으면 주문을 건드리지 않는다 (KVE-2026-2434).
     *
     * 종전에는 이 대조가 유일한 자격 근거였고, 연락처를 아는 제3자가 주문을 취소시킬 수 있었다.
     *
     * @effects close_report_rejects_matching_buyer_contact_without_ownership
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_matching_buyer_contact_without_ownership(): void
    {
        $order = $this->makeOrder('ORD-TOSS-CLOSE-003');

        $response = $this->postJson(self::URL, [
            'orderId' => 'ORD-TOSS-CLOSE-003',
            'amount' => 10000,
            'buyer_email' => self::BUYER_EMAIL,
            'buyer_phone' => self::BUYER_PHONE,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.message.0', 'Order ownership verification failed.');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertArrayNotHasKey('payment_failure_code', $order->order_meta ?? []);
    }

    /**
     * 소유자 증명(로그인·조회 토큰)이 없으면 통과시키지 않는다.
     *
     * @effects close_report_rejects_request_without_ownership_proof
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_request_without_ownership_proof(): void
    {
        $order = $this->makeOrder('ORD-TOSS-CLOSE-004');

        $response = $this->postJson(self::URL, [
            'orderId' => 'ORD-TOSS-CLOSE-004',
            'amount' => 10000,
        ]);

        $response->assertStatus(403);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 금액이 주문 청구액과 다르면 거부한다.
     *
     * @effects close_report_rejects_amount_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_close_report_rejects_amount_mismatch(): void
    {
        $order = $this->makeOrder('ORD-TOSS-CLOSE-005');
        $this->actAsOrderOwner($order);

        $response = $this->postJson(self::URL, [
            'orderId' => 'ORD-TOSS-CLOSE-005',
            'amount' => 999,
        ]);

        $response->assertStatus(422);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }

    /**
     * 존재하지 않는 주문번호는 404 로 응답한다.
     *
     * @effects close_report_returns_not_found_for_unknown_order
     *
     * @return void 반환값 없음
     */
    public function test_close_report_returns_not_found_for_unknown_order(): void
    {
        $response = $this->postJson(self::URL, [
            'orderId' => 'ORD-TOSS-DOES-NOT-EXIST',
            'amount' => 10000,
        ]);

        $response->assertStatus(404);
    }

    /**
     * 이미 결제가 성공한 주문은 무시한다 — 성공 콜백과의 경쟁에서 주문을 덮지 않는다.
     *
     * @effects close_report_ignores_order_whose_payment_already_paid
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_order_whose_payment_already_paid(): void
    {
        $order = $this->makeOrder('ORD-TOSS-CLOSE-006');
        $order->payment->update([
            'payment_status' => PaymentStatusEnum::PAID->value,
            'paid_at' => now(),
        ]);

        $response = $this->postJson(self::URL, [
            'orderId' => 'ORD-TOSS-CLOSE-006',
            'amount' => 10000,
        ]);

        $response->assertOk();
        $this->assertSame('ignored', $response->json('data.status'));
        $this->assertSame('payment_already_paid', $response->json('data.reason'));

        $order->refresh();
        $this->assertNotEquals(OrderStatusEnum::CANCELLED, $order->order_status);
    }

    /**
     * 이미 결제 가능 상태가 아닌 주문은 무시한다.
     *
     * @effects close_report_ignores_order_that_is_no_longer_payable
     *
     * @return void 반환값 없음
     */
    public function test_close_report_ignores_order_that_is_no_longer_payable(): void
    {
        $order = $this->makeOrder('ORD-TOSS-CLOSE-007');
        $order->update(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);

        $response = $this->postJson(self::URL, [
            'orderId' => 'ORD-TOSS-CLOSE-007',
            'amount' => 10000,
        ]);

        $response->assertOk();
        $this->assertSame('ignored', $response->json('data.status'));
        $this->assertSame('order_not_payable', $response->json('data.reason'));
    }
}
