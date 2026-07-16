<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class AdminOrderListControllerTest extends PluginTestCase
{
    public function test_easy_pay_display_map_returns_order_payment_display_labels(): void
    {
        app()->setLocale('ko');

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KCP-LIST-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 50000,
            'total_due_amount' => 0,
            'total_paid_amount' => 50000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => 'KCP_TNO_PAYCO',
            'embedded_pg_provider' => 'payco',
            'paid_amount_local' => 50000,
            'paid_at' => now(),
            'payment_meta' => [
                'nhnkcp_easy_pay_method' => 'nhnkcp_payco',
                'nhnkcp_easy_pay_provider' => 'payco',
                'nhnkcp_easy_pay_label' => [
                    'ko' => 'PAYCO (페이코)',
                    'en' => 'PAYCO',
                ],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/plugins/sirsoft-pay_nhnkcp/admin/orders/easy-pay-display-map');

        $response->assertOk();

        $display = $response->json("data.{$order->order_number}");
        $this->assertContains($display['embedded_pg_provider_label'] ?? null, ['PAYCO (페이코)', 'PAYCO']);
        $this->assertSame(
            ($display['embedded_pg_provider_label'] ?? '') . ' (' . ($display['payment_method_label'] ?? '') . ')',
            $display['payment_method_display_label'] ?? null,
        );
    }
}
