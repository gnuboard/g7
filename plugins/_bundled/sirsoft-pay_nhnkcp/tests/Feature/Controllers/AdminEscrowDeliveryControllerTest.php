<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class AdminEscrowDeliveryControllerTest extends PluginTestCase
{
    private function createEscrowOrder(string $tno = 'KCP_ESCROW_TNO_DELIVERY'): Order
    {
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_due_amount' => 0,
            'total_paid_amount' => 30000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => $tno,
            'paid_amount_local' => 30000,
            'paid_at' => now(),
            'is_escrow' => true,
            'payment_meta' => [
                'site_cd' => 'T0000',
                'is_test_mode' => true,
                'escw_yn' => 'Y',
            ],
        ]);

        return $order;
    }

    private function mockEscrowDeliveryApi(array $response): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('registerEscrowDelivery')->willReturn($response);
        $this->app->instance(NhnKcpApiService::class, $mock);
    }

    public function test_register_stores_sanitized_pg_response_only(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = $this->createEscrowOrder();
        $this->mockEscrowDeliveryApi([
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'tno' => 'KCP_ESCROW_TNO_DELIVERY',
            'deli_numb' => '1234567890',
            'deli_corp' => '04',
            'recv_name' => '수령자',
            'recv_tel' => '01012345678',
            'recv_addr' => '서울시 테스트구',
            'unexpected_sensitive' => 'store-me-not',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{$order->order_number}/escrow-delivery", [
                'deli_numb' => '1234567890',
                'deli_corp' => '04',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $payment = $order->payment;
        $payment->refresh();
        $deliveryMeta = $payment->payment_meta['escrow_delivery'] ?? [];
        $pgResponse = $deliveryMeta['pg_response'] ?? [];

        $this->assertTrue($deliveryMeta['pg_response_sanitized'] ?? false);
        $this->assertSame('0000', $pgResponse['res_cd'] ?? null);
        $this->assertSame('KCP_ESCROW_TNO_DELIVERY', $pgResponse['tno'] ?? null);
        $this->assertArrayNotHasKey('recv_name', $pgResponse);
        $this->assertArrayNotHasKey('recv_tel', $pgResponse);
        $this->assertArrayNotHasKey('recv_addr', $pgResponse);
        $this->assertArrayNotHasKey('unexpected_sensitive', $pgResponse);
    }
}
