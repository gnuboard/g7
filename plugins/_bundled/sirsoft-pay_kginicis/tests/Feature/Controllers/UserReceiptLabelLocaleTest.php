<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 영수증 조회 응답의 표시 글자가 요청 언어를 따르는지 검증한다.
 *
 * 종전에는 버튼 이름·확인서 제목·항목 이름·결제수단 이름이 한국어로 박혀 있어 영어·일본어 화면에서도 한국어로 보였다.
 * 확인서 항목에는 언어와 무관한 고정 키(key)가 붙어, 화면 스크립트가 번역되는 이름 대신 키로 항목을 찾는다.
 */
class UserReceiptLabelLocaleTest extends PluginTestCase
{
    /**
     * 영어로 요청하면 일본 편의점 입금 대기 확인서의 이름·항목·상태가 영어로 오고 항목마다 고정 키가 붙는다.
     *
     * @scenario locale=en, surface=api_cbt_cvs
     *
     * @effects receipt_labels_follow_request_locale, receipt_fields_carry_stable_key
     *
     * @return void 반환값 없음
     */
    public function test_cvs_confirmation_labels_follow_english_request(): void
    {
        [$user, $order] = $this->createCvsWaitingOrder();

        $response = $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'en'])
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('data.receipt_label', 'Deposit details')
            ->assertJsonPath('data.receipt_view_label', 'View convenience store deposit details')
            ->assertJsonPath('data.receipt_title', 'KG Inicis CBT convenience store deposit details')
            ->assertJsonPath('data.payment_method_label', 'Japan convenience store payment')
            ->assertJsonFragment(['key' => 'amount', 'label' => 'Amount due', 'value' => '10 JPY'])
            ->assertJsonFragment(['key' => 'deposit_status', 'label' => 'Deposit status', 'value' => 'Awaiting deposit'])
            ->assertJsonFragment(['key' => 'cvs_code', 'label' => 'Convenience store code', 'value' => '00007'])
            ->assertJsonFragment(['key' => 'cvs_payment_term', 'label' => 'Deposit deadline', 'value' => '2026-05-27 23:59:59']);

        $this->assertStringNotContainsString('입금', $response->getContent());
    }

    /**
     * 한국어로 요청하면 종전과 같은 한국어 이름이 오고, 항목에는 같은 고정 키가 붙는다.
     *
     * @scenario locale=ko, surface=api_cbt_cvs
     *
     * @effects receipt_fields_carry_stable_key
     *
     * @return void 반환값 없음
     */
    public function test_cvs_confirmation_keeps_korean_labels_with_stable_keys(): void
    {
        [$user, $order] = $this->createCvsWaitingOrder();

        $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt_label', '입금정보')
            ->assertJsonFragment(['key' => 'amount', 'label' => '입금예정금액', 'value' => '10 JPY'])
            ->assertJsonFragment(['key' => 'deposit_status', 'label' => '입금 상태', 'value' => '입금대기']);
    }

    /**
     * 영어로 요청하면 일반 결제의 영수증 버튼 이름과 결제수단·간편결제 이름이 영어로 온다
     * — 결제 메타에 결제 시점의 한국어 간편결제 이름이 저장돼 있어도 제공자 코드로 번역한다.
     *
     * @scenario locale=en, surface=api_domestic
     *
     * @effects receipt_labels_follow_request_locale
     *
     * @return void 반환값 없음
     */
    public function test_domestic_receipt_labels_follow_english_request(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RECEIPT-EN-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'StdpayCARDINIpayTest20260928000000000001',
            'embedded_pg_provider' => 'naverpay',
            'paid_amount_local' => 1000,
            'payment_meta' => [
                'selected_payment_method' => 'kginicis_naverpay',
                'embedded_pg_provider' => 'naverpay',
                'embedded_pg_provider_label' => '네이버페이',
            ],
        ]);

        $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'en'])
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt_label', 'Receipt')
            ->assertJsonPath('data.receipt_view_label', 'View Receipt')
            ->assertJsonPath('data.payment_method_label', 'Credit card')
            ->assertJsonPath('data.embedded_pg_provider_label', 'Naver Pay')
            ->assertJsonPath('data.payment_method_display_label', 'Naver Pay (Credit card)');
    }

    /**
     * 회원의 일본 편의점 입금 대기 주문을 만듭니다.
     *
     * @return array{0: User, 1: Order} 회원과 주문
     */
    private function createCvsWaitingOrder(): array
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-CBT-CVS-L-'.random_int(10000, 99999),
            'currency' => 'JPY',
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'total_amount' => 10,
            'total_due_amount' => 10,
            'total_paid_amount' => 0,
            'paid_at' => null,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'INIJPGCVS_CBTTEST00120260928000000000001',
            'paid_amount_local' => 0,
            'currency' => 'JPY',
            'vbank_name' => 'CVS',
            'vbank_number' => '999999999999999999',
            'payment_meta' => [
                'pay_method' => 'CVS',
                'is_cbt' => true,
                'currency' => 'JPY',
                'cvs_amount' => 10,
                'cvs_convenience' => '00007',
                'cvs_conf_no' => '999999999999999999',
                'cvs_receipt_no' => '1779433713966',
                'cvs_payment_term' => '20260527235959',
            ],
        ]);

        return [$user, $order];
    }

    /**
     * 플러그인 설정을 테스트 모드로 고정합니다.
     *
     * @return void 반환값 없음
     */
    private function mockPluginSettings(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn(['is_test_mode' => true]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }
}
