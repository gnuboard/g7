<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class AdminEscrowControllerTest extends PluginTestCase
{
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
    }

    public function test_escrow_delivery_register_sanitizes_pg_response_before_storing(): void
    {
        $payment = $this->createEscrowPayment('ORD-ESCROW-DLV-001');

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('useEscrowCredentials')
            ->with(true);
        $mock->expects($this->once())
            ->method('registerEscrowDelivery')
            ->willReturn([
                'resultCode' => '00',
                'resultMsg' => 'OK',
                'tid' => $payment->transaction_id,
                'recvName' => '홍길동',
                'recvTel' => '010-1234-5678',
                'recvAddr' => '서울시 강남구 비공개 주소',
            ]);
        $this->app->instance(KgInicisApiService::class, $mock);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-ESCROW-DLV-001/escrow-delivery', [
                'invoice' => 'INV-001',
                'ex_code' => 'hanjin',
                'recv_name' => '홍길동',
                'recv_tel' => '010-1234-5678',
                'recv_post' => '12345',
                'recv_addr' => '서울시 강남구 비공개 주소',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $payment->refresh();
        $this->assertTrue($payment->payment_meta['pg_response_sanitized'] ?? false);

        $storedPgResponse = $payment->payment_meta['escrow_delivery']['pg_response'] ?? [];

        $this->assertSame('00', $storedPgResponse['resultCode'] ?? null);
        $this->assertSame('OK', $storedPgResponse['resultMsg'] ?? null);
        $this->assertArrayNotHasKey('recvName', $storedPgResponse);
        $this->assertArrayNotHasKey('recvTel', $storedPgResponse);
        $this->assertArrayNotHasKey('recvAddr', $storedPgResponse);
    }

    public function test_escrow_deny_confirm_sanitizes_pg_response_before_storing(): void
    {
        $payment = $this->createEscrowPayment('ORD-ESCROW-DNCF-001', [
            'escrow_confirm' => ['type' => 'deny'],
        ]);

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('useEscrowCredentials')
            ->with(true);
        $mock->expects($this->once())
            ->method('denyConfirmEscrow')
            ->willReturn([
                'resultCode' => '00',
                'resultMsg' => 'OK',
                'originalTid' => $payment->transaction_id,
                'dcnfName' => '관리자 이름',
                'buyerName' => '홍길동',
                'buyerTel' => '010-1234-5678',
            ]);
        $this->app->instance(KgInicisApiService::class, $mock);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-ESCROW-DNCF-001/escrow-deny-confirm', [
                'dcnf_name' => '관리자 이름',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $payment->refresh();
        $this->assertTrue($payment->payment_meta['pg_response_sanitized'] ?? false);

        $storedPgResponse = $payment->payment_meta['escrow_deny_confirm']['pg_response'] ?? [];

        $this->assertSame('00', $storedPgResponse['resultCode'] ?? null);
        $this->assertSame('OK', $storedPgResponse['resultMsg'] ?? null);
        $this->assertArrayNotHasKey('dcnfName', $storedPgResponse);
        $this->assertArrayNotHasKey('buyerName', $storedPgResponse);
        $this->assertArrayNotHasKey('buyerTel', $storedPgResponse);
    }

    /**
     * 운송장번호 누락은 EscrowDeliveryRegisterRequest 가 표준 422 로 차단하고,
     * 메시지는 다국어 키(escrow.invoice_required)의 ko 문구여야 한다
     * (종전 컨트롤러 인라인 한글 하드코딩 → 다국어 키 이관 회귀 방지).
     */
    public function test_escrow_delivery_register_rejects_missing_invoice_with_localized_message(): void
    {

        $this->createEscrowPayment('ORD-ESCROW-VAL-001');

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->never())->method('useEscrowCredentials');
        $mock->expects($this->never())->method('registerEscrowDelivery');
        $this->app->instance(KgInicisApiService::class, $mock);

        $response = $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-ESCROW-VAL-001/escrow-delivery', [
                'ex_code' => 'hanjin',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['invoice'])
            ->assertJsonPath('errors.invoice.0', '운송장번호를 입력해주세요.');
    }

    /**
     * 코드표(COURIER_CODES) 밖의 택배사 코드는 Rule::in 이 422 로 차단해야 한다.
     */
    public function test_escrow_delivery_register_rejects_unknown_courier_code(): void
    {

        $this->createEscrowPayment('ORD-ESCROW-VAL-002');

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->never())->method('registerEscrowDelivery');
        $this->app->instance(KgInicisApiService::class, $mock);

        $response = $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-ESCROW-VAL-002/escrow-delivery', [
                'invoice' => 'INV-001',
                'ex_code' => 'nope',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ex_code'])
            ->assertJsonPath('errors.ex_code.0', '택배사를 선택해주세요.');
    }

    /**
     * 수령인 필드 상한 초과(이름 31자 / 전화 21자 / 우편 11자 / 주소 201자)는
     * KG 이니시스 연동 스펙 상한을 넘으므로 422 로 차단하고 PG 호출은 없어야 한다.
     */
    public function test_escrow_delivery_register_rejects_overlong_recv_fields(): void
    {
        $this->createEscrowPayment('ORD-ESCROW-VAL-003');

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->never())->method('registerEscrowDelivery');
        $this->app->instance(KgInicisApiService::class, $mock);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-ESCROW-VAL-003/escrow-delivery', [
                'invoice' => 'INV-001',
                'ex_code' => 'hanjin',
                'recv_name' => str_repeat('가', 31),
                'recv_tel' => str_repeat('0', 21),
                'recv_post' => str_repeat('1', 11),
                'recv_addr' => str_repeat('가', 201),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'recv_name',
                'recv_tel',
                'recv_post',
                'recv_addr',
            ]);
    }

    /**
     * 배열 주입(`dcnf_name[]=x`)은 EscrowDenyConfirmRequest 의 string 규칙이 422 로
     * 차단하고 PG 구매거절확인 API 는 호출되지 않아야 한다.
     */
    public function test_escrow_deny_confirm_rejects_array_dcnf_name(): void
    {
        $this->createEscrowPayment('ORD-ESCROW-DNCF-VAL-001', [
            'escrow_confirm' => ['type' => 'deny'],
        ]);

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->never())->method('useEscrowCredentials');
        $mock->expects($this->never())->method('denyConfirmEscrow');
        $this->app->instance(KgInicisApiService::class, $mock);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-ESCROW-DNCF-VAL-001/escrow-deny-confirm', [
                'dcnf_name' => ['x'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dcnf_name']);
    }

    /**
     * 확인자명 상한(20자) 초과는 422 로 차단해야 한다 (이니시스 확인자명 20자 스펙).
     */
    public function test_escrow_deny_confirm_rejects_overlong_dcnf_name(): void
    {
        $this->createEscrowPayment('ORD-ESCROW-DNCF-VAL-002', [
            'escrow_confirm' => ['type' => 'deny'],
        ]);

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->never())->method('denyConfirmEscrow');
        $this->app->instance(KgInicisApiService::class, $mock);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-ESCROW-DNCF-VAL-002/escrow-deny-confirm', [
                'dcnf_name' => str_repeat('가', 21),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dcnf_name']);
    }

    private function createEscrowPayment(string $orderNumber, array $paymentMeta = []): OrderPayment
    {
        $order = OrderFactory::new()->paid()->create([
            'order_number' => $orderNumber,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_due_amount' => 0,
        ]);

        OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
            'address_detail' => '비공개 주소',
        ]);

        return OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'INIMX_CARDINIpayTest20260623123456',
            'paid_amount_local' => 30000,
            'paid_amount_base' => 30000,
            'is_escrow' => true,
            'payment_meta' => $paymentMeta,
        ]);
    }
}
