<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class EasyPayDisplayControllerTest extends PluginTestCase
{
    public function test_admin_order_list_easy_pay_display_map_returns_display_labels(): void
    {
        app()->setLocale('ko');

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = $this->createPaidEasyPayOrder('nicepay_payco', 'payco', [
            'ko' => 'PAYCO (페이코)',
            'en' => 'PAYCO',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/plugins/sirsoft-pay_nicepayments/admin/orders/easy-pay-display-map');

        $response->assertOk();

        $display = $response->json("data.{$order->order_number}");
        $this->assertSame('payco', $display['embedded_pg_provider'] ?? null);
        $this->assertContains($display['embedded_pg_provider_label'] ?? null, ['PAYCO (페이코)', 'PAYCO']);
        $this->assertSame(
            ($display['embedded_pg_provider_label'] ?? '') . ' (' . ($display['payment_method_label'] ?? '') . ')',
            $display['payment_method_display_label'] ?? null,
        );
    }

    public function test_admin_transaction_status_includes_easy_pay_display_label(): void
    {
        app()->setLocale('ko');

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = $this->createPaidEasyPayOrder('nicepay_kakaopay', 'kakaopay', [
            'ko' => '카카오페이',
            'en' => 'KakaoPay',
        ]);

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->once())
            ->method('queryTransaction')
            ->with('NICE_TID_' . $order->id)
            ->willReturn([
                'ResultCode' => '3001',
                'ResultMsg' => '정상처리',
                'TID' => 'NICE_TID_' . $order->id,
                'PayMethod' => 'CARD',
            ]);
        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/admin/orders/{$order->order_number}/transaction-status");

        $response->assertOk()
            ->assertJsonPath('data._embedded_pg_provider', 'kakaopay');

        $data = $response->json('data');
        $this->assertContains($data['_embedded_pg_provider_label'] ?? null, ['카카오페이', 'KakaoPay']);
        $this->assertSame(
            ($data['_embedded_pg_provider_label'] ?? '') . ' (' . ($data['_base_pay_method_label'] ?? '') . ')',
            $data['_pay_method_label'] ?? null,
        );
    }

    public function test_user_receipt_response_includes_easy_pay_display_label(): void
    {
        app()->setLocale('ko');

        $user = User::factory()->create();
        $order = $this->createPaidEasyPayOrder('nicepay_naverpay', 'naverpay', [
            'ko' => '네이버페이',
            'en' => 'NaverPay',
        ], $user);

        $response = $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_nicepayments/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('selected_payment_method', 'nicepay_naverpay')
            ->assertJsonPath('embedded_pg_provider', 'naverpay');

        $data = $response->json();
        $this->assertContains($data['embedded_pg_provider_label'] ?? null, ['네이버페이', 'NaverPay']);
        $this->assertSame(
            ($data['embedded_pg_provider_label'] ?? '') . ' (' . ($data['payment_method_label'] ?? '') . ')',
            $data['payment_method_display_label'] ?? null,
        );
    }

    /**
     * @param array{ko: string, en: string} $label
     */
    private function createPaidEasyPayOrder(
        string $method,
        string $provider,
        array $label,
        ?User $user = null,
    ): \Modules\Sirsoft\Ecommerce\Models\Order {
        $order = OrderFactory::new()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'order_number' => 'ORD-NICE-EASY-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 50000,
            'total_amount' => 50000,
            'total_due_amount' => 0,
            'total_paid_amount' => 50000,
            'currency' => 'KRW',
            'currency_snapshot' => self::krwCurrencySnapshot(),
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'transaction_id' => 'NICE_TID_' . $order->id,
            'embedded_pg_provider' => $provider,
            'paid_amount_local' => 50000,
            'paid_at' => now(),
            'payment_meta' => [
                'nicepay_easy_pay_method' => $method,
                'nicepay_easy_pay_provider' => $provider,
                'nicepay_easy_pay_label' => $label,
                'pg_raw_response' => [
                    'ResultCode' => '3001',
                    'PayMethod' => 'CARD',
                ],
            ],
        ]);

        return $order;
    }
}
