<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 간편결제 표시 라벨 응답 테스트
 *
 * 나이스페이 간편결제(네이버페이·카카오페이·PAYCO 등) 주문의 결제수단 이름이 관리자 거래 조회와
 * 사용자 영수증 응답에 「간편결제 이름 (기본 결제수단)」 형태로 실리는지 확인한다. 관리자 주문 목록용
 * 표시 맵 경로는 1.1.0 에서 제거됐다 — 주문 목록은 이커머스 응답의 표시 이름을 쓴다.
 */
class EasyPayDisplayControllerTest extends PluginTestCase
{
    /**
     * 제거한 관리자 주문 목록용 간편결제 표시 맵 경로는 더 이상 응답하지 않는다.
     *
     * @scenario surface=admin_order_list
     *
     * @effects removed_easy_pay_display_map_route_is_not_served
     *
     * @return void 반환값 없음
     */
    public function test_admin_order_list_easy_pay_display_map_route_is_removed(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $this->actingAs($admin)
            ->getJson('/api/plugins/sirsoft-pay_nicepayments/admin/orders/easy-pay-display-map')
            ->assertNotFound();
    }

    /**
     * 관리자 거래 조회 응답은 간편결제 이름과 기본 결제수단을 합친 표시 이름을 싣는다.
     *
     * @scenario surface=admin_transaction_status
     *
     * @effects transaction_status_includes_easy_pay_display_label
     *
     * @return void 반환값 없음
     */
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
            ->with('NICE_TID_'.$order->id)
            ->willReturn([
                'ResultCode' => '3001',
                'ResultMsg' => '정상처리',
                'TID' => 'NICE_TID_'.$order->id,
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
            ($data['_embedded_pg_provider_label'] ?? '').' ('.($data['_base_pay_method_label'] ?? '').')',
            $data['_pay_method_label'] ?? null,
        );
    }

    /**
     * 사용자 영수증 응답은 간편결제 이름과 기본 결제수단을 합친 표시 이름을 싣는다.
     *
     * @scenario surface=user_receipt
     *
     * @effects user_receipt_includes_easy_pay_display_label, response_uses_common_envelope
     *
     * @return void 반환값 없음
     */
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
            ->assertJsonPath('data.selected_payment_method', 'nicepay_naverpay')
            ->assertJsonPath('data.embedded_pg_provider', 'naverpay');

        $data = $response->json('data');
        $this->assertContains($data['embedded_pg_provider_label'] ?? null, ['네이버페이', 'NaverPay']);
        $this->assertSame(
            ($data['embedded_pg_provider_label'] ?? '').' ('.($data['payment_method_label'] ?? '').')',
            $data['payment_method_display_label'] ?? null,
        );
    }

    /**
     * 나이스페이 간편결제로 결제 완료된 주문을 만듭니다.
     *
     * @param  string  $method  간편결제 결제수단 식별자 (예: nicepay_payco)
     * @param  string  $provider  간편결제 제공자 식별자 (예: payco)
     * @param  array{ko: string, en: string}  $label  간편결제 표시 이름 (로케일별)
     * @param  User|null  $user  주문 회원 (없으면 새로 만든다)
     * @return Order 생성한 주문
     */
    private function createPaidEasyPayOrder(
        string $method,
        string $provider,
        array $label,
        ?User $user = null,
    ): Order {
        $order = OrderFactory::new()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'order_number' => 'ORD-NICE-EASY-'.random_int(10000, 99999),
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
            'transaction_id' => 'NICE_TID_'.$order->id,
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
