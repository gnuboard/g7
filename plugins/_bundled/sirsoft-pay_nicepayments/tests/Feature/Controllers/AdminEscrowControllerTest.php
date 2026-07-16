<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class AdminEscrowControllerTest extends PluginTestCase
{
    private const TID = 'NICE_ESCROW_TID_001';

    public function test_register_delivery_requires_bound_escrow_payment_and_restores_credentials(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->createPaidEscrowPayment();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->once())
            ->method('useStoredCredentials')
            ->with(false, 'SRLIVE001');
        $apiServiceMock->expects($this->once())
            ->method('registerEscrowDelivery')
            ->with(
                self::TID,
                'CJ대한통운',
                'TRACK123456',
                '서울시 테스트구 1',
                '운영자',
            )
            ->willReturn([
                'ResultCode' => 'C000',
                'ResultMsg' => '정상처리',
            ]);
        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/escrow/register-delivery', [
            'tid' => self::TID,
            'delivery_name' => 'CJ대한통운',
            'tracking_number' => 'TRACK123456',
            'buyer_address' => '서울시 테스트구 1',
            'register_name' => '운영자',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ResultCode', 'C000');
    }

    public function test_register_delivery_rejects_unbound_tid_before_api_call(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->createPaidEscrowPayment();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->never())->method('useStoredCredentials');
        $apiServiceMock->expects($this->never())->method('registerEscrowDelivery');
        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/escrow/register-delivery', [
            'tid' => 'ATTACKER_SUPPLIED_TID',
            'delivery_name' => 'CJ대한통운',
            'tracking_number' => 'TRACK123456',
            'buyer_address' => '서울시 테스트구 1',
            'register_name' => '운영자',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    private function createPaidEscrowPayment(): void
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_due_amount' => 0,
            'total_paid_amount' => 30000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'is_escrow' => true,
            'paid_amount_local' => 30000,
            'paid_at' => now(),
            'transaction_id' => self::TID,
            'payment_meta' => [
                'mid' => 'SRLIVE001',
                'is_test_mode' => false,
            ],
        ]);
    }
}
