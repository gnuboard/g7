<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class AdminCashReceiptControllerTest extends PluginTestCase
{
    public function test_cash_receipt_issue_returns_sanitized_pg_response(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = OrderFactory::new()->paid()->create([
            'order_number' => 'ORD-CASH-RECEIPT-001',
            'total_amount' => 30000,
            'total_due_amount' => 0,
            'total_paid_amount' => 30000,
        ]);

        $payment = OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'INICIS_CASH_TID_001',
            'paid_amount_local' => 30000,
            'vat_amount' => 2727,
            'payment_name' => '테스트 상품',
            'buyer_name' => '홍길동',
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '010-1234-5678',
            'is_cash_receipt_issued' => false,
        ]);

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())
            ->method('issueCashReceipt')
            ->willReturn([
                'resultCode' => '00',
                'resultMsg' => 'OK',
                'tid' => 'INICIS_CASH_TID_001',
                'cashReceiptNo' => 'CR-001',
                'buyerName' => '홍길동',
                'buyerTel' => '010-1234-5678',
                'buyerEmail' => 'buyer@example.com',
            ]);
        $this->app->instance(KgInicisApiService::class, $mock);

        $response = $this->actingAs($admin)
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-CASH-RECEIPT-001/cash-receipt', [
                'issue_type' => '0',
                'issue_number' => '01012345678',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.result_code', '00')
            ->assertJsonPath('data.pg_response.resultCode', '00')
            ->assertJsonMissingPath('data.pg_response.buyerName')
            ->assertJsonMissingPath('data.pg_response.buyerTel')
            ->assertJsonMissingPath('data.pg_response.buyerEmail');

        $payment->refresh();
        $this->assertSame('*******5678', $payment->cash_receipt_identifier);
        $this->assertNotSame('01012345678', $payment->cash_receipt_identifier);
    }
}
