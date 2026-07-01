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
