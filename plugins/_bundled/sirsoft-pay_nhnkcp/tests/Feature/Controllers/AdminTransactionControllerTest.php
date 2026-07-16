<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class AdminTransactionControllerTest extends PluginTestCase
{
    public function test_transaction_status_includes_easy_pay_display_label(): void
    {
        app()->setLocale('ko');

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KCP-ADMIN-' . random_int(10000, 99999),
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
            'transaction_id' => 'KCP_TNO_KAKAOPAY',
            'embedded_pg_provider' => 'kakaopay',
            'paid_amount_local' => 50000,
            'paid_at' => now(),
            'payment_meta' => [
                'nhnkcp_easy_pay_method' => 'nhnkcp_kakaopay',
                'nhnkcp_easy_pay_provider' => 'kakaopay',
                'nhnkcp_easy_pay_label' => [
                    'ko' => '카카오페이',
                    'en' => 'KakaoPay',
                ],
                'pg_raw_response' => [
                    'app_no' => 'APP12345',
                    'use_pay_method' => 'CARD',
                ],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{$order->order_number}/transaction-status");

        $response->assertOk()
            ->assertJsonPath('data._embedded_pg_provider', 'kakaopay');

        $data = $response->json('data');
        $this->assertContains($data['_embedded_pg_provider_label'] ?? null, ['카카오페이', 'KakaoPay']);
        $this->assertSame(
            ($data['_embedded_pg_provider_label'] ?? '') . ' (' . ($data['_base_pay_method_label'] ?? '') . ')',
            $data['_pay_method_label'] ?? null,
        );
    }

    public function test_transaction_status_exposes_current_cancelled_payment_and_refund_state(): void
    {
        app()->setLocale('ko');

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KCP-CANCELLED-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::CANCELLED,
            'total_amount' => 1100,
            'total_due_amount' => 0,
            'total_paid_amount' => 0,
            'total_refunded_amount' => 1100,
            'paid_at' => now()->subMinute(),
            'cancelled_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::CANCELLED,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => '26438048818473',
            'paid_amount_local' => 1100,
            'paid_amount_base' => 1100,
            'cancelled_amount' => 1100,
            'cancelled_at' => now(),
            'payment_meta' => [
                'res_cd' => '0000',
                'app_time' => '20260707122519',
                'pg_raw_response' => [
                    'res_cd' => '0000',
                    'tno' => '26438048818473',
                    'app_no' => '37420127',
                    'app_time' => '20260707122519',
                    'card_name' => '롯데카드',
                ],
            ],
        ]);

        DB::table('ecommerce_order_refunds')->insert([
            'order_id' => $order->id,
            'order_cancel_id' => null,
            'refund_number' => 'RF-KCP-' . random_int(10000, 99999),
            'refund_status' => RefundStatusEnum::COMPLETED->value,
            'refund_method' => 'pg',
            'refund_amount' => 1100,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'pg_transaction_id' => '26438048818473',
            'refunded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{$order->order_number}/transaction-status");

        $response->assertOk()
            ->assertJsonPath('data.res_cd', '0000')
            ->assertJsonPath('data.payment_status', PaymentStatusEnum::CANCELLED->value)
            ->assertJsonPath('data.cancelled_amount', 1100)
            ->assertJsonPath('data.cancelled_amount_formatted', '1,100원')
            ->assertJsonPath('data.refund_status', RefundStatusEnum::COMPLETED->value)
            ->assertJsonPath('data.refund_pg_transaction_id', '26438048818473');

        $data = $response->json('data');
        $this->assertContains($data['payment_status_label'] ?? null, ['결제취소', 'Cancelled']);
        $this->assertContains($data['refund_status_label'] ?? null, ['환불완료', 'Refunded', 'Refund Completed']);
    }
}
